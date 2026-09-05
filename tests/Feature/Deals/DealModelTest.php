<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Enums\ActivityLogEvent;
use App\Enums\DealContactRole;
use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Models\ActivityLog;
use App\Models\Competitor;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealCompetitor;
use App\Models\DealContact;
use App\Models\DealProduct;
use App\Models\DealStageLog;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Deals\DealStageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The Deal model's relations, pivots, soft deletion and audit whitelist
 * (decisions D-6, D-8, D-13).
 */
final class DealModelTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
    }

    #[Test]
    public function a_deal_starts_only_in_an_open_stage_of_its_own_pipeline(): void
    {
        $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();
        $wonStage = PipelineStage::query()
            ->where('pipeline_id', $pipeline->getKey())
            ->where('kind', StageKind::Won->value)
            ->firstOrFail();

        try {
            Deal::factory()->create(['stage_id' => $wonStage->getKey()]);
            $this->fail('A deal was created directly in a Won stage.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.initial_stage_must_be_open'), $exception->getMessage());
        }

        $foreign = PipelineStage::factory()->asDefault()->create(['pipeline_id' => Pipeline::factory()->create()->getKey()]);

        try {
            Deal::factory()->create(['pipeline_id' => $pipeline->getKey(), 'stage_id' => $foreign->getKey()]);
            $this->fail('A deal was created in a stage of another pipeline.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.stage_outside_pipeline'), $exception->getMessage());
        }

        $this->assertSame(0, Deal::query()->count());

        // Close columns handed to a create are discarded: the workflow stamps them.
        $deal = Deal::factory()->create(['status' => DealStatus::Won, 'won_at' => now(), 'lost_at' => now(), 'lost_notes' => 'smuggled']);

        $deal->refresh();
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertNull($deal->won_at);
        $this->assertNull($deal->lost_at);
        $this->assertNull($deal->close_reason_id);
        $this->assertNull($deal->lost_notes);
        $this->assertSame(StageKind::Open, $deal->stage?->kind);
        $this->assertFalse((new Deal)->isFillable('status'));
    }

    #[Test]
    public function contacts_are_attached_with_a_cast_role(): void
    {
        $deal = Deal::factory()->create();
        $contact = Contact::factory()->create(['account_id' => $deal->account_id]);
        $other = Contact::factory()->create(['account_id' => $deal->account_id]);

        $deal->contacts()->attach($contact->getKey(), ['role' => DealContactRole::DecisionMaker->value]);
        $deal->contacts()->attach($other->getKey());

        $this->assertSame(2, $deal->contacts()->count());

        $pivot = $deal->contacts()->whereKey($contact->getKey())->firstOrFail()->pivot;
        $this->assertInstanceOf(DealContact::class, $pivot);
        $this->assertSame(DealContactRole::DecisionMaker, $pivot->role);
        $this->assertNotNull($pivot->created_at);

        $second = $deal->contacts()->whereKey($other->getKey())->firstOrFail()->pivot;
        $this->assertInstanceOf(DealContact::class, $second);
        $this->assertNull($second->role);

        $this->assertTrue($contact->deals()->firstOrFail()->is($deal));
        $this->assertSame(DealContactRole::DecisionMaker, $contact->deals()->firstOrFail()->pivot->role);

        $primary = Contact::factory()->create(['account_id' => $deal->account_id]);
        $deal->update(['contact_id' => $primary->getKey()]);
        $this->assertTrue($primary->primaryDeals()->first()?->is($deal));
        $this->assertTrue($deal->refresh()->contact?->is($primary));
    }

    #[Test]
    public function competitors_are_attached_with_cast_pivot_columns(): void
    {
        $deal = Deal::factory()->create();
        $competitor = Competitor::factory()->create();

        $deal->competitors()->attach($competitor->getKey(), ['is_winner' => true, 'notes' => 'Undercut us by 15%']);

        $pivot = $deal->competitors()->firstOrFail()->pivot;
        $this->assertInstanceOf(DealCompetitor::class, $pivot);
        $this->assertTrue($pivot->is_winner);
        $this->assertSame('Undercut us by 15%', $pivot->notes);

        $this->assertTrue($competitor->deals()->firstOrFail()->is($deal));
        $this->assertTrue($competitor->deals()->firstOrFail()->pivot->is_winner);
    }

    #[Test]
    public function a_deal_links_back_to_its_origin_lead_and_the_lead_to_its_deal(): void
    {
        $lead = Lead::factory()->create();
        $deal = Deal::factory()->create(['lead_id' => $lead->getKey()]);

        $this->assertTrue($deal->lead?->is($lead));

        try {
            $lead->forceFill(['converted_deal_id' => $deal->getKey()])->save();
            $this->fail('converted_deal_id was written outside the conversion workflow.');
        } catch (LogicException) {
            $this->assertNull($lead->refresh()->converted_deal_id);
        }

        Lead::withoutWorkflowGuard(fn () => $lead->forceFill(['converted_deal_id' => $deal->getKey()])->save());

        $this->assertTrue($lead->refresh()->convertedDeal?->is($deal));
        $this->assertTrue($deal->account?->deals()->first()?->is($deal));
        $this->assertTrue($deal->pipeline?->deals()->first()?->is($deal));
        $this->assertTrue($deal->stage?->deals()->first()?->is($deal));
    }

    #[Test]
    public function a_soft_deleted_deal_is_hidden_and_restored_with_its_history_and_lines(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $next = PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->whereKeyNot($deal->stage_id)
            ->orderBy('sort')
            ->firstOrFail();
        app(DealStageWorkflow::class)->transition($deal, $next, $rep);
        DealProduct::factory()->create(['deal_id' => $deal->getKey()]);

        $deal->delete();

        $this->assertNull(Deal::query()->find($deal->getKey()));
        $this->assertNotNull(Deal::withTrashed()->find($deal->getKey()));
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealDeleted->value, 'subject_id' => $deal->getKey()]);

        $deal->restore();

        $restored = Deal::query()->findOrFail($deal->getKey());
        $this->assertSame($next->getKey(), $restored->stage_id);
        $this->assertSame(1, $restored->stageLogs()->count());
        $this->assertSame(1, $restored->products()->count());
        $this->assertSame(1, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealRestored->value, 'subject_id' => $deal->getKey()]);
    }

    #[Test]
    public function only_whitelisted_attribute_changes_are_audited(): void
    {
        $deal = Deal::factory()->create(['amount' => 1000]);
        $rows = fn (): int => ActivityLog::query()->where('subject_type', $deal->getMorphClass())->where('subject_id', $deal->getKey())->count();

        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::DealCreated->value, 'subject_id' => $deal->getKey()]);
        $before = $rows();

        $deal->update(['description' => 'Internal remarks only']);
        $this->assertSame($before, $rows());

        $deal->update(['amount' => 1500]);
        $this->assertSame($before + 1, $rows());

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::DealUpdated->value)->latest('id')->firstOrFail();
        $this->assertSame('1500.00', $audit->properties->get('attributes')['amount']);
        $this->assertSame('1000.00', $audit->properties->get('old')['amount']);
        $this->assertSame(ActivityLogEvent::DealUpdated->logName(), $audit->log_name);

        // A line-item change lands on the deal as an amount change.
        DealProduct::factory()->create(['deal_id' => $deal->getKey(), 'unit_price' => 250]);
        $this->assertSame('250.00', $deal->refresh()->amount);
        $this->assertSame($before + 2, $rows());
    }
}
