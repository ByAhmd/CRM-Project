<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CrmRole;
use App\Enums\TaskStatus;
use App\Filament\Pages\Reports\EmployeeDashboardPage;
use App\Filament\Pages\Reports\TaskPerformanceReportPage;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RecordAssignedNotification;
use App\Services\Access\RecordAssignmentService;
use App\Services\Settings\SettingsRepository;
use App\Services\Statistics\Reports\EmployeeDashboard;
use App\Services\Statistics\Reports\EmployeeDashboardTask;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\TaskPerformanceReport;
use App\Services\Tasks\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Shared tasks in the reports (decision D-28). The task performance report
 * (module 23, D-21) counts assignments: a task held by several employees is
 * on the line of each of them — the first assignee and every co-assignee —
 * with each one's time to finish measured from when the task reached them,
 * and the totals add the lines up (per-assignee accountability). A line is
 * shown only when the viewer may see the task and reaches its assignee
 * (D-4): a manager never gets a line for an outsider who shares a member's
 * task, an own-level viewer only their own line. The employee dashboard
 * (D-26) reads a shared task as each holder's own, exactly as their line on
 * the report does.
 *
 * Fixture: team Riyadh = a sales manager and the employees Amal and Badr;
 * Omar is an employee outside every team. The administrator hands the tasks
 * out through TaskService, at the travelled moments of each test.
 */
