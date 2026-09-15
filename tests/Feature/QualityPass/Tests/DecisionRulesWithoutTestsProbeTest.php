<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Tests;

use App\Enums\CrmRole;
use App\Enums\LeadScoringRuleKind;
use App\Jobs\RescoreLeads;
use App\Models\Lead;
use App\Models\LeadScoringRule;
use App\Services\Leads\LeadScoringService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Test-suite audit probe: owner decisions whose literal wording no test pins.
 */
final class DecisionRulesWithoutTestsProbeTest extends TestCase
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
    public function d13_grants_the_entity_export_permission_to_every_seeded_role(): void
    {
        // D-13: "{entity}.export granted to every role incl. sales_rep". The drift test pins
        // RolePermissionMatrix, and PermissionMatrixTest asserts support lacks lead.export —
        // the opposite of the decision text — so nothing holds the matrix to D-13.
        $missing = [];

        foreach (CrmRole::cases() as $role) {
            $user = $this->makeUser($role);

            foreach (['lead', 'contact', 'account', 'deal'] as $entity) {
                if (! $user->can("{$entity}.export")) {
                    $missing[] = "{$role->value} lacks {$entity}.export";
                }
            }
        }

        $this->assertSame([], $missing, implode(', ', $missing));
    }

    #[Test]
    public function the_activity_recency_points_are_withdrawn_once_the_window_has_passed(): void
    {
        // D-7 rule-based scoring includes activity recency. A stored score is recomputed only
        // when the lead or a rule is saved, so a lead touched yesterday keeps its recency
        // points forever unless something rescores open leads as time passes.
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-09-06 10:00:00'));

        LeadScoringRule::factory()->create(['kind' => LeadScoringRuleKind::ActivityRecency, 'field' => null, 'within_days' => 7, 'points' => 25]);
        $lead = Lead::factory()->create(['last_activity_at' => now()->subDay()]);

        $this->assertSame(25, $lead->refresh()->score, 'precondition');

        $rescoring = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->description, RescoreLeads::class)
                || str_contains((string) $event->command, 'rescore'));

        $this->assertNotNull($rescoring, 'nothing in routes/console.php rescores open leads as time passes');

        $this->travelTo(Carbon::parse('2026-09-16 10:00:00'));
        (new RescoreLeads)->handle(app(LeadScoringService::class));

        $this->assertSame(0, $lead->refresh()->score);
    }
}
