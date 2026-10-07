<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Performance;

use App\Enums\ActivityKind;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Filament\Pages\Reports\LeadReportPage;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Widgets\ActivityCountsWidget;
use App\Filament\Widgets\LeadsByStatusChart;
use App\Filament\Widgets\PipelineByStageChart;
use App\Filament\Widgets\RevenueWonByMonthChart;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\StaleDealsWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Filament\Widgets\TableWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Probe: every dashboard widget (plan section 3.9), the Tasks home «عملي»
 * (My Work, D-27 — it took over the former "my tasks today" widget's list)
 * and a report page are aggregate SQL whose cost does not follow the number
 * of records in reach.
 */
final class DashboardAndReportQueryCountTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const int TOLERANCE = 3;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $team = $this->makeTeam();
        $this->manager = $this->salesManager($team);
        $this->rep = $this->salesRep($team);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function widgets(): array
    {
        return [
            'sales kpis' => [SalesKpisWidget::class],
            'leads by status' => [LeadsByStatusChart::class],
            'pipeline by stage' => [PipelineByStageChart::class],
            'revenue won by month' => [RevenueWonByMonthChart::class],
            'upcoming follow-ups' => [UpcomingFollowUpsWidget::class],
            'stale deals' => [StaleDealsWidget::class],
            'activity counts' => [ActivityCountsWidget::class],
        ];
    }

    /**
     * @param  class-string  $widget
     */
    #[Test]
    #[DataProvider('widgets')]
    public function each_dashboard_widget_issues_no_per_row_queries(string $widget): void
    {
        $this->seedRecords(3);
        $this->renderWidget($widget);

        $small = $this->queriesDuring(fn () => $this->renderWidget($widget));

        $this->seedRecords(12);

        $large = $this->queriesDuring(fn () => $this->renderWidget($widget));

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("%s: %d queries for 3 records of each kind, %d for 15. Most repeated:\n%s", $widget, count($small), count($large), $this->topRepeated($large)),
        );
    }

    /**
     * D-27: My Work lists every bucket of the reader's tasks with the status
     * pill, flag, relative due, checklist meter, assigner's avatar, linked
     * record and the per-row controls (each a policy check) — on a fixed
     * number of queries whatever the number of rows.
     */
    #[Test]
    public function my_work_issues_no_per_row_queries(): void
    {
        $this->seedMyWork(3);
        $this->renderMyWork();

        $small = $this->queriesDuring(fn () => $this->renderMyWork());

        $this->seedMyWork(12);

        $large = $this->queriesDuring(fn () => $this->renderMyWork());

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("My Work: %d queries for 3 tasks per bucket, %d for 15. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    #[Test]
    public function the_lead_report_grouped_by_owner_issues_no_per_group_queries(): void
    {
        $this->seedOwners(2);
        $this->renderReport();

        $small = $this->queriesDuring(fn () => $this->renderReport());

        $this->seedOwners(10);

        $large = $this->queriesDuring(fn () => $this->renderReport());

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("LeadReportPage: %d queries for 2 owners, %d for 12 owners. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    /**
     * @param  class-string  $widget
     */
    private function renderWidget(string $widget): void
    {
        $component = Livewire::actingAs($this->manager)
            ->test($widget, ['pageFilters' => ['from' => '2026-09-01', 'to' => '2026-09-30']]);

        if (is_subclass_of($widget, TableWidget::class)) {
            $component->set('tableRecordsPerPage', 25);
        }

        $component->assertOk();
    }

    private function renderMyWork(): void
    {
        Livewire::actingAs($this->manager)
            ->test(TasksDashboard::class)
            ->assertOk();
    }

    /**
     * Tasks of the manager in every My Work bucket, handed out by an
     * administrator, linked to a lead, each with a checklist; and some
     * completed yesterday.
     */
    private function seedMyWork(int $count): void
    {
        $assigner = $this->admin();

        for ($i = 0; $i < $count; $i++) {
            foreach ([now()->subDays(2), now()->setTime(15, 0), now()->addDays(3), now()->addDays(20), null] as $due) {
                $task = Task::factory()->create([
                    'assignee_id' => $this->manager->getKey(),
                    'assigned_by' => $assigner->getKey(),
                    'lead_id' => Lead::factory()->create(['owner_id' => $this->manager->getKey()])->getKey(),
                    'due_at' => $due,
                ]);

                $this->checklistItemOn($task, 'Step one', now());
                $this->checklistItemOn($task, 'Step two');
            }

            $this->taskAssignedTo($this->manager, TaskStatus::Completed, ['completed_at' => now()->subDay(), 'due_at' => now()->subDay()]);
        }
    }

    private function renderReport(): void
    {
        Livewire::actingAs($this->manager)
            ->test(LeadReportPage::class)
            ->fillForm(['from' => '2026-09-01', 'to' => '2026-09-30', 'group_by' => 'owner'])
            ->call('run')
            ->assertSet('applied.group_by', 'owner')
            ->assertOk();
    }

    /** Leads, open and stale deals, tasks for the manager and the rep, and meetings — all in September. */
    private function seedRecords(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $lead = Lead::factory()->create(['owner_id' => $this->rep->getKey()]);
            $deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
            Deal::query()->whereKey($deal->getKey())->update(['updated_at' => now()->subDays(30), 'last_activity_at' => null]);

            Task::factory()->create([
                'assignee_id' => $this->manager->getKey(),
                'lead_id' => $lead->getKey(),
                'due_at' => now()->setTime(15, 0),
            ]);

            Task::factory()->create([
                'assignee_id' => $this->rep->getKey(),
                'deal_id' => $deal->getKey(),
                'kind' => TaskKind::FollowUp,
                'due_at' => now()->addDays(2),
            ]);

            Activity::factory()->ofKind(ActivityKind::Meeting)->create([
                'owner_id' => $this->rep->getKey(),
                'lead_id' => $lead->getKey(),
                'occurred_at' => now()->subDays(1),
            ]);
        }
    }

    /** One rep per owner group, in the manager's team, each with a lead created this month. */
    private function seedOwners(int $count): void
    {
        $team = $this->manager->team;

        for ($i = 0; $i < $count; $i++) {
            $owner = $this->salesRep($team);
            Lead::factory()->count(2)->create(['owner_id' => $owner->getKey()]);
        }
    }
}
