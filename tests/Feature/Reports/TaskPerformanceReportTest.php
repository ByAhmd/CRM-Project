<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\TaskPerformanceReport;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Task performance (module 23): completed, on-time, late, still-open and
 * overdue tasks per assignee, inside the viewer's scope (D-4, D-13).
 *
 * Fixture (today is 2026-09-15; team Riyadh = manager + rep A; rep B has no team):
 * - rep A: T1 due 10 Sep completed 9 Sep (on time); T2 due 5 Sep completed
 *   12 Sep (late); T3 without a due date completed 12 Sep (on time);
 *   T4 created 2 Sep due 20 Sep, open; T5 created 3 Sep due 10 Sep, open
 *   and overdue; a task cancelled on 4 Sep (neither completed nor open);
 * - rep B: T6 due 3 Sep completed 1 Sep (on time); T7 created in August
 *   due 30 Aug, open and overdue — not "still open" for September.
 */
final class TaskPerformanceReportTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $repA;

    private User $repB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->repA = $this->salesRep($this->team);
        $this->repB = $this->salesRep();
        $tasks = app(TaskService::class);

        $this->travelTo(Carbon::parse('2026-08-20 10:00:00'));
        Task::factory()->create(['assignee_id' => $this->repB->getKey(), 'due_at' => '2026-08-30 10:00:00']);

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $t1 = Task::factory()->create(['assignee_id' => $this->repA->getKey(), 'due_at' => '2026-09-10 10:00:00']);
        $t2 = Task::factory()->create(['assignee_id' => $this->repA->getKey(), 'due_at' => '2026-09-05 10:00:00']);
        $t3 = Task::factory()->create(['assignee_id' => $this->repA->getKey(), 'due_at' => null]);
        $cancelled = Task::factory()->create(['assignee_id' => $this->repA->getKey(), 'due_at' => '2026-09-06 10:00:00']);
        $t6 = Task::factory()->create(['assignee_id' => $this->repB->getKey(), 'due_at' => '2026-09-03 10:00:00']);
        $tasks->complete($t6, $this->repB);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        Task::factory()->create(['assignee_id' => $this->repA->getKey(), 'due_at' => '2026-09-20 10:00:00']);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        Task::factory()->create(['assignee_id' => $this->repA->getKey(), 'due_at' => '2026-09-10 10:00:00']);

        $this->travelTo(Carbon::parse('2026-09-04 09:00:00'));
        $tasks->cancel($cancelled, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-09 09:00:00'));
        $tasks->complete($t1, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-12 09:00:00'));
        $tasks->complete($t2, $this->repA);
        $tasks->complete($t3, $this->repA);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
    }

    #[Test]
    public function an_admin_sees_one_line_per_assignee_with_exact_figures_and_totals(): void
    {
        $rows = $this->report()->rows($this->admin, $this->filters());

        $first = $rows->first();

        $this->assertNotNull($first);
        $this->assertSame([$this->repA->name, $this->repB->name], $rows->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(['completed' => 3, 'on_time' => 2, 'late' => 1, 'still_open' => 2, 'overdue_now' => 1, 'completion_rate' => 60.0], $first->values);
        $this->assertSame(['completed' => 1, 'on_time' => 1, 'late' => 0, 'still_open' => 0, 'overdue_now' => 1, 'completion_rate' => 100.0], $rows->last()?->values);
        $this->assertSame(['assignee_id' => $this->repA->getKey()], $first->meta);
        $this->assertSame(['completed' => 4, 'on_time' => 3, 'late' => 1, 'still_open' => 2, 'overdue_now' => 2, 'completion_rate' => 66.7], $this->report()->totals($rows)->values);
    }

    #[Test]
    public function a_manager_sees_the_team_and_reps_see_only_their_own_tasks(): void
    {
        $manager = $this->report()->rows($this->manager, $this->filters());
        $repA = $this->report()->rows($this->repA, $this->filters());
        $repB = $this->report()->rows($this->repB, $this->filters());

        $this->assertSame([$this->repA->name], $manager->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(3, (int) $manager->first()?->value('completed'));
        $this->assertSame($manager->first()?->values, $repA->first()?->values);
        $this->assertSame([$this->repB->name], $repB->map(fn (ReportRow $row): string => $row->label)->all());
        $this->assertSame(1, (int) $repB->first()?->value('overdue_now'));
    }

    #[Test]
    public function the_period_follows_the_completion_and_creation_dates_while_overdue_is_now(): void
    {
        $august = $this->report()->rows($this->admin, $this->filters(['from' => '2026-08-01', 'to' => '2026-08-31']));

        $this->assertSame(['completed' => 0, 'on_time' => 0, 'late' => 0, 'still_open' => 0, 'overdue_now' => 1, 'completion_rate' => 0.0], $august->first()?->values);
        $this->assertSame(['completed' => 0, 'on_time' => 0, 'late' => 0, 'still_open' => 1, 'overdue_now' => 1, 'completion_rate' => 0.0], $august->last()?->values);

        $firstWeek = $this->report()->rows($this->admin, $this->filters(['from' => '2026-09-01', 'to' => '2026-09-10']));

        $this->assertSame(['completed' => 1, 'on_time' => 1, 'late' => 0, 'still_open' => 2, 'overdue_now' => 1, 'completion_rate' => 33.3], $firstWeek->first()?->values);
    }

    #[Test]
    public function the_owner_filter_narrows_and_never_widens(): void
    {
        $adminOnRepB = $this->report()->rows($this->admin, $this->filters(['owner_id' => $this->repB->getKey()]));

        $this->assertSame([$this->repB->name], $adminOnRepB->map(fn (ReportRow $row): string => $row->label)->all());

        $managerOnRepB = $this->filters(['owner_id' => $this->repB->getKey()], $this->manager);

        $this->assertNull($managerOnRepB->ownerId);
        $this->assertSame([$this->repA->name], $this->report()->rows($this->manager, $managerOnRepB)->map(fn (ReportRow $row): string => $row->label)->all());

        $forged = new ReportFilters($managerOnRepB->from, $managerOnRepB->to, ownerId: $this->repB->getKey());
        $this->assertTrue($this->report()->rows($this->manager, $forged)->isEmpty());
    }

    #[Test]
    public function the_chart_plots_completed_on_time_and_late_per_assignee(): void
    {
        $chart = $this->report()->chart($this->admin, $this->filters());

        $this->assertSame([$this->repA->name, $this->repB->name], $chart['labels']);
        $this->assertSame([__('reports.chart.completed'), __('reports.chart.on_time'), __('reports.chart.late')], array_column($chart['datasets'], 'label'));
        $this->assertSame([3, 1], $chart['datasets'][0]['data']);
        $this->assertSame([2, 1], $chart['datasets'][1]['data']);
        $this->assertSame([1, 0], $chart['datasets'][2]['data']);
        $this->assertSame(array_keys($this->report()->columns()), array_keys($this->report()->formats()));
        $this->assertSame(ReportRow::FORMAT_PERCENT, $this->report()->formats()['completion_rate']);
    }

    private function report(): TaskPerformanceReport
    {
        return app(TaskPerformanceReport::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function filters(array $data = [], ?User $viewer = null): ReportFilters
    {
        return ReportFilters::resolve(
            $data + ['from' => '2026-09-01', 'to' => '2026-09-30'],
            $viewer ?? $this->admin,
            app(RecordVisibilityResolver::class),
            Task::permissionGroup(),
            'Asia/Riyadh',
        );
    }
}
