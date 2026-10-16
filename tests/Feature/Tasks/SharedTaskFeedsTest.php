<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Enums\MyWorkBucket;
use App\Enums\MyWorkView;
use App\Enums\Permission;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Filament\Pages\TasksBoard;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskReminderNotification;
use App\Notifications\WeeklySummaryNotification;
use App\Services\Access\RecordAssignmentService;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\TaskMetrics;
use App\Services\Tasks\CalendarEvent;
use App\Services\Tasks\CalendarFeed;
use App\Services\Tasks\MyWorkFeed;
use App\Services\Tasks\MyWorkSection;
use App\Services\Tasks\TaskBoardFeed;
use App\Services\Tasks\TaskReminderService;
use App\Services\Tasks\TaskService;
use App\Services\Tasks\WeeklySummary;
use App\Services\Tasks\WeeklySummaryAssignee;
use App\Services\Tasks\WeeklySummaryTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Shared tasks (D-28) in everything that lists, counts or announces tasks:
 * My Work (D-27) finds a shared task in each holder's own work and in the
 * handed-out view of whoever handed it out by either way; the dashboard
 * figures pin it to each holder; the board gives it a card in every holder's
 * column and leaves the unassigned bucket to tasks nobody holds; the calendar
 * lets a manager move it through a team member who holds it; the reminders
 * and overdue notices reach every holder who may sign in, once per task; the
 * weekly summary (D-18) counts it in each holder's row, once in the totals,
 * and counts every colleague added by someone else as handed out. An outsider
 * never meets the task anywhere, and none of these reads costs a query per
 * row.
 */
