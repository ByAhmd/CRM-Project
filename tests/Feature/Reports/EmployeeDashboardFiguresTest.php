<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\CrmRole;
use App\Enums\TaskStatus;
use App\Enums\TaskUpdateKind;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\Statistics\Reports\EmployeeDashboard;
use App\Services\Statistics\Reports\EmployeeDashboardSnapshot;
use App\Services\Statistics\Reports\EmployeeDashboardTask;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use App\Services\Statistics\Reports\TaskPerformanceReport;
use App\Services\Tasks\StalledTaskRule;
use App\Services\Tasks\WeeklySummary;
use App\Services\Tasks\WeeklySummaryTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Every figure of the employee dashboard (decision D-26), read by an
 * administrator (all level) for one employee at fixed moments.
 *
 * The application stores moments in Asia/Riyadh (config app.timezone); the
 * organisation's clock, calendar and week start come from the settings, so
 * the boundary cases set an organisation timezone or week start that differs
 * from the defaults and pin completions minutes either side of a boundary.
 */
final class EmployeeDashboardFiguresTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->admin = $this->admin();
        $this->employee = $this->makeUser(CrmRole::Employee, ['name' => 'Employee One']);
    }

    #[Test]
    public function the_now_figures_count_open_overdue_and_due_today_on_the_organisation_day(): void
    {
        // Wednesday 14 October 2026, noon in Riyadh.
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $other = $this->salesRep();

        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Due yesterday', 'due_at' => Carbon::parse('2026-10-13 10:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Due this morning', 'due_at' => Carbon::parse('2026-10-14 09:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Due tonight', 'due_at' => Carbon::parse('2026-10-14 23:30:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Due tomorrow', 'due_at' => Carbon::parse('2026-10-15 00:30:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'No due date', 'due_at' => null]);
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['due_at' => Carbon::parse('2026-10-12 10:00:00')])->delete();
        $this->taskAssignedTo($this->employee, TaskStatus::Cancelled, ['due_at' => Carbon::parse('2026-10-12 10:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['due_at' => Carbon::parse('2026-10-12 10:00:00'), 'completed_at' => Carbon::parse('2026-10-13 10:00:00')]);
        $this->taskAssignedTo($other, TaskStatus::Pending, ['due_at' => Carbon::parse('2026-10-12 10:00:00')]);

        $snapshot = $this->snapshot();

        $this->assertSame(5, $snapshot->open);
        $this->assertSame(2, $snapshot->overdue, 'open and due before now; the deleted, cancelled, completed and other tasks are not');
        $this->assertSame(2, $snapshot->dueToday, 'open and due during the organisation day, overdue or not');
        $this->assertSame(['Due yesterday', 'Due this morning'], $this->titles($snapshot->overdueTasks->tasks), 'oldest due first');
        $this->assertSame([1, 0], array_map(static fn (EmployeeDashboardTask $task): int => $task->days, $snapshot->overdueTasks->tasks));
        $this->assertSame(2, $snapshot->overdueTasks->total);
        $this->assertSame(0, $snapshot->overdueTasks->more());
    }

    #[Test]
    public function the_month_follows_the_organisation_calendar_not_the_storage_clock(): void
    {
        // The organisation keeps UTC while moments are stored in Riyadh time
        // (UTC+3): 01:30 Riyadh on 1 October is still 30 September for the
        // organisation, 03:30 Riyadh is already October.
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC'], $this->admin);
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'September in UTC', 'completed_at' => Carbon::parse('2026-10-01 01:30:00'), 'due_at' => null]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'October in UTC', 'completed_at' => Carbon::parse('2026-10-01 03:30:00'), 'due_at' => null]);

        $this->assertSame(1, $this->snapshot()->completedThisMonth);
    }

    #[Test]
    public function the_overdue_count_matches_the_task_reports_overdue_now_when_the_organisation_keeps_another_timezone(): void
    {
        // The organisation keeps UTC while moments are stored in Riyadh time
        // (UTC+3). Noon in Riyadh is 09:00 UTC: a task due at 10:30 Riyadh
        // fell due an hour and a half ago, whichever clock reads it.
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'UTC'], $this->admin);
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));

        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Due this morning', 'due_at' => Carbon::parse('2026-10-14 08:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Due an hour and a half ago', 'due_at' => Carbon::parse('2026-10-14 10:30:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Due this afternoon', 'due_at' => Carbon::parse('2026-10-14 13:00:00')]);

        $snapshot = $this->snapshot();

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, ReportFilters::currentMonth(app(SettingsRepository::class)->timezone()));
        $row = $rows->first(fn (ReportRow $row): bool => ($row->meta['assignee_id'] ?? null) === (int) $this->employee->getKey());

        $this->assertInstanceOf(ReportRow::class, $row);
        $this->assertSame(2, $snapshot->overdue);
        $this->assertSame(['Due this morning', 'Due an hour and a half ago'], $this->titles($snapshot->overdueTasks->tasks));
        $this->assertSame($snapshot->overdue, $row->value('overdue_now'), "the dashboard's overdue is the task report's overdue now");
    }

    #[Test]
    public function a_completion_late_on_the_last_day_of_the_month_counts_in_that_month(): void
    {
        // Organisation clock = Riyadh (the default): 23:30 on 30 September is
        // September; 00:30 on 1 October — still 30 September in UTC — is October.
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));

        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-09-30 23:30:00'), 'due_at' => null]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-10-01 00:30:00'), 'due_at' => null]);

        $snapshot = $this->snapshot();

        $this->assertSame(1, $snapshot->completedThisMonth);
        $this->assertSame(100.0, $snapshot->onTimeRate, 'without a due date a completion is on time');
    }

    #[Test]
    public function the_week_starts_on_the_configured_day_and_the_trend_keeps_eight_weeks(): void
    {
        // Wednesday 14 October 2026. The organisation's week starts on Saturday.
        app(SettingsRepository::class)->update([SettingsRepository::WEEK_STARTS_ON => 6], $this->admin);
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));

        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-10-09 23:30:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-10-10 00:15:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-10-14 08:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-08-22 00:30:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-08-21 23:00:00')]);

        $snapshot = $this->snapshot();

        $this->assertSame(2, $snapshot->completedThisWeek, 'Saturday 00:15 and Wednesday; Friday 23:30 was last week');
        $this->assertCount(EmployeeDashboard::TREND_WEEKS, $snapshot->trend);
        $this->assertSame('2026-08-22', $snapshot->trend[0]['start']->toDateString(), 'eight Saturday weeks, the current one last');
        $this->assertSame('2026-10-10', $snapshot->trend[7]['start']->toDateString());
        $this->assertSame([1, 0, 0, 0, 0, 0, 1, 2], array_map(static fn (array $week): int => $week['completed'], $snapshot->trend));

        // The default Sunday week: Saturday 10 October belongs to the week before.
        app(SettingsRepository::class)->update([SettingsRepository::WEEK_STARTS_ON => 0], $this->admin);
        $sunday = $this->snapshot();

        $this->assertSame(1, $sunday->completedThisWeek);
        $this->assertSame('2026-10-11', $sunday->trend[7]['start']->toDateString());
        $this->assertSame([0, 0, 0, 0, 0, 0, 2, 1], array_map(static fn (array $week): int => $week['completed'], $sunday->trend));
    }

    #[Test]
    public function the_on_time_rate_and_the_average_time_to_finish_read_this_months_completions_as_the_task_report_does(): void
    {
        // Handed out at 09:00 on 1 October (assigned_at is stamped then).
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['due_at' => Carbon::parse('2026-10-05 18:00:00'), 'completed_at' => Carbon::parse('2026-10-05 09:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['due_at' => Carbon::parse('2026-10-03 18:00:00'), 'completed_at' => Carbon::parse('2026-10-06 09:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['due_at' => null, 'completed_at' => Carbon::parse('2026-10-02 15:00:00')]);
        $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['due_at' => null, 'completed_at' => Carbon::parse('2026-09-28 15:00:00')]);

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        $snapshot = $this->snapshot();

        $this->assertSame(3, $snapshot->completedThisMonth, 'September is not this month');
        $this->assertSame(66.7, $snapshot->onTimeRate, 'two of three: on or before the due date, or without one');
        // 4 days + 5 days + 30 hours = 885 600 s over 3 tasks.
        $this->assertSame(295200, $snapshot->averageSecondsToFinish);

        $rows = app(TaskPerformanceReport::class)->rows($this->admin, ReportFilters::currentMonth(app(SettingsRepository::class)->timezone()));
        $row = $rows->first(fn (ReportRow $row): bool => ($row->meta['assignee_id'] ?? null) === (int) $this->employee->getKey());

        $this->assertInstanceOf(ReportRow::class, $row);
        $this->assertSame($snapshot->averageSecondsToFinish, $row->value('avg_time_to_finish'), 'measured exactly as the task report (D-21)');
    }

    #[Test]
    public function with_nothing_completed_this_month_the_rate_and_the_average_are_empty_not_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['due_at' => null]);

        $snapshot = $this->snapshot();

        $this->assertSame(0, $snapshot->completedThisMonth);
        $this->assertNull($snapshot->onTimeRate);
        $this->assertNull($snapshot->averageSecondsToFinish);
        $this->assertSame(array_fill(0, EmployeeDashboard::TREND_WEEKS, 0), array_map(static fn (array $week): int => $week['completed'], $snapshot->trend));
    }

    #[Test]
    public function the_stalled_list_is_the_weekly_summarys_own_rule(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 10:00:00');
        $this->travelTo($now->subDays(10));

        $quiet = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Quiet task', 'due_at' => null]);
        $this->threadEntryOn($quiet, $this->employee, $now->subDays(4), TaskUpdateKind::Progress);

        $commented = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Answered task', 'due_at' => null]);
        $this->threadEntryOn($commented, $this->employee, $now->subDays(6), TaskUpdateKind::Progress);
        $this->threadEntryOn($commented, $this->admin, $now->subDay(), TaskUpdateKind::Comment);

        $ticked = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Ticked task', 'due_at' => null]);
        $this->threadEntryOn($ticked, $this->employee, $now->subDays(6), TaskUpdateKind::Progress);
        $this->checklistItemOn($ticked, 'Step', $now->subDay());

        $oldTick = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Old tick', 'due_at' => null]);
        $this->checklistItemOn($oldTick, 'Step', $now->subDays(5));

        $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Legacy quiet', 'due_at' => null, 'updated_at' => $now->subDays(8)]);
        $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Legacy touched', 'due_at' => null, 'updated_at' => $now->subDay()]);
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Never started', 'due_at' => null, 'updated_at' => $now->subDays(8)]);

        $this->travelTo($now);
        $snapshot = $this->snapshot();
        $summary = app(WeeklySummary::class)->report($now);

        $expected = ['Legacy quiet', 'Old tick', 'Quiet task'];

        $this->assertSame($expected, $this->titles($snapshot->stalledTasks->tasks), 'quietest first: no sign at all, then the oldest last progress');
        $this->assertSame($expected, array_map(static fn (WeeklySummaryTask $task): string => $task->title, $summary->stalled), 'the weekly summary reads the same rule');
        $this->assertSame($summary->stalledTotal, $snapshot->stalledTasks->total);
        $this->assertSame(
            array_map(static fn (WeeklySummaryTask $task): int => $task->days, $summary->stalled),
            array_map(static fn (EmployeeDashboardTask $task): int => $task->days, $snapshot->stalledTasks->tasks),
        );
        $this->assertSame([8, 5, 4], array_map(static fn (EmployeeDashboardTask $task): int => $task->days, $snapshot->stalledTasks->tasks));
        $this->assertSame(StalledTaskRule::AFTER_DAYS, WeeklySummary::STALLED_AFTER_DAYS);
    }

    #[Test]
    public function the_checklist_list_names_this_months_completions_with_items_left_open(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));

        $open = $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'Left open', 'completed_at' => Carbon::parse('2026-10-10 10:00:00')]);
        $this->checklistItemOn($open, 'One', Carbon::parse('2026-10-09 10:00:00'));
        $this->checklistItemOn($open, 'Two');
        $this->checklistItemOn($open, 'Three');

        $done = $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'All ticked', 'completed_at' => Carbon::parse('2026-10-11 10:00:00')]);
        $this->checklistItemOn($done, 'One', Carbon::parse('2026-10-11 09:00:00'));

        $lastMonth = $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'Last month', 'completed_at' => Carbon::parse('2026-09-30 10:00:00')]);
        $this->checklistItemOn($lastMonth, 'One');

        $stillOpen = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Still open']);
        $this->checklistItemOn($stillOpen, 'One');

        $later = $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['title' => 'Later', 'completed_at' => Carbon::parse('2026-10-15 10:00:00')]);
        $this->checklistItemOn($later, 'One');

        $list = $this->snapshot()->checklistOpenTasks;

        $this->assertSame(['Later', 'Left open'], $this->titles($list->tasks), 'newest completion first');
        $this->assertSame(['0/1', '1/3'], array_map(static fn (EmployeeDashboardTask $task): ?string => $task->checklist, $list->tasks));
        $this->assertSame(2, $list->total);
    }

    #[Test]
    public function each_list_is_capped_with_a_count_of_the_rest(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));

        for ($i = 0; $i < EmployeeDashboard::LIST_LIMIT + 2; $i++) {
            $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Overdue '.$i, 'due_at' => Carbon::parse('2026-10-01 10:00:00')->addHours($i)]);

            $done = $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-10-02 10:00:00')->addHours($i)]);
            $this->checklistItemOn($done, 'Open step');
        }

        $snapshot = $this->snapshot();

        $this->assertCount(EmployeeDashboard::LIST_LIMIT, $snapshot->overdueTasks->tasks);
        $this->assertSame(EmployeeDashboard::LIST_LIMIT + 2, $snapshot->overdueTasks->total);
        $this->assertSame(2, $snapshot->overdueTasks->more());
        $this->assertSame('Overdue 0', $snapshot->overdueTasks->tasks[0]->title);
        $this->assertCount(EmployeeDashboard::LIST_LIMIT, $snapshot->checklistOpenTasks->tasks);
        $this->assertSame(2, $snapshot->checklistOpenTasks->more());
    }

    #[Test]
    public function the_figures_cost_the_same_number_of_queries_whatever_the_workload(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        $this->workload(2);
        // Warm the settings and permission caches, read once per process.
        $this->snapshot();
        $small = $this->queryCount();

        $this->workload(20);
        $large = $this->queryCount();

        $this->assertSame($small, $large, "{$small} queries for a light workload, {$large} for a heavy one");
    }

    #[Test]
    public function the_latest_activity_is_the_employees_own_newest_entries_on_tasks_that_still_exist(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        $task = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Live task']);
        $gone = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['title' => 'Deleted task']);

        for ($i = 1; $i <= EmployeeDashboard::ACTIVITY_LIMIT + 2; $i++) {
            $this->threadEntryOn($task, $this->employee, Carbon::parse('2026-10-01 10:00:00')->addHours($i), TaskUpdateKind::Progress, 'Note '.$i);
        }

        $this->threadEntryOn($task, $this->admin, Carbon::parse('2026-10-19 10:00:00'), TaskUpdateKind::Comment, 'From the admin');
        $this->threadEntryOn($gone, $this->employee, Carbon::parse('2026-10-19 11:00:00'), TaskUpdateKind::Comment, 'On a deleted task');
        $gone->delete();

        $entries = $this->snapshot()->latestActivity;

        $this->assertCount(EmployeeDashboard::ACTIVITY_LIMIT, $entries);
        $this->assertSame('Note '.(EmployeeDashboard::ACTIVITY_LIMIT + 2), $entries[0]->body, 'newest first');
        $this->assertSame('Live task', $entries[0]->taskTitle);
        $this->assertSame(TaskUpdateKind::Progress, $entries[0]->kind);
        $this->assertTrue($entries[0]->readable);
        $this->assertNotContains('From the admin', array_map(static fn ($entry): string => $entry->body, $entries));
        $this->assertNotContains('On a deleted task', array_map(static fn ($entry): string => $entry->body, $entries));
    }

    private function snapshot(): EmployeeDashboardSnapshot
    {
        return app(EmployeeDashboard::class)->snapshot($this->admin, $this->employee);
    }

    /**
     * @param  list<EmployeeDashboardTask>  $tasks
     * @return list<string>
     */
    private function titles(array $tasks): array
    {
        return array_map(static fn (EmployeeDashboardTask $task): string => $task->title, $tasks);
    }

    /** Overdue, stalled, checklist-open and commented tasks, $count of each. */
    private function workload(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['due_at' => Carbon::parse('2026-10-01 10:00:00')]);

            $stalled = $this->taskAssignedTo($this->employee, TaskStatus::InProgress, ['due_at' => null]);
            $this->threadEntryOn($stalled, $this->employee, Carbon::parse('2026-10-10 10:00:00'), TaskUpdateKind::Progress);

            $done = $this->taskAssignedTo($this->employee, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-10-05 10:00:00')]);
            $this->checklistItemOn($done, 'Open step');
        }
    }

    private function queryCount(): int
    {
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->snapshot();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
