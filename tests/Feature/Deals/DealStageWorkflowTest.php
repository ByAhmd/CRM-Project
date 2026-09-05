<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Enums\ActivityLogEvent;
use App\Enums\DealStatus;
use App\Enums\StageKind;
use App\Exceptions\Deals\InvalidDealTransitionException;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\DealStageLog;
use App\Models\PipelineStage;
use App\Services\Deals\DealStageWorkflow;
use App\Services\Settings\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The deal stage workflow (decision D-8): every change is logged with the
 * time spent in the previous stage, stages stay inside the pipeline, the
 * stage columns are guarded and the history is append-only.
 */
final class DealStageWorkflowTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function an_open_move_writes_a_stage_log_with_its_duration_and_an_audit_event(): void
    {
        $rep = $this->salesRep();

        Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00'));
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $qualification = $deal->stage;
        $proposal = $this->openStage($deal, 1);

        Carbon::setTestNow(Carbon::parse('2026-09-06 10:30:00'));
        app(DealStageWorkflow::class)->transition($deal, $proposal, $rep, 'Proposal sent');

        $this->assertSame($proposal->getKey(), $deal->stage_id);
        $this->assertSame($proposal->getKey(), $deal->refresh()->stage_id);
        $this->assertSame(DealStatus::Open, $deal->status);

        $log = DealStageLog::query()->where('deal_id', $deal->getKey())->firstOrFail();
        $this->assertSame($qualification?->getKey(), (int) $log->from_stage_id);
        $this->assertSame($proposal->getKey(), (int) $log->to_stage_id);
        $this->assertSame($rep->getKey(), (int) $log->changed_by);
        $this->assertSame('Proposal sent', $log->notes);
        $this->assertSame(1800, (int) $log->duration_seconds);
        $this->assertTrue($log->changed_at->equalTo(Carbon::parse('2026-09-06 10:30:00')));

        $audit = ActivityLog::query()->where('description', ActivityLogEvent::DealStageChanged->value)->latest('id')->firstOrFail();
        $this->assertSame($deal->getKey(), (int) $audit->subject_id);
        $this->assertSame($rep->getKey(), (int) $audit->causer_id);
        $this->assertSame($proposal->display_name, $audit->properties->get('to_stage'));
        $this->assertSame($qualification?->display_name, $audit->properties->get('from_stage'));
        $this->assertSame($deal->title, $audit->properties->get('subject_label'));

        Carbon::setTestNow(Carbon::parse('2026-09-06 10:40:00'));
        app(DealStageWorkflow::class)->transition($deal, $this->openStage($deal, 2), $rep);

        $second = DealStageLog::query()->where('deal_id', $deal->getKey())->orderByDesc('id')->firstOrFail();
        $this->assertSame(600, (int) $second->duration_seconds);
        $this->assertNull($second->notes);
    }

    #[Test]
    public function a_stage_from_another_pipeline_is_refused(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $other = app(PipelineService::class)->create(['name_ar' => 'الشراكات', 'name_en' => 'Partnerships', 'is_active' => true]);
        $foreign = $other->stages()->where('kind', StageKind::Open->value)->firstOrFail();

        try {
            app(DealStageWorkflow::class)->transition($deal, $foreign, $rep);
            $this->fail('A stage of another pipeline was accepted.');
        } catch (InvalidDealTransitionException $exception) {
            $this->assertSame(__('deals.validation.stage_outside_pipeline'), $exception->getMessage());
        }

        $this->assertSame($deal->stage_id, $deal->refresh()->stage_id);
        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
    }

    #[Test]
    public function moving_to_the_current_stage_is_a_no_op(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        $current = $deal->stage;
        $this->assertNotNull($current);

        app(DealStageWorkflow::class)->transition($deal, $current, $rep, 'nothing');

        $this->assertSame(0, DealStageLog::query()->where('deal_id', $deal->getKey())->count());
        $this->assertSame(0, ActivityLog::query()->where('description', ActivityLogEvent::DealStageChanged->value)->count());
    }

    #[Test]
    public function the_stage_columns_cannot_be_written_outside_the_workflow(): void
    {
        $deal = Deal::factory()->create();
        $proposal = $this->openStage($deal, 1);

        try {
            $deal->forceFill(['stage_id' => $proposal->getKey()])->save();
            $this->fail('stage_id was written outside the workflow.');
        } catch (LogicException) {
            $this->assertNotSame($proposal->getKey(), $deal->refresh()->stage_id);
        }

        $this->expectException(LogicException::class);
        $deal->forceFill(['status' => DealStatus::Won, 'won_at' => now()])->save();
    }

    #[Test]
    public function stage_log_rows_are_append_only(): void
    {
        $rep = $this->salesRep();
        $deal = Deal::factory()->create(['owner_id' => $rep->getKey()]);
        app(DealStageWorkflow::class)->transition($deal, $this->openStage($deal, 1), $rep);
        $log = DealStageLog::query()->where('deal_id', $deal->getKey())->firstOrFail();

        try {
            $log->forceFill(['notes' => 'rewritten'])->save();
            $this->fail('A stage log row was updated.');
        } catch (LogicException) {
            $this->assertNull($log->refresh()->notes);
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    #[Test]
    public function allowed_stages_exclude_the_current_and_the_closed_stages(): void
    {
        $deal = Deal::factory()->create();
        $workflow = app(DealStageWorkflow::class);

        $allowed = $workflow->allowedStages($deal)->get();

        $this->assertCount(2, $allowed);
        $this->assertNotContains($deal->stage_id, $allowed->modelKeys());
        $this->assertSame([StageKind::Open, StageKind::Open], $allowed->map(fn (PipelineStage $stage): StageKind => $stage->kind)->all());
        $this->assertSame(
            [$this->openStage($deal, 1)->getKey(), $this->openStage($deal, 2)->getKey()],
            $allowed->modelKeys(),
        );

        $closed = $workflow->closedStages($deal)->get();
        $this->assertSame([StageKind::Won, StageKind::Lost], $closed->map(fn (PipelineStage $stage): StageKind => $stage->kind)->all());

        $this->assertTrue($workflow->canTransition($deal));
    }

    /** The n-th Open stage of the deal's pipeline, in pipeline order (0 = the default stage). */
    private function openStage(Deal $deal, int $position): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', StageKind::Open->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }
}