final class SharedTaskFeedsTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const int TOLERANCE = 3;

    private User $admin;

    private User $huda;

    private User $omar;

    private User $sara;

    private User $outsider;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        // Thursday 2026-10-15, 10:00 in Riyadh (the application and the organisation clock).
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
        config()->set('mail.default', 'log');

        $this->admin = $this->makeUser(CrmRole::Admin, ['name' => 'Office Admin']);
        $this->huda = $this->makeUser(CrmRole::Employee, ['name' => 'Huda', 'locale' => 'ar']);
        $this->omar = $this->makeUser(CrmRole::Employee, ['name' => 'Omar', 'locale' => 'en']);
        $this->sara = $this->makeUser(CrmRole::Employee, ['name' => 'Sara']);
        $this->outsider = $this->makeUser(CrmRole::Employee, ['name' => 'Outsider']);
        $this->tasks = app(TaskService::class);
    }

    // --- My Work ---

    #[Test]
    public function my_work_lists_a_shared_task_for_every_holder_with_all_of_them_and_never_for_an_outsider(): void
    {
        $task = $this->shared('Count the stock', ['due_at' => '2026-10-15 17:00:00'], $this->huda, $this->omar);
        $feed = app(MyWorkFeed::class);

        foreach ([$this->huda, $this->omar] as $reader) {
            $today = $this->section($reader, MyWorkView::Mine, MyWorkBucket::Today);

            $this->assertSame([(int) $task->getKey()], $this->ids($today->tasks), "{$reader->name} holds it as their own");
            $this->assertSame(['Huda', 'Omar'], $this->names($today->tasks[0]), 'every assignee, the first one first, read from the eager-loaded relations');
            $this->assertSame(MyWorkView::Mine, $feed->resolveView($reader, null));

            $found = $feed->find($reader, (string) $task->getKey());
            $this->assertNotNull($found);
            $this->assertTrue($found->is($task));
            $this->assertTrue($found->relationLoaded('assignee') && $found->relationLoaded('coAssignees'), 'a row action reads every assignee without a query');
            $this->assertTrue($found->isHandedOutTo($reader), 'the administrator handed it to both');
        }

        $team = $this->section($this->admin, MyWorkView::Team, MyWorkBucket::Today);
        $this->assertSame([(int) $task->getKey()], $this->ids($team->tasks));
        $this->assertSame(['Huda', 'Omar'], $this->names($team->tasks[0]));

        $this->assertSame([MyWorkView::Mine], $feed->views($this->outsider));

        foreach (MyWorkView::cases() as $view) {
            foreach ($feed->sections($this->outsider, $view) as $section) {
                $this->assertSame(0, $section->total, "the outsider's {$view->value} view holds nothing");
            }

            $this->assertNull($feed->find($this->outsider, $task->getKey(), $view), "the outsider resolves nothing in {$view->value}");
        }
    }

    #[Test]
    public function the_handed_out_view_follows_both_ways_of_handing_a_task_out(): void
    {
        $otherAdmin = $this->makeUser(CrmRole::Admin, ['name' => 'Second Admin']);
        $feed = app(MyWorkFeed::class);

        // As its assigner: filed under someone else.
        $assigned = $this->shared('Handed to Huda', ['due_at' => '2026-10-16 12:00:00'], $this->huda);

        // By sharing: Huda's own to-do, to which the administrator added Omar.
        $joined = $this->tasks->create(['title' => 'Huda own, shared later', 'due_at' => '2026-10-16 12:00:00', 'assignee_ids' => [$this->huda->getKey()]], $this->huda);
        $this->tasks->update($joined, ['assignee_ids' => [$this->huda->getKey(), $this->omar->getKey()]], $this->admin);

        // Not handed out by him: another administrator's, and one he joins himself.
        $foreign = $this->tasks->create(['title' => 'Someone else handed this out', 'due_at' => '2026-10-16 12:00:00', 'assignee_ids' => [$this->omar->getKey(), $this->sara->getKey()]], $otherAdmin);
        $selfJoined = $this->tasks->create(['title' => 'Joined himself', 'due_at' => '2026-10-16 12:00:00', 'assignee_ids' => [$this->sara->getKey()]], $otherAdmin);

        $this->assertSame(MyWorkView::Team, $feed->resolveView($this->admin, null), 'no open work of his own yet');

        $this->tasks->update($selfJoined, ['assignee_ids' => [$this->sara->getKey(), $this->admin->getKey()]], $this->admin);

        $delegated = $this->viewIds($this->admin, MyWorkView::Delegated);
        $this->assertEqualsCanonicalizing([(int) $assigned->getKey(), (int) $joined->getKey()], $delegated);
        $this->assertNotContains((int) $foreign->getKey(), $delegated);
        $this->assertNotContains((int) $selfJoined->getKey(), $delegated, 'joining a task oneself hands nothing out');

        foreach ($this->section($this->admin, MyWorkView::Delegated, MyWorkBucket::NextSevenDays)->tasks as $row) {
            $this->assertTrue($row->relationLoaded('coAssignees'), 'the handed-out view names every assignee too');
        }

        $this->assertSame([(int) $selfJoined->getKey()], $this->viewIds($this->admin, MyWorkView::Mine), 'a task he joined is his own work');
        $this->assertSame(MyWorkView::Mine, $feed->resolveView($this->admin, null), 'and he now opens on it');
    }

    #[Test]
    public function whoever_shared_a_task_keeps_the_handed_out_view_without_task_assign_and_nobody_else_gains_it(): void
    {
        $coordinator = $this->userWithPermissions(null, [Permission::WorkspaceTasks, Permission::TaskViewAny, Permission::TaskViewAll, Permission::TaskUpdate, Permission::TaskAssign]);
        $own = $this->tasks->create(['title' => 'Huda own', 'assignee_ids' => [$this->huda->getKey()]], $this->huda);
        $this->tasks->update($own, ['assignee_ids' => [$this->huda->getKey(), $this->omar->getKey()]], $coordinator);

        $coordinator->revokePermissionTo(Permission::TaskAssign->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $coordinator = $coordinator->fresh() ?? $coordinator;

        $this->assertFalse($coordinator->can(Permission::TaskAssign->value));
        $this->assertContains(MyWorkView::Delegated, app(MyWorkFeed::class)->views($coordinator), 'he added Omar to the task');
        $this->assertSame([(int) $own->getKey()], $this->viewIds($coordinator, MyWorkView::Delegated));

        foreach ([$this->huda, $this->omar] as $holder) {
            $this->assertNotContains(MyWorkView::Delegated, app(MyWorkFeed::class)->views($holder), 'holding a shared task hands nothing out');
        }
    }

    #[Test]
    public function my_work_reads_shared_tasks_without_a_query_per_row(): void
    {
        $render = fn () => Livewire::actingAs($this->omar)->test(TasksDashboard::class)->assertOk();

        $this->seedSharedWork(1);
        $render();
        $small = $this->queriesDuring($render);

        $this->seedSharedWork(6);
        $large = $this->queriesDuring($render);

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("My Work: %d queries for 4 shared tasks, %d for 28. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    // --- The dashboard figures ---

    #[Test]
    public function the_dashboard_pins_a_shared_task_to_each_holder_and_a_manager_reaches_it_through_a_team_member(): void
    {
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $member = $this->salesRep($team);
        $elsewhere = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $otherManager = $this->salesManager($this->makeTeam('Dammam Team', 'فريق الدمام'));

        $today = $this->shared('Call the supplier', ['kind' => TaskKind::Call->value, 'due_at' => '2026-10-15 15:00:00'], $elsewhere, $member);
        $notOurs = $this->shared('Jeddah only', ['kind' => TaskKind::FollowUp->value, 'due_at' => '2026-10-16 15:00:00'], $elsewhere);
        $metrics = app(TaskMetrics::class);

        foreach ([$member, $elsewhere] as $holder) {
            $mine = $metrics->myTasksToday($holder);

            $this->assertSame([(int) $today->getKey()], $this->ids($mine->all()), 'due today in each holder\'s own list');
            $this->assertTrue($mine->first()?->relationLoaded('coAssignees') ?? false, 'with every assignee');
            $this->assertSame([(int) $today->getKey()], $this->ids($metrics->myAttentionQuery($holder)->get()->all()));
        }

        $this->assertSame([], $this->ids($metrics->myTasksToday($this->outsider)->all()));
        $this->assertSame([], $this->ids($metrics->myAttentionQuery($manager)->get()->all()), 'the manager holds neither');

        $upcoming = $this->ids($metrics->upcomingFollowUps($manager)->all());
        $this->assertContains((int) $today->getKey(), $upcoming, 'reached through the team member who holds it');
        $this->assertNotContains((int) $notOurs->getKey(), $upcoming);
        $this->assertSame([], $this->ids($metrics->upcomingFollowUps($otherManager)->all()), 'another team reaches neither');

        // The tasks-by-status chart under the dashboard's owner and team filters.
        $pending = fn (array $filters): int => $metrics->countsByStatus(
            $this->admin,
            DashboardFilters::fromArray(['from' => '2026-10-01', 'to' => '2026-10-31', ...$filters], $this->admin),
        )[TaskStatus::Pending->value];

        $this->assertSame(2, $pending([]), 'each task once, however many hold it');
        $this->assertSame(1, $pending(['owner_id' => $member->getKey()]), 'the owner filter finds the task its co-assignee holds');
        $this->assertSame(2, $pending(['owner_id' => $elsewhere->getKey()]));
        $this->assertSame(0, $pending(['owner_id' => $otherManager->getKey()]));
        $this->assertSame(1, $pending(['team_id' => $team->getKey()]), 'the team filter reaches it through the member who holds it');
    }

    // --- The tasks board ---

    #[Test]
    public function the_board_gives_a_shared_task_a_card_in_every_holders_column_and_the_unassigned_bucket_only_tasks_nobody_holds(): void
    {
        $shared = $this->shared('Pack the order', ['due_at' => '2026-10-16 09:00:00'], $this->huda, $this->omar);
        $hudas = $this->shared('Huda alone', ['due_at' => '2026-10-17 09:00:00'], $this->huda);
        $omars = $this->shared('Omar alone', ['due_at' => '2026-10-18 09:00:00'], $this->omar);
        $orphan = $this->shared('Left to Sara', ['due_at' => '2026-10-19 09:00:00'], $this->huda, $this->sara);
        app(RecordAssignmentService::class)->assign($orphan, null, $this->admin);
        $nobodys = Task::factory()->unassigned()->create(['title' => 'Nobody holds this', 'due_at' => '2026-10-20 09:00:00']);
        $closed = $this->shared('Done together', [], $this->huda, $this->omar);
        $this->tasks->complete($closed, $this->huda);

        $feed = app(TaskBoardFeed::class);
        $board = $feed->board();

        $this->assertSame([
            'Huda' => [[(int) $shared->getKey(), (int) $hudas->getKey()], 2],
            'Omar' => [[(int) $shared->getKey(), (int) $omars->getKey()], 2],
            'Sara' => [[(int) $orphan->getKey()], 1],
            '' => [[(int) $nobodys->getKey()], 1],
        ], $this->columns($board['columns']), 'Huda left the orphan when its first assignee was cleared; Sara still holds it');

        foreach ($board['columns'] as $column) {
            foreach ($column['tasks'] as $card) {
                $this->assertTrue($card->relationLoaded('assignee') && $card->relationLoaded('coAssignees'), 'a card names every assignee without a query');
            }
        }

        $this->assertSame(['Huda', 'Omar'], $this->names($board['columns'][1]['tasks'][0]), 'the shared card in Omar\'s column');
        $this->assertSame(['Sara'], $this->names($board['columns'][2]['tasks'][0]));

        // «مهامي فقط»: every open task the user holds, either way, in their one column.
        $this->assertSame(['Omar' => [[(int) $shared->getKey(), (int) $omars->getKey()], 2]], $this->columns($feed->board($this->omar)['columns']));
        $this->assertSame(['Sara' => [[(int) $orphan->getKey()], 1]], $this->columns($feed->board($this->sara)['columns']));
        $this->assertSame([], $feed->board($this->outsider)['columns']);
    }

    #[Test]
    public function a_holders_column_is_capped_like_any_other_and_the_board_costs_no_query_per_card(): void
    {
        $render = fn () => Livewire::actingAs($this->admin)->test(TasksBoard::class)->assertOk();

        for ($i = 0; $i < 2; $i++) {
            $this->shared("Small {$i}", ['due_at' => Carbon::parse('2026-10-16 09:00:00')->addHours($i)], $this->huda, $this->omar);
        }

        $render();
        $small = $this->queriesDuring($render);

        for ($i = 0; $i < TaskBoardFeed::TASKS_PER_ASSIGNEE; $i++) {
            $this->shared("Large {$i}", ['due_at' => Carbon::parse('2026-10-20 09:00:00')->addHours($i)], $this->huda, $this->omar, $this->sara);
        }

        $large = $this->queriesDuring($render);

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("TasksBoard: %d queries for 2 shared tasks, %d for 10. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );

        $columns = app(TaskBoardFeed::class)->board()['columns'];

        foreach ([0 => 'Huda', 1 => 'Omar'] as $position => $name) {
            $this->assertSame($name, $columns[$position]['assignee']?->name);
            $this->assertCount(TaskBoardFeed::TASKS_PER_ASSIGNEE, $columns[$position]['tasks']);
            $this->assertSame(TaskBoardFeed::TASKS_PER_ASSIGNEE + 2, $columns[$position]['total']);
            $this->assertSame(2, $columns[$position]['more']);
        }

        $this->assertSame('Sara', $columns[2]['assignee']?->name);
        $this->assertSame(TaskBoardFeed::TASKS_PER_ASSIGNEE, $columns[2]['total']);
        $this->assertSame(0, $columns[2]['more']);
    }

    #[Test]
    public function the_by_status_view_for_one_user_holds_every_task_they_hold_and_only_what_the_reader_may_open(): void
    {
        $shared = $this->shared('Pack the order', [], $this->huda, $this->omar);
        $hudas = $this->shared('Huda alone', [], $this->huda);
        $feed = app(TaskBoardFeed::class);

        $this->actingAs($this->omar);
        $pending = $feed->byStatus(TaskResource::getEloquentQuery(), $this->omar)[0];
        $this->assertSame(TaskStatus::Pending, $pending['status']);
        $this->assertSame([(int) $shared->getKey()], $this->ids($pending['tasks']), 'the co-assignee\'s own column');
        $this->assertSame(['Huda', 'Omar'], $this->names($pending['tasks'][0]));

        $this->actingAs($this->admin);
        $this->assertSame([(int) $shared->getKey()], $this->ids($feed->byStatus(TaskResource::getEloquentQuery(), $this->omar)[0]['tasks']));
        $this->assertEqualsCanonicalizing([(int) $shared->getKey(), (int) $hudas->getKey()], $this->ids($feed->byStatus(TaskResource::getEloquentQuery(), $this->huda)[0]['tasks']));

        $this->actingAs($this->outsider);
        $this->assertSame([], $this->ids($feed->byStatus(TaskResource::getEloquentQuery())[0]['tasks']), 'an outsider opens neither');
    }

    // --- The calendar ---

    #[Test]
    public function the_calendar_shows_a_shared_task_to_its_holders_and_a_manager_moves_it_through_a_team_member_who_holds_it(): void
    {
        [$manager, $member, $elsewhere, $thirdManager] = $this->twoTeams();
        $task = $this->shared('Visit the client', ['due_at' => '2026-10-20 10:00:00'], $elsewhere, $member);
        $id = 'task-'.$task->getKey();

        $managed = $this->calendarEvent($manager, $id);
        $this->assertNotNull($managed, 'the manager sees it through the team member who holds it');
        $this->assertTrue($managed->editable, 'and may move it, as TaskPolicy says');
        $this->assertTrue($managed->extendedProps['canEdit']);
        $this->assertTrue($manager->can('update', $task));

        foreach ([$member, $elsewhere] as $holder) {
            $event = $this->calendarEvent($holder, $id);
            $this->assertNotNull($event, "{$holder->name} holds it");
            $this->assertFalse($event->editable, 'handed out by the administrator: its date stays his (D-14)');
            $this->assertFalse($holder->can('update', $task));
        }

        $this->assertNull($this->calendarEvent($thirdManager, $id), 'a team holding none of it never sees it');
        $this->assertNull($this->calendarEvent($this->outsider, $id));
    }

    #[Test]
    public function a_calendar_range_full_of_shared_tasks_costs_no_query_per_entry(): void
    {
        [$manager, $member, $elsewhere] = $this->twoTeams();
        $range = function () use ($manager): void {
            $this->assertNotEmpty($this->calendarEvents($manager));
        };

        for ($i = 0; $i < 2; $i++) {
            $this->shared("Small {$i}", ['due_at' => Carbon::parse('2026-10-16 09:00:00')->addDays($i)], $elsewhere, $member);
        }

        $range();
        $small = $this->queriesDuring($range);

        for ($i = 0; $i < 12; $i++) {
            $this->shared("Large {$i}", ['due_at' => Carbon::parse('2026-10-18 09:00:00')->addDays($i % 10)], $elsewhere, $member);
        }

        $large = $this->queriesDuring($range);

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("CalendarFeed: %d queries for 2 shared tasks, %d for 14. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    // --- Reminders and overdue notices ---

    #[Test]
    public function a_reminder_and_an_overdue_notice_reach_every_holder_who_may_sign_in_once_per_task(): void
    {
        $gone = $this->makeUser(CrmRole::Employee, ['name' => 'Gone']);
        $task = $this->shared('Renew the contract', ['due_at' => '2026-10-14 17:00:00', 'reminder_at' => '2026-10-14 16:00:00'], $this->huda, $this->omar, $this->sara, $gone);
        $this->sara->forceFill(['status' => UserStatus::Disabled])->save();
        $gone->delete();
        Notification::fake();

        $reminders = app(TaskReminderService::class);

        $this->assertSame(2, $reminders->sendDueReminders(now()), 'one reminder for each holder told');

        foreach ([[$this->huda, 'ar'], [$this->omar, 'en']] as [$holder, $locale]) {
            Notification::assertSentToTimes($holder, TaskReminderNotification::class, 1);
            Notification::assertSentTo($holder, TaskReminderNotification::class, static fn (TaskReminderNotification $notification): bool => $notification->locale === $locale);
        }

        foreach ([$this->sara, $gone, $this->outsider, $this->admin] as $silent) {
            Notification::assertNotSentTo($silent, TaskReminderNotification::class);
        }

        $this->assertSame('2026-10-15 10:00:00', $task->refresh()->reminder_sent_at?->format('Y-m-d H:i:s'), 'the task is stamped once');
        $this->assertSame(0, $reminders->sendDueReminders(now()), 'and reminded about once');

        $this->assertSame(2, $reminders->notifyOverdue(now()));

        foreach ([$this->huda, $this->omar] as $holder) {
            Notification::assertSentToTimes($holder, TaskOverdueNotification::class, 1);
        }

        foreach ([$this->sara, $gone, $this->outsider, $this->admin] as $silent) {
            Notification::assertNotSentTo($silent, TaskOverdueNotification::class);
        }

        $this->assertNotNull($task->refresh()->overdue_notified_at);
        $this->assertSame(0, $reminders->notifyOverdue(now()));
    }

    #[Test]
    public function a_task_held_only_by_co_assignees_is_still_announced_and_the_commands_count_each_notice(): void
    {
        $orphan = $this->shared('Left to Sara', ['reminder_at' => '2026-10-15 09:00:00'], $this->huda, $this->sara);
        app(RecordAssignmentService::class)->assign($orphan, null, $this->admin);
        $nobodys = Task::factory()->unassigned()->create(['reminder_at' => '2026-10-15 09:00:00']);
        Notification::fake();

        $this->assertSame(1, app(TaskReminderService::class)->sendDueReminders(now()));
        Notification::assertSentToTimes($this->sara, TaskReminderNotification::class, 1);
        Notification::assertNotSentTo($this->huda, TaskReminderNotification::class);
        $this->assertNotNull($orphan->refresh()->reminder_sent_at);
        $this->assertNull($nobodys->refresh()->reminder_sent_at, 'a task nobody holds is no one\'s to be told');

        $this->shared('Two to tell', ['due_at' => '2026-10-14 09:00:00'], $this->huda, $this->omar);

        $this->artisan('tasks:notify-overdue')
            ->expectsOutputToContain('2 overdue notifications sent')
            ->assertSuccessful();
    }

    // --- The weekly summary ---

    #[Test]
    public function the_weekly_summary_counts_a_shared_task_in_every_holders_row_and_once_in_the_totals(): void
    {
        $now = $this->weeklyClock();

        $this->shared('Late together', ['due_at' => $now->subDays(2)], $this->huda, $this->omar);
        $done = $this->shared('Done together', ['due_at' => $now->addDay()], $this->huda, $this->sara);
        $this->tasks->complete($done, $this->sara);
        $orphan = $this->shared('Left to Sara', ['due_at' => $now->addDays(2)], $this->omar, $this->sara);
        app(RecordAssignmentService::class)->assign($orphan, null, $this->admin);
        Task::factory()->unassigned()->create(['title' => 'Nobody holds this', 'status' => TaskStatus::Pending, 'due_at' => $now->addDays(3)]);

        $report = app(WeeklySummary::class)->report($now);

        $this->assertSame([
            ['Huda', 1, 1, 1],
            ['Omar', 1, 1, 0],
            ['Sara', 1, 0, 1],
            [null, 1, 0, 0],
        ], array_map(static fn (WeeklySummaryAssignee $row): array => [$row->name, $row->open, $row->overdue, $row->completed], $report->assignees), 'the unassigned row keeps only the task nobody holds');
        $this->assertSame(4, $report->assigneesTotal);
        $this->assertSame(1, $report->completed, 'a task completed once is one completion');
        $this->assertSame(1, $report->overdueTotal, 'one overdue task, however many hold it');
        $this->assertCount(1, $report->overdue);
    }

    #[Test]
    public function handing_out_in_the_week_counts_every_colleague_added_by_someone_else(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 22:30:00', 'Asia/Riyadh');

        // Nine days ago, before the window.
        $this->travelTo($now->subDays(9));
        $older = $this->shared('Older, joined this week', [], $this->huda);
        $this->shared('Older, shared then', [], $this->omar, $this->sara);

        $this->travelTo($now->subDay());
        // Created for Huda and Omar: the task and Omar's place in it, two hand-outs.
        $this->shared('Handed to two', [], $this->huda, $this->omar);
        // Sara joins the older task: one more.
        $this->tasks->update($older, ['assignee_ids' => [$this->huda->getKey(), $this->sara->getKey()]], $this->admin);
        // Huda's own to-do, which the administrator joins himself: nothing handed out.
        $own = $this->tasks->create(['title' => 'Huda own', 'assignee_ids' => [$this->huda->getKey()]], $this->huda);
        $this->tasks->update($own, ['assignee_ids' => [$this->huda->getKey(), $this->admin->getKey()]], $this->admin);
        // Shared and then deleted: counts for nothing.
        $this->shared('Deleted', [], $this->huda, $this->sara)->delete();

        $this->travelTo($now);

        $this->assertSame(3, app(WeeklySummary::class)->report($now)->handedOut);
    }

    #[Test]
    public function a_listed_shared_task_names_its_first_assignee_and_how_many_others_hold_it_in_both_languages(): void
    {
        $now = $this->weeklyClock();

        $this->shared('Late for three', ['due_at' => $now->subDays(3)], $this->huda, $this->omar, $this->sara);
        $orphan = $this->shared('Late, left to two', ['due_at' => $now->subDays(2)], $this->omar, $this->sara, $this->huda);
        app(RecordAssignmentService::class)->assign($orphan, null, $this->admin);
        $this->shared('Late for one', ['due_at' => $now->subDay()], $this->omar);

        $report = app(WeeklySummary::class)->report($now);

        $this->assertSame([
            ['Late for three', 'Huda', 2],
            ['Late, left to two', 'Sara', 1],
            ['Late for one', 'Omar', 0],
        ], array_map(static fn (WeeklySummaryTask $line): array => [$line->title, $line->assigneeName, $line->otherAssignees], $report->overdue), 'a task whose first assignee left it is named after its earliest co-assignee');

        $notification = new WeeklySummaryNotification($report, null, []);

        App::setLocale('en');
        $html = (string) $notification->toMail($this->admin)->render();
        $this->assertStringContainsString('Huda and 2 other assignees', $html);
        $this->assertStringContainsString('Sara and 1 other assignee<br>', $html);
        $this->assertStringContainsString('Omar<br>', $html);
        $this->assertStringNotContainsString('Omar and', $html);

        App::setLocale('ar');
        $html = (string) $notification->toMail($this->admin)->render();
        $this->assertStringContainsString('Huda ومكلَّفان آخران', $html);
        $this->assertStringContainsString('Sara ومكلَّف آخر', $html);
        $this->assertStringNotContainsString('weekly_summary.', $html);
    }

    #[Test]
    public function the_weekly_summary_reads_shared_work_on_a_fixed_number_of_queries(): void
    {
        $now = $this->weeklyClock();
        $report = static fn () => app(WeeklySummary::class)->report($now);

        $this->shared('Small late', ['due_at' => $now->subDays(2)], $this->huda, $this->omar);
        $report();
        $small = $this->queriesDuring($report);

        for ($i = 0; $i < 10; $i++) {
            $this->shared("Large late {$i}", ['due_at' => $now->subDays(2)->subHours($i)], $this->huda, $this->omar, $this->sara);
        }

        $large = $this->queriesDuring($report);

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("WeeklySummary::report(): %d queries for 1 shared task, %d for 11. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    /**
     * A pending task the administrator handed out to the given employees,
     * the first one first, through TaskService.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function shared(string $title, array $attributes, User ...$assignees): Task
    {
        return $this->tasks->create([
            'title' => $title,
            ...$attributes,
            'assignee_ids' => array_map(static fn (User $user): int => (int) $user->getKey(), $assignees),
        ], $this->admin);
    }

    /** Open tasks the administrator handed to Huda, Omar and Sara together, one in each dated bucket and one without a date. */
    private function seedSharedWork(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            foreach (['2026-10-13 09:00:00', '2026-10-15 16:00:00', '2026-10-18 09:00:00', null] as $due) {
                $this->shared("Shared {$i}", ['due_at' => $due], $this->huda, $this->omar, $this->sara);
            }
        }
    }

    /**
     * A manager over a team member, a rep in another team, and the manager
     * of a third team none of them belongs to.
     *
     * @return array{0: User, 1: User, 2: User, 3: User}
     */
    private function twoTeams(): array
    {
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $otherTeam = $this->makeTeam('Jeddah Team', 'فريق جدة');

        return [$manager, $this->salesRep($team), $this->salesRep($otherTeam), $this->salesManager($this->makeTeam('Dammam Team', 'فريق الدمام'))];
    }

    /** The scheduled moment of the weekly summary on the test's Thursday. */
    private function weeklyClock(): CarbonImmutable
    {
        $now = CarbonImmutable::parse('2026-10-15 22:30:00', 'Asia/Riyadh');
        $this->travelTo($now);

        return $now;
    }

    private function section(User $reader, MyWorkView $view, MyWorkBucket $bucket): MyWorkSection
    {
        foreach (app(MyWorkFeed::class)->sections($reader, $view) as $section) {
            if ($section->bucket === $bucket) {
                return $section;
            }
        }

        $this->fail("no {$bucket->value} section");
    }

    /**
     * Every task id a My Work view lists, across its buckets.
     *
     * @return list<int>
     */
    private function viewIds(User $reader, MyWorkView $view): array
    {
        $ids = [];

        foreach (app(MyWorkFeed::class)->sections($reader, $view) as $section) {
            $ids = [...$ids, ...$this->ids($section->tasks)];
        }

        return $ids;
    }

    /**
     * @return list<CalendarEvent>
     */
    private function calendarEvents(User $viewer): array
    {
        $this->actingAs($viewer);

        return app(CalendarFeed::class)
            ->events($viewer, Carbon::parse('2026-10-01 00:00:00', 'Asia/Riyadh'), Carbon::parse('2026-11-01 00:00:00', 'Asia/Riyadh'))
            ->values()
            ->all();
    }

    private function calendarEvent(User $viewer, string $id): ?CalendarEvent
    {
        foreach ($this->calendarEvents($viewer) as $event) {
            if ($event->id === $id) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The board's columns as name => [card ids, total] ('' for the unassigned bucket).
     *
     * @param  list<array{assignee: User|null, tasks: list<Task>, total: int, more: int}>  $columns
     * @return array<string, array{0: list<int>, 1: int}>
     */
    private function columns(array $columns): array
    {
        $shape = [];

        foreach ($columns as $column) {
            $shape[$column['assignee'] === null ? '' : $column['assignee']->name] = [$this->ids($column['tasks']), $column['total']];
        }

        return $shape;
    }

    /**
     * @param  array<array-key, Task>  $tasks
     * @return list<int>
     */
    private function ids(array $tasks): array
    {
        return array_values(array_map(static fn (Task $task): int => (int) $task->getKey(), $tasks));
    }

    /**
     * The names of every assignee of the task, the first one first.
     *
     * @return list<string>
     */
    private function names(Task $task): array
    {
        return array_map(static fn (User $user): string => $user->name, $task->assignees());
    }
}
