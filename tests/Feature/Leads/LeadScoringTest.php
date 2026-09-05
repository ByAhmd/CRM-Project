<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\LeadScoringRuleKind;
use App\Enums\LeadStatusKind;
use App\Jobs\RescoreLeads;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Services\Leads\LeadScoringService;
use App\Services\Leads\LeadStatusWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Rule-based lead scoring with manual override (decision D-7).
 */
final class LeadScoringTest extends TestCase
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
    public function the_score_is_the_clamped_sum_of_matching_rules(): void
    {
        Queue::fake();

        $referral = LeadSource::query()->where('name_en', 'Referral')->firstOrFail();
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::Source, 'reference_id' => $referral->getKey(), 'field' => null, 'points' => 40]);
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::FieldFilled, 'field' => 'email', 'points' => 30]);
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::FieldFilled, 'field' => 'website', 'points' => 50]);
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::FieldFilled, 'field' => 'job_title', 'points' => -20, 'is_active' => false]);
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::ActivityRecency, 'field' => null, 'within_days' => 7, 'points' => 25]);

        $lead = Lead::factory()->create([
            'lead_source_id' => $referral->getKey(),
            'email' => 'lead@example.com',
            'website' => null,
            'last_activity_at' => now()->subDays(30),
        ]);

        $this->assertSame(70, $lead->score);
        $this->assertSame(70, $lead->effective_score);

        $lead->forceFill(['website' => 'https://example.com', 'last_activity_at' => now()->subDay()])->save();

        $this->assertSame(100, $lead->refresh()->score);
    }

    #[Test]
    public function a_manual_override_wins_but_the_computed_score_is_kept(): void
    {
        Queue::fake();
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::FieldFilled, 'field' => 'email', 'points' => 35]);

        $lead = Lead::factory()->create(['email' => 'lead@example.com', 'score_override' => 90]);

        $this->assertSame(35, $lead->score);
        $this->assertSame(90, $lead->effective_score);

        $lead->forceFill(['score_override' => null])->save();

        $this->assertSame(35, $lead->refresh()->effective_score);
    }

    #[Test]
    public function a_status_rule_applies_after_the_workflow_moves_the_lead(): void
    {
        Queue::fake();
        $rep = $this->salesRep();
        $qualified = LeadStatus::query()->where('kind', LeadStatusKind::Qualified->value)->firstOrFail();
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::Status, 'reference_id' => $qualified->getKey(), 'field' => null, 'points' => 60]);

        $lead = Lead::factory()->create(['owner_id' => $rep->getKey(), 'email' => null]);
        $this->assertSame(0, $lead->score);

        app(LeadStatusWorkflow::class)->transition($lead, $qualified, $rep, 'Qualified after demo.');

        $this->assertSame(60, $lead->refresh()->score);
    }

    #[Test]
    public function saving_a_rule_queues_one_rescore_and_the_job_updates_open_leads_only(): void
    {
        Queue::fake();
        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::FieldFilled, 'field' => 'email', 'points' => 20]);
        Queue::assertPushed(RescoreLeads::class, 1);

        $open = Lead::factory()->create(['email' => 'open@example.com']);
        $converted = Lead::factory()->create(['email' => 'done@example.com']);
        Lead::withoutWorkflowGuard(fn () => $converted->forceFill([
            'lead_status_id' => LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->value('id'),
            'converted_at' => now(),
        ])->save());

        $this->assertSame(20, $open->score);
        $this->assertSame(20, $converted->score);

        LeadScoringRule::query()->update(['points' => 45]);
        app(LeadScoringService::class)->refresh();

        (new RescoreLeads)->handle(app(LeadScoringService::class));

        $this->assertSame(45, $open->refresh()->score);
        $this->assertSame(20, $converted->refresh()->score);
    }
}
