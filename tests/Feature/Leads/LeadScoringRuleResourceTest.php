<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\ActivityLogEvent;
use App\Enums\LeadScoringRuleKind;
use App\Filament\Resources\LeadScoringRules\LeadScoringRuleResource;
use App\Filament\Resources\LeadScoringRules\Pages\CreateLeadScoringRule;
use App\Filament\Resources\LeadScoringRules\Pages\EditLeadScoringRule;
use App\Filament\Resources\LeadScoringRules\Pages\ListLeadScoringRules;
use App\Jobs\RescoreLeads;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