final class SharedTaskReportsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $manager;

    private User $amal;

    private User $badr;

    private User $omar;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        Notification::fake();

        $this->team = $this->makeTeam();
        $this->admin = $this->admin();
        $this->manager = $this->salesManager($this->team);
        $this->amal = $this->makeUser(CrmRole::Employee, ['name' => 'Amal Team'], $this->team);
        $this->badr = $this->makeUser(CrmRole::Employee, ['name' => 'Badr Team'], $this->team);
        $this->omar = $this->makeUser(CrmRole::Employee, ['name' => 'Omar Outsider']);
        $this->tasks = app(TaskService::class);
    }

    // --- Counting: one line per assignee ---

    #[Test]
    public function a_shared_task_counts_on_the_line_of_each_assignee_and_the_totals_add_the_lines(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $shared = $this->handOut('Count the stock', [$this->amal, $this->badr, $this->omar], ['due_at' => '2026-09-05 10:00:00']);
        $this->handOut('Amal alone', [$this->amal], ['due_at' => '2026-09-30 10:00:00']);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->complete($shared, $this->badr);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));

        $this->assertSame([$this->amal->name, $this->badr->name, $this->omar->name], $this->labels($rows));
        $this->assertSame(
            ['completed' => 1, 'on_time' => 1, 'late' => 0, 'still_open' => 1, 'overdue_now' => 0, 'completion_rate' => 50.0, 'avg_time_to_finish' => 86400, 'finish_seconds' => 86400],
            $this->row($rows, $this->amal)->values,
        );

        foreach ([$this->badr, $this->omar] as $assignee) {
            $this->assertSame(
                ['completed' => 1, 'on_time' => 1, 'late' => 0, 'still_open' => 0, 'overdue_now' => 0, 'completion_rate' => 100.0, 'avg_time_to_finish' => 86400, 'finish_seconds' => 86400],
                $this->row($rows, $assignee)->values,
                "{$assignee->name} answers for the shared task too",
            );
        }

        $this->assertSame(2, Task::query()->count());
        $this->assertSame(
            ['completed' => 3, 'on_time' => 3, 'late' => 0, 'still_open' => 1, 'overdue_now' => 0, 'completion_rate' => 75.0, 'avg_time_to_finish' => 86400, 'finish_seconds' => 259200],
            app(TaskPerformanceReport::class)->totals($rows)->values,
            'one completion per assignee — per-assignee accountability, not a count of distinct tasks',
        );
        $this->assertSame([1, 1, 1], app(TaskPerformanceReport::class)->chartFromRows($rows)['datasets'][0]['data']);
    }

    #[Test]
    public function each_assignee_is_measured_from_when_the_task_reached_them_and_never_below_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $task = $this->handOut('Inventory', [$this->amal]);

        // Badr joins two days later.
        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $this->tasks->update($task, ['assignee_ids' => [$this->amal->getKey(), $this->badr->getKey()]], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-04 15:00:00'));
        $this->tasks->complete($task, $this->amal);

        // Omar is recorded as joining a day after the completion.
        $this->travelTo(Carbon::parse('2026-09-05 15:00:00'));
        $this->shareTask($task->refresh(), $this->omar);

        $this->assertTrue(TaskCoAssignee::query()->where('task_id', $task->getKey())->where('user_id', $this->badr->getKey())->sole()->assigned_at->equalTo(Carbon::parse('2026-09-03 09:00:00')));

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));

        $this->assertSame(3 * 86400 + 6 * 3600, $this->row($rows, $this->amal)->value('avg_time_to_finish'), 'Amal since 1 Sep 09:00');
        $this->assertSame(86400 + 6 * 3600, $this->row($rows, $this->badr)->value('avg_time_to_finish'), 'Badr since he joined on 3 Sep, not since the task was created');
        $this->assertSame(1, $this->row($rows, $this->omar)->value('completed'));
        $this->assertSame(0, $this->row($rows, $this->omar)->value('finish_seconds'), 'a completion before the task reached Omar counts zero, never minus a day');

        $totals = app(TaskPerformanceReport::class)->totals($rows);

        $this->assertSame(3, $totals->value('completed'));
        $this->assertSame(280800 + 108000, $totals->value('finish_seconds'));
        $this->assertSame(129600, $totals->value('avg_time_to_finish'), 'the mean over every completed assignment');
    }

    #[Test]
    public function moving_up_or_down_between_first_and_co_assignee_never_restarts_anyones_time_to_finish(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $swapped = $this->handOut('Swapped', [$this->amal]);
        $movedUp = $this->handOut('Moved up', [$this->amal]);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->update($movedUp, ['assignee_ids' => [$this->amal->getKey(), $this->badr->getKey()]], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $this->tasks->update($swapped, ['assignee_ids' => [$this->amal->getKey(), $this->badr->getKey()]], $this->admin);

        // Badr first on one, Amal beside him; Amal removed from the other through the assign action.
        $this->travelTo(Carbon::parse('2026-09-04 09:00:00'));
        $this->tasks->update($swapped, ['assignee_ids' => [$this->badr->getKey(), $this->amal->getKey()]], $this->admin);
        app(RecordAssignmentService::class)->assign($movedUp, null, $this->admin);

        $this->travelTo(Carbon::parse('2026-09-05 15:00:00'));
        $this->tasks->complete($swapped, $this->badr);
        $this->tasks->complete($movedUp, $this->badr);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));

        $this->assertSame([$this->amal->name, $this->badr->name], $this->labels($rows));
        $this->assertSame(1, $this->row($rows, $this->amal)->value('completed'), 'Amal left the task she was removed from');
        $this->assertSame(4 * 86400 + 6 * 3600, $this->row($rows, $this->amal)->value('finish_seconds'), 'Amal moved down and is still measured from 1 Sep');
        $this->assertSame(2, $this->row($rows, $this->badr)->value('completed'));
        $this->assertSame(
            (2 * 86400 + 6 * 3600) + (3 * 86400 + 6 * 3600),
            $this->row($rows, $this->badr)->value('finish_seconds'),
            'Badr moved up and is measured from when each task reached him (3 Sep, 2 Sep), not from the move',
        );

        // Nobody was told about a task they already held.
        Notification::assertSentToTimes($this->amal, RecordAssignedNotification::class, 2);
        Notification::assertSentToTimes($this->badr, RecordAssignedNotification::class, 2);
    }

    // --- Scope (D-4): the task in reach AND its assignee in reach ---

    #[Test]
    public function a_manager_gets_the_line_of_a_member_on_a_task_shared_with_an_outsider_and_never_the_outsider_line(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $shared = $this->handOut('Visit the client', [$this->omar, $this->amal], ['due_at' => '2026-09-10 10:00:00']);
        $outsiderOnly = $this->handOut('Outsider only', [$this->omar]);
        $this->tasks->create(['title' => 'Nobody yet', 'assignee_id' => null], $this->admin);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->complete($shared, $this->omar);
        $this->tasks->complete($outsiderOnly, $this->omar);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $manager = app(TaskPerformanceReport::class)->rows($this->manager, $this->taskReportFilters($this->manager));

        $this->assertSame([$this->amal->name, __('reports.labels.unassigned')], $this->labels($manager), 'the member\'s line and the unassigned one, no line for the outsider');
        $this->assertSame(1, $this->row($manager, $this->amal)->value('completed'));
        $this->assertSame(86400, $this->row($manager, $this->amal)->value('avg_time_to_finish'));

        $totals = app(TaskPerformanceReport::class)->totals($manager);

        $this->assertSame(1, $totals->value('completed'), 'the outsider\'s two completions stay out of the manager\'s totals');
        $this->assertSame(1, $totals->value('still_open'));

        $admin = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));

        $this->assertSame([$this->amal->name, $this->omar->name, __('reports.labels.unassigned')], $this->labels($admin));
        $this->assertSame(2, $this->row($admin, $this->omar)->value('completed'));
        $this->assertSame(3, app(TaskPerformanceReport::class)->totals($admin)->value('completed'));
    }

    #[Test]
    public function at_own_level_each_assignee_sees_only_their_own_line_on_a_shared_task(): void
    {
        $rana = $this->makeUser(CrmRole::SalesRep, ['name' => 'Rana Rep'], $this->team);
        $sami = $this->makeUser(CrmRole::SalesRep, ['name' => 'Sami Rep']);

        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $shared = $this->handOut('Prepare the samples', [$sami, $rana, $this->amal]);
        $this->handOut('Sami alone', [$sami]);

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $this->tasks->complete($shared, $rana);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        foreach ([[$rana, 0], [$sami, 1], [$this->amal, 0]] as [$viewer, $stillOpen]) {
            $rows = app(TaskPerformanceReport::class)->rows($viewer, $this->taskReportFilters($viewer));

            $this->assertSame([$viewer->name], $this->labels($rows), "{$viewer->name} sees their own line alone");
            $this->assertSame(1, $this->row($rows, $viewer)->value('completed'));
            $this->assertSame($stillOpen, $this->row($rows, $viewer)->value('still_open'));
            $this->assertSame(2 * 86400, $this->row($rows, $viewer)->value('avg_time_to_finish'));
            $this->assertSame(1, app(TaskPerformanceReport::class)->totals($rows)->value('completed'));
        }
    }

    // --- Filters: the assignee of each assignment ---

    #[Test]
    public function the_owner_and_team_filters_narrow_the_assignee_of_each_assignment_and_never_widen(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $shared = $this->handOut('Filed under the outsider', [$this->omar, $this->amal]);
        $own = $this->handOut('Badr alone', [$this->badr]);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->complete($shared, $this->amal);
        $this->tasks->complete($own, $this->badr);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        // The owner filter names an assignee, first or co-assignee.
        $onAmal = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin, ['owner_id' => $this->amal->getKey()]));

        $this->assertSame([$this->amal->name], $this->labels($onAmal));
        $this->assertSame(1, $this->row($onAmal, $this->amal)->value('completed'), 'the task filed under the outsider is hers too');

        $onOmar = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin, ['owner_id' => $this->omar->getKey()]));

        $this->assertSame([$this->omar->name], $this->labels($onOmar));
        $this->assertSame(1, $this->row($onOmar, $this->omar)->value('completed'));

        // The team filter keeps the team's assignees, whoever a task is filed under.
        $riyadh = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin, ['team_id' => $this->team->getKey()]));

        $this->assertSame([$this->amal->name, $this->badr->name], $this->labels($riyadh));
        $this->assertSame(2, app(TaskPerformanceReport::class)->totals($riyadh)->value('completed'));

        // A manager narrows to a member; the outsider is dropped, and a forged id finds nothing.
        $this->assertSame([$this->amal->name], $this->labels(app(TaskPerformanceReport::class)->rows($this->manager, $this->taskReportFilters($this->manager, ['owner_id' => $this->amal->getKey()]))));

        $managerOnOmar = $this->taskReportFilters($this->manager, ['owner_id' => $this->omar->getKey()]);

        $this->assertNull($managerOnOmar->ownerId);
        $this->assertSame([$this->amal->name, $this->badr->name], $this->labels(app(TaskPerformanceReport::class)->rows($this->manager, $managerOnOmar)));

        $forged = new ReportFilters($managerOnOmar->from, $managerOnOmar->to, ownerId: $this->omar->getKey());

        $this->assertTrue(app(TaskPerformanceReport::class)->rows($this->manager, $forged)->isEmpty());
    }

    // --- The unassigned line ---

    #[Test]
    public function the_unassigned_line_holds_only_the_tasks_nobody_holds(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $nobody = $this->tasks->create(['title' => 'Nobody holds it', 'assignee_id' => null], $this->admin);

        // No first assignee, and Badr holds it as a co-assignee.
        $coHeld = $this->shareTask($this->tasks->create(['title' => 'Held by a co-assignee alone', 'assignee_id' => null], $this->admin), $this->badr);

        $this->assertNull($coHeld->assignee_id);
        $this->assertSame([(int) $this->badr->getKey()], $coHeld->assigneeIds());

        $this->travelTo(Carbon::parse('2026-09-03 09:00:00'));
        $this->tasks->complete($nobody, $this->admin);
        $this->tasks->complete($coHeld, $this->badr);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $rows = app(TaskPerformanceReport::class)->rows($this->admin, $this->taskReportFilters($this->admin));

        $this->assertSame([$this->badr->name, __('reports.labels.unassigned')], $this->labels($rows), 'the task Badr holds is his, not unassigned');
        $this->assertSame(2 * 86400, $this->row($rows, $this->badr)->value('finish_seconds'), 'from when it reached Badr');

        $unassigned = $rows->last();

        $this->assertInstanceOf(ReportRow::class, $unassigned);
        $this->assertNull($unassigned->meta['assignee_id']);
        $this->assertSame(1, $unassigned->value('completed'), 'only the task nobody holds');
        $this->assertSame(2 * 86400, $unassigned->value('finish_seconds'), 'measured from its creation');
        $this->assertSame(2, app(TaskPerformanceReport::class)->totals($rows)->value('completed'));
    }

    // --- The page ---

    #[Test]
    public function the_report_page_links_a_members_line_to_her_dashboard_and_names_no_outsider(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
        $shared = $this->handOut('Visit the client', [$this->omar, $this->amal]);

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));
        $this->tasks->complete($shared, $this->amal);

        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

        $html = Livewire::actingAs($this->manager)
            ->test(TaskPerformanceReportPage::class)
            ->assertSee($this->amal->name)
            ->assertDontSee($this->omar->name)
            ->html();

        $this->assertStringContainsString(EmployeeDashboardPage::getUrl(['employee' => $this->amal->getKey()]), $html, 'the member\'s line opens her dashboard');
    }

    // --- The employee dashboard (D-26) ---

    #[Test]
    public function the_employee_dashboard_reads_a_shared_task_as_the_co_assignees_own_and_agrees_with_the_report(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
        $done = $this->handOut('Filed under the outsider', [$this->omar], ['due_at' => '2026-10-08 18:00:00']);
        $this->handOut('Overdue together', [$this->omar, $this->amal], ['due_at' => '2026-10-10 10:00:00']);

        // Amal joins the first task two days later.
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00'));
        $this->tasks->update($done, ['assignee_ids' => [$this->omar->getKey(), $this->amal->getKey()]], $this->admin);

        $this->travelTo(Carbon::parse('2026-10-05 15:00:00'));
        $this->tasks->complete($done, $this->omar);

        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $dashboard = app(EmployeeDashboard::class);
        $snapshot = $dashboard->snapshot($this->manager, $this->amal);

        $this->assertSame(1, $snapshot->open);
        $this->assertSame(1, $snapshot->overdue);
        $this->assertSame(['Overdue together'], array_map(static fn (EmployeeDashboardTask $task): string => $task->title, $snapshot->overdueTasks->tasks));
        $this->assertTrue($snapshot->overdueTasks->tasks[0]->readable, 'the manager opens it through the member who holds it');
        $this->assertSame(1, $snapshot->completedThisMonth);
        $this->assertSame(100.0, $snapshot->onTimeRate);
        $this->assertSame(2 * 86400 + 6 * 3600, $snapshot->averageSecondsToFinish, 'from when it reached Amal on 3 Oct, not from 1 Oct');

        $row = $this->row(app(TaskPerformanceReport::class)->rows($this->manager, ReportFilters::currentMonth(app(SettingsRepository::class)->timezone())), $this->amal);

        $this->assertSame($snapshot->completedThisMonth, $row->value('completed'));
        $this->assertSame($snapshot->overdue, $row->value('overdue_now'));
        $this->assertSame($snapshot->averageSecondsToFinish, $row->value('avg_time_to_finish'), 'the dashboard and the report measure from the same moment');

        // The outsider holds both tasks and still lies outside the manager's reach.
        $this->assertNull($dashboard->employee($this->manager, (int) $this->omar->getKey()));

        $this->expectException(AuthorizationException::class);
        $dashboard->snapshot($this->manager, $this->omar);
    }

    #[Test]
    public function the_employee_list_offers_whoever_holds_a_task_that_still_exists_as_a_co_assignee(): void
    {
        $holder = $this->userWithPermissions(null, []);
        $formerHolder = $this->userWithPermissions(null, []);

        $this->shareTask($this->taskAssignedTo($this->omar, TaskStatus::Pending), $holder);

        $deleted = $this->shareTask($this->taskAssignedTo($this->omar, TaskStatus::Pending), $formerHolder);
        $deleted->delete();

        $dashboard = app(EmployeeDashboard::class);
        $listed = array_map('intval', $dashboard->employees($this->admin)->pluck('users.id')->all());

        $this->assertContains((int) $holder->getKey(), $listed, 'a co-assignee carries the shared task');
        $this->assertNotContains((int) $formerHolder->getKey(), $listed, 'a deleted task carries nobody');
        $this->assertSame(1, $dashboard->snapshot($this->admin, $holder)->open);
    }

    /**
     * A pending task the administrator hands out to the given employees, the
     * first one first (D-28).
     *
     * @param  list<User>  $assignees
     * @param  array<string, mixed>  $attributes
     */
    private function handOut(string $title, array $assignees, array $attributes = []): Task
    {
        return $this->tasks->create([
            'title' => $title,
            'assignee_ids' => array_map(static fn (User $user): int => (int) $user->getKey(), $assignees),
            ...$attributes,
        ], $this->admin);
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     * @return list<string>
     */
    private function labels(Collection $rows): array
    {
        return $rows->map(static fn (ReportRow $row): string => $row->label)->values()->all();
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     */
    private function row(Collection $rows, User $assignee): ReportRow
    {
        $row = $rows->first(static fn (ReportRow $row): bool => ($row->meta['assignee_id'] ?? null) === (int) $assignee->getKey());

        $this->assertInstanceOf(ReportRow::class, $row, "no report line for {$assignee->name}");

        return $row;
    }
}
