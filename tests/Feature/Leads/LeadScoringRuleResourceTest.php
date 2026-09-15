<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\ActivityLogEvent;
use App\Enums\LeadScoringRuleKind;
use App\Enums\LeadStatusKind;
use App\Filament\Resources\LeadScoringRules\LeadScoringRuleResource;
use App\Filament\Resources\LeadScoringRules\Pages\CreateLeadScoringRule;
use App\Filament\Resources\LeadScoringRules\Pages\EditLeadScoringRule;
use App\Filament\Resources\LeadScoringRules\Pages\ListLeadScoringRules;
use App\Jobs\RescoreLeads;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class LeadScoringRuleResourceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Queue::fake();
    }

    #[Test]
    public function only_settings_managers_reach_the_resource(): void
    {
        $rule = LeadScoringRule::factory()->create();

        $this->actingAs($this->admin())->get(LeadScoringRuleResource::getUrl('index'))->assertOk();
        $this->actingAs($this->admin())->get(LeadScoringRuleResource::getUrl('edit', ['record' => $rule]))->assertOk();
        $this->actingAs($this->salesManager())->get(LeadScoringRuleResource::getUrl('index'))->assertForbidden();
        $this->actingAs($this->salesRep())->get(LeadScoringRuleResource::getUrl('create'))->assertForbidden();

        Livewire::actingAs($this->admin())->test(ListLeadScoringRules::class)->assertCanSeeTableRecords([$rule]);
    }

    #[Test]
    public function a_source_rule_needs_a_reference_and_is_audited_and_queues_a_rescore(): void
    {
        $admin = $this->admin();
        $source = LeadSource::query()->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateLeadScoringRule::class)
            ->fillForm(['kind' => LeadScoringRuleKind::Source->value, 'reference_id' => null, 'points' => 15])
            ->call('create')
            ->assertHasFormErrors(['reference_id' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateLeadScoringRule::class)
            ->fillForm(['kind' => LeadScoringRuleKind::Source->value, 'reference_id' => $source->getKey(), 'points' => 15])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = LeadScoringRule::query()->where('kind', LeadScoringRuleKind::Source->value)->firstOrFail();

        $this->assertSame($source->getKey(), (int) $rule->reference_id);
        $this->assertNull($rule->field);
        $this->assertSame($source->display_name, $rule->targetLabel());
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::LookupCreated->value, 'subject_id' => $rule->getKey()]);
        Queue::assertPushed(RescoreLeads::class);
    }

    #[Test]
    public function a_recency_rule_needs_a_day_count_within_range(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateLeadScoringRule::class)
            ->fillForm(['kind' => LeadScoringRuleKind::ActivityRecency->value, 'within_days' => 0, 'points' => 500])
            ->call('create')
            ->assertHasFormErrors(['within_days', 'points']);
    }

    #[Test]
    public function a_field_rule_is_edited_and_deleted(): void
    {
        $admin = $this->admin();
        $rule = LeadScoringRule::factory()->create(['field' => 'email', 'points' => 10]);

        Livewire::actingAs($admin)
            ->test(EditLeadScoringRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm(['field' => 'website', 'points' => -5])
            ->call('save')
            ->assertHasNoFormErrors();

        $rule->refresh();
        $this->assertSame('website', $rule->field);
        $this->assertSame(-5, (int) $rule->points);
        $this->assertSame(__('leads.fields.website'), $rule->targetLabel());

        Livewire::actingAs($admin)
            ->test(EditLeadScoringRule::class, ['record' => $rule->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('lead_scoring_rules', ['id' => $rule->getKey()]);
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::LookupDeleted->value, 'subject_id' => $rule->getKey()]);
    }

    #[Test]
    public function the_form_refuses_a_second_rule_with_the_same_kind_and_target(): void
    {
        $admin = $this->admin();
        $website = LeadSource::factory()->create(['name_en' => 'Trade fair', 'name_ar' => 'معرض تجاري']);
        $referral = LeadSource::factory()->create(['name_en' => 'Partner referral', 'name_ar' => 'إحالة شريك']);
        $duplicate = __('lead_scoring_rules.validation.duplicate');

        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::Source, 'reference_id' => $website->getKey(), 'field' => null, 'points' => 15]);
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::FieldFilled, 'field' => 'email', 'points' => 5]);
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::ActivityRecency, 'field' => null, 'within_days' => 7, 'points' => 10]);

        foreach ([
            'reference_id' => ['kind' => LeadScoringRuleKind::Source->value, 'reference_id' => $website->getKey(), 'points' => 20],
            'field' => ['kind' => LeadScoringRuleKind::FieldFilled->value, 'field' => 'email', 'points' => 20],
            'within_days' => ['kind' => LeadScoringRuleKind::ActivityRecency->value, 'within_days' => 7, 'points' => 20],
        ] as $target => $data) {
            Livewire::actingAs($admin)
                ->test(CreateLeadScoringRule::class)
                ->fillForm($data)
                ->call('create')
                ->assertHasFormErrors([$target])
                ->assertSee($duplicate);
        }

        $this->assertSame(3, LeadScoringRule::query()->count());

        Livewire::actingAs($admin)
            ->test(CreateLeadScoringRule::class)
            ->fillForm(['kind' => LeadScoringRuleKind::Source->value, 'reference_id' => $referral->getKey(), 'points' => 20])
            ->call('create')
            ->assertHasNoFormErrors();

        $second = LeadScoringRule::query()->where('reference_id', $referral->getKey())->firstOrFail();

        Livewire::actingAs($admin)
            ->test(EditLeadScoringRule::class, ['record' => $second->getRouteKey()])
            ->fillForm(['reference_id' => $website->getKey()])
            ->call('save')
            ->assertHasFormErrors(['reference_id']);

        Livewire::actingAs($admin)
            ->test(EditLeadScoringRule::class, ['record' => $second->getRouteKey()])
            ->fillForm(['points' => 25])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(25, (int) $second->refresh()->points);
        $this->assertSame($referral->getKey(), (int) $second->reference_id);
    }

    #[Test]
    public function the_database_refuses_a_duplicate_rule_and_a_rule_for_a_missing_source_or_status(): void
    {
        $website = LeadSource::factory()->create(['name_en' => 'Trade fair', 'name_ar' => 'معرض تجاري']);
        $row = [
            'kind' => LeadScoringRuleKind::Source->value,
            'reference_id' => $website->getKey(),
            'field' => null,
            'within_days' => null,
            'points' => 10,
            'is_active' => true,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('lead_scoring_rules')->insert($row);

        foreach ([
            'duplicate' => $row,
            'missing source' => ['reference_id' => 999999] + $row,
            'missing status' => ['kind' => LeadScoringRuleKind::Status->value, 'reference_id' => 999999] + $row,
        ] as $case => $attempt) {
            try {
                DB::transaction(static fn (): bool => DB::table('lead_scoring_rules')->insert($attempt));
                $this->fail("The database accepted a rule: {$case}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, LeadScoringRule::query()->count());
    }

    #[Test]
    public function a_source_or_status_a_rule_uses_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $source = LeadSource::factory()->create(['name_en' => 'Trade fair', 'name_ar' => 'معرض تجاري']);
        $status = LeadStatus::query()->where('kind', LeadStatusKind::Unqualified->value)->firstOrFail();

        $this->assertTrue($admin->can('delete', $source));
        $this->assertTrue($admin->can('delete', $status));

        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::Source, 'reference_id' => $source->getKey(), 'field' => null]);
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::Status, 'reference_id' => $status->getKey(), 'field' => null]);

        $this->assertFalse($admin->can('delete', $source));
        $this->assertFalse($admin->can('delete', $status));

        foreach ([$source, $status] as $referenced) {
            try {
                DB::transaction(static fn (): ?bool => $referenced->delete());
                $this->fail('A lookup a scoring rule references was deleted.');
            } catch (QueryException) {
                $this->assertNotNull($referenced->fresh());
            }
        }
    }
}
