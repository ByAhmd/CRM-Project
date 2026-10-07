<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\MyWorkBucket;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Enums\TaskTransition;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Support\TaskActions;
use App\Models\Task;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Services\Tasks\MyWorkFeed;
use App\Services\Tasks\MyWorkSection;
use App\Services\Tasks\TaskService;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Callout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * «عملي» (My Work), the Tasks home (decision D-27): the reader's own tasks in
 * buckets on the organisation calendar, the counts, the shared visual
 * language on every row and the quick controls — each held to the rules it
 * must keep: only the reader's own tasks, the organisation day (not the
 * server's), the D-25 warning before completing with open checklist items,
 * no reschedule for the handed-out assignee (D-14 amendment), every write
 * through TaskService, a refused id never acted on.
 */
final class MyWorkTest extends TestCase
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
        // Wednesday 2026-10-07, 10:00 in Riyadh (the application and, by default, the organisation clock).
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        config()->set('mail.default', 'log');

        $this->admin = $this->admin();
        $this->employee = $this->employee();
    }

    #[Test]
    public function the_buckets_sort_the_readers_own_tasks_on_the_organisation_calendar(): void
    {
        $mine = fn (string $title, ?string $due, TaskStatus $status = TaskStatus::Pending, array $extra = []): Task => $this->taskAssignedTo($this->employee, $status, ['title' => $title, 'due_at' => $due, ...$extra]);

        $overdue = $mine('Yesterday late', '2026-10-06 23:59:00');
        $passedToday = $mine('This morning', '2026-10-07 08:00:00');
        $laterToday = $mine('Tonight', '2026-10-07 23:30:00');
        $tomorrow = $mine('Tomorrow', '2026-10-08 00:00:00');
        $seventhDay = $mine('Seventh day', '2026-10-14 23:59:00');
        $eighthDay = $mine('Eighth day', '2026-10-15 00:00:00');
        $undated = $mine('Some day', null);
        $inProgress = $mine('Under way', '2026-10-09 12:00:00', TaskStatus::InProgress);
        $doneYesterday = $mine('Done yesterday', '2026-10-06 12:00:00', TaskStatus::Completed, ['completed_at' => '2026-10-06 18:00:00']);
        $doneSixDaysAgo = $mine('Done on the first', '2026-09-30 12:00:00', TaskStatus::Completed, ['completed_at' => '2026-10-01 00:05:00']);

        // Never on My Work: completed before the last seven days, cancelled,
        // deleted, or someone else's.
        $mine('Done long ago', '2026-09-20 12:00:00', TaskStatus::Completed, ['completed_at' => '2026-09-30 23:55:00']);
        $mine('Called off', '2026-10-07 12:00:00', TaskStatus::Cancelled);
        $mine('Deleted', '2026-10-07 12:00:00')->delete();
        $this->taskAssignedTo($this->employee(), TaskStatus::Pending, ['title' => 'A colleague\'s', 'due_at' => '2026-10-07 12:00:00']);

        $this->assertSame([
            'overdue' => [$overdue->getKey()],
            'today' => [$passedToday->getKey(), $laterToday->getKey()],
            'next_7_days' => [$tomorrow->getKey(), $inProgress->getKey(), $seventhDay->getKey()],
            'later' => [$eighthDay->getKey()],
            'no_date' => [$undated->getKey()],
            'completed_recently' => [$doneYesterday->getKey(), $doneSixDaysAgo->getKey()],
        ], $this->bucketIds($this->employee));

        $this->assertSame(
            ['overdue' => 1, 'today' => 2, 'next_7_days' => 3, 'later' => 1, 'no_date' => 1, 'completed_recently' => 2],
            $this->bucketCounts($this->employee),
        );
    }

    #[Test]
    public function a_bucket_that_gains_its_first_task_opens_without_a_reload(): void
    {
        $this->actingAs($this->employee);

        $page = Livewire::test(TasksDashboard::class)
            ->assertSeeHtml('wire:key="my-work-bucket-today-empty"')
            ->assertDontSeeHtml('wire:key="my-work-bucket-today-filled"');

        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Arrived just now', 'due_at' => '2026-10-07 17:00:00']);

        // Any re-render after the task exists (quick add, a completion, the undo)
        // gives the bucket a new key, so wire:ignore.self cannot keep it folded.
        $page->call('$refresh')
            ->assertSeeHtml('wire:key="my-work-bucket-today-filled"')
            ->assertDontSeeHtml('wire:key="my-work-bucket-today-empty"')
            ->assertSee('Arrived just now');
    }

    #[Test]
    public function the_buckets_turn_at_the_organisation_midnight_not_the_servers(): void
    {
        app(SettingsRepository::class)->update([SettingsRepository::TIMEZONE => 'Asia/Tokyo'], $this->admin);

        // Riyadh 17:00 is Wednesday 23:00 in Tokyo; Riyadh 20:00 is Thursday 02:00; Riyadh Thursday 18:30 is Friday 00:30.
        $wednesdayLate = $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['due_at' => '2026-10-07 17:00:00']);
        $thursdayEarly = $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['due_at' => '2026-10-07 20:00:00']);
        $fridayEarly = $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['due_at' => '2026-10-08 18:30:00']);

        // 17:59 in Riyadh: still Wednesday 23:59 in Tokyo.
        $this->travelTo(Carbon::parse('2026-10-07 17:59:00'));

        $before = $this->bucketIds($this->employee);
        $this->assertSame([$wednesdayLate->getKey()], $before['today'], 'before Tokyo midnight the 23:00 task is due today');
        $this->assertSame([$thursdayEarly->getKey(), $fridayEarly->getKey()], $before['next_7_days']);
        $this->assertSame([], $before['overdue']);

        // 18:01 in Riyadh: Thursday 00:01 in Tokyo, while the server's day is still Wednesday.
        $this->travelTo(Carbon::parse('2026-10-07 18:01:00'));

        $after = $this->bucketIds($this->employee);
        $this->assertSame([$wednesdayLate->getKey()], $after['overdue'], 'past Tokyo midnight Wednesday is yesterday');
        $this->assertSame([$thursdayEarly->getKey()], $after['today']);
        $this->assertSame([$fridayEarly->getKey()], $after['next_7_days']);
    }

    #[Test]
    public function a_bucket_lists_its_first_tasks_and_counts_the_rest(): void
    {
        for ($i = 0; $i < MyWorkFeed::TASKS_PER_BUCKET + 2; $i++) {
            $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['due_at' => Carbon::parse('2026-10-01 09:00:00')->addMinutes($i)]);
        }

        $overdue = $this->section($this->employee, MyWorkBucket::Overdue);

        $this->assertSame(MyWorkFeed::TASKS_PER_BUCKET + 2, $overdue->total);
        $this->assertCount(MyWorkFeed::TASKS_PER_BUCKET, $overdue->tasks);
        $this->assertSame(2, $overdue->more());

        Livewire::actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->assertSee(trans_choice('my_work.actions.show_more', 2, ['count' => 2]))
            ->assertSeeHtml('href="'.e(TasksDashboard::listUrlFor(MyWorkBucket::Overdue)).'"');
    }

    #[Test]
    public function a_buckets_show_more_link_opens_the_list_on_exactly_the_buckets_tasks(): void
    {
        // An administrator reads everyone's tasks, so the list tabs alone would show a colleague's too.
        $mine = fn (string $title, ?string $due, TaskStatus $status = TaskStatus::Pending, array $extra = []): Task => $this->taskAssignedTo($this->admin, $status, ['title' => $title, 'due_at' => $due, ...$extra]);

        $buckets = [
            MyWorkBucket::Overdue->value => [$mine('Yesterday late', '2026-10-06 23:59:00')],
            MyWorkBucket::Today->value => [$mine('Tonight', '2026-10-07 23:30:00')],
            MyWorkBucket::NextSevenDays->value => [$mine('Tomorrow', '2026-10-08 00:00:00'), $mine('Seventh day', '2026-10-14 23:59:00')],
            MyWorkBucket::Later->value => [$mine('Eighth day', '2026-10-15 00:00:00')],
            MyWorkBucket::NoDate->value => [$mine('Some day', null)],
            MyWorkBucket::CompletedRecently->value => [$mine('Done yesterday', '2026-10-06 12:00:00', TaskStatus::Completed, ['completed_at' => '2026-10-06 18:00:00'])],
        ];

        $colleague = $this->employee();
        $theirs = [
            $this->taskAssignedTo($colleague, TaskStatus::Pending, ['title' => 'Their overdue', 'due_at' => '2026-10-05 09:00:00']),
            $this->taskAssignedTo($colleague, TaskStatus::Pending, ['title' => 'Their today', 'due_at' => '2026-10-07 18:00:00']),
            $this->taskAssignedTo($colleague, TaskStatus::Pending, ['title' => 'Their next week', 'due_at' => '2026-10-09 09:00:00']),
            $this->taskAssignedTo($colleague, TaskStatus::Pending, ['title' => 'Their undated', 'due_at' => null]),
            $this->taskAssignedTo($colleague, TaskStatus::Completed, ['title' => 'Their done', 'completed_at' => '2026-10-06 10:00:00']),
        ];
        $all = array_merge(...array_values($buckets));

        foreach ($buckets as $bucket => $expected) {
            parse_str((string) parse_url(TasksDashboard::listUrlFor(MyWorkBucket::from($bucket)), PHP_URL_QUERY), $query);

            Livewire::withQueryParams($query)
                ->actingAs($this->admin)
                ->test(ListTasks::class)
                ->assertCanSeeTableRecords($expected)
                ->assertCanNotSeeTableRecords(array_values(array_filter($all, static fn (Task $task): bool => ! in_array($task, $expected, true))))
                ->assertCanNotSeeTableRecords($theirs);
        }
    }

    #[Test]
    public function every_row_carries_the_shared_visual_language_and_the_controls_the_policy_allows(): void
    {
        $handedOut = app(TaskService::class)->create(['title' => 'Prepare the visit', 'assignee_id' => $this->employee->getKey(), 'due_at' => '2026-10-07 17:00:00', 'priority' => 'urgent'], $this->admin);
        $ownTask = app(TaskService::class)->create(['title' => 'Call the supplier', 'assignee_id' => $this->employee->getKey(), 'due_at' => '2026-10-05 09:00:00'], $this->employee);
        $this->checklistItemOn($ownTask, 'Find the number', now());
        $this->checklistItemOn($ownTask, 'Call');

        $html = Livewire::actingAs($this->employee)->test(TasksDashboard::class)->html();

        // The shared pieces: the relative due dates, the flag, the meter, the assigner's avatar.
        $this->assertStringContainsString('crm-due crm-due--today', $html);
        $this->assertStringContainsString(e(__('tasks.due.today', ['time' => '17:00'])), $html);
        $this->assertStringContainsString('crm-due crm-due--overdue', $html);
        $this->assertStringContainsString('crm-flag crm-flag--urgent', $html);
        $this->assertStringContainsString('crm-meter-text', $html);
        $this->assertStringContainsString(e(__('my_work.fields.handed_out_by', ['name' => $this->admin->name])), $html);

        // Both rows: the completion check and the status pill (the reader may start and complete either).
        foreach ([$handedOut, $ownTask] as $task) {
            $this->assertMatchesRegularExpression($this->mountPattern('quickComplete', $task), $html);
            $this->assertMatchesRegularExpression($this->mountPattern('changeStatus', $task), $html);

            // Each control is named with its task for a screen reader, as on the list and the board.
            $this->assertStringContainsString('aria-label="'.e(__('tasks.list_ux.helpers.mark_complete', ['task' => $task->title])).'"', $html);
            $this->assertStringContainsString('aria-label="'.e(__('tasks.board_ux.actions.change_status', ['status' => $task->status->getLabel(), 'task' => $task->title])).'"', $html);
        }

        // The dates stay with the assigner (D-14 amendment): reschedule on the employee's own task only.
        $this->assertMatchesRegularExpression($this->mountPattern('reschedule', $ownTask), $html);
        $this->assertDoesNotMatchRegularExpression($this->mountPattern('reschedule', $handedOut), $html);

        // Quick add is the page's primary action, marked for the N shortcut.
        $this->assertStringContainsString('data-crm-shortcut="quick-add"', $html);
    }

    #[Test]
    public function the_completion_check_completes_through_the_service_and_offers_the_undo(): void
    {
        $task = app(TaskService::class)->create(['title' => 'Send the quote', 'assignee_id' => $this->employee->getKey(), 'due_at' => '2026-10-07 17:00:00'], $this->admin);

        Livewire::actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->callAction(TestAction::make('quickComplete')->arguments(['task' => $task->getKey()]))
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.completed'));

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertSame([$task->getKey()], $this->bucketIds($this->employee)['completed_recently'], 'the task moves to Completed recently');
    }

    #[Test]
    public function completing_with_open_checklist_items_shows_the_warning_first(): void
    {
        $task = app(TaskService::class)->create(['title' => 'Install the line', 'assignee_id' => $this->employee->getKey(), 'due_at' => '2026-10-07 17:00:00'], $this->admin);
        $this->checklistItemOn($task, 'Cable', now());
        $this->checklistItemOn($task, 'Test');

        $page = Livewire::actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->mountAction(TestAction::make('quickComplete')->arguments(['task' => $task->getKey()]))
            ->assertActionMounted('quickComplete')
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: static fn (Callout $callout): bool => $callout->isVisible()
                && (string) $callout->getHeading() === trans_choice('tasks.confirmations.checklist_open', 2, ['count' => 1, 'total' => 2]));

        $this->assertSame(TaskStatus::Pending, $task->refresh()->status, 'nothing happens before the warning is confirmed');

        $page->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
    }

    #[Test]
    public function an_id_outside_the_readers_own_tasks_is_never_acted_on(): void
    {
        $colleague = $this->employee();
        $foreign = $this->taskAssignedTo($colleague, TaskStatus::Pending, ['due_at' => '2026-10-07 12:00:00']);
        // Even a task the reader may open (an administrator) is not theirs on My Work.
        $readable = $this->taskAssignedTo($colleague, TaskStatus::Pending, ['due_at' => '2026-10-07 12:00:00']);

        foreach (['quickComplete', 'changeStatus', 'reschedule'] as $action) {
            Livewire::actingAs($this->employee)
                ->test(TasksDashboard::class)
                ->call('mountAction', $action, ['task' => $foreign->getKey()])
                ->assertActionNotMounted($action);

            Livewire::actingAs($this->admin)
                ->test(TasksDashboard::class)
                ->call('mountAction', $action, ['task' => $readable->getKey()])
                ->assertActionNotMounted($action);

            Livewire::actingAs($this->employee)
                ->test(TasksDashboard::class)
                ->call('mountAction', $action, ['task' => 'not-an-id'])
                ->assertActionNotMounted($action);
        }

        $this->assertSame(TaskStatus::Pending, $foreign->refresh()->status);
        $this->assertSame(TaskStatus::Pending, $readable->refresh()->status);
    }

    #[Test]
    public function the_handed_out_assignee_cannot_reschedule_or_cancel_from_my_work(): void
    {
        $task = app(TaskService::class)->create(['title' => 'Visit the client', 'assignee_id' => $this->employee->getKey(), 'due_at' => '2026-10-09 10:00:00'], $this->admin);

        Livewire::actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->call('mountAction', 'reschedule', ['task' => $task->getKey()])
            ->assertActionNotMounted('reschedule');

        $this->assertSame('2026-10-09 10:00', $task->refresh()->due_at?->format('Y-m-d H:i'));
        $this->assertNotContains(TaskTransition::Cancel, $this->movesFor($this->employee, $task));

        // Their own task: the reschedule chips move it, through the service.
        $own = app(TaskService::class)->create(['title' => 'Book the room', 'assignee_id' => $this->employee->getKey(), 'due_at' => '2026-10-05 09:00:00'], $this->employee);

        Livewire::actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->callAction(TestAction::make('reschedule')->arguments(['task' => $own->getKey()]), ['due_shortcut' => 'tomorrow'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-10-08 09:00', $own->refresh()->due_at?->format('Y-m-d H:i'), 'tomorrow, keeping the task\'s 09:00');
    }

    #[Test]
    public function quick_add_is_the_primary_action_for_those_who_may_create_tasks(): void
    {
        Livewire::actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->assertActionVisible('quickAdd')
            ->callAction('quickAdd', ['title' => 'Water the plants', 'due_shortcut' => 'today', 'priority' => 'high'])
            ->assertHasNoActionErrors();

        $task = Task::query()->where('title', 'Water the plants')->firstOrFail();
        $this->assertSame($this->employee->getKey(), $task->assignee_id, 'an employee adds a task for themselves');
        $this->assertSame([$task->getKey()], $this->bucketIds($this->employee)['today']);

        // ?action=quickAdd (the phone bar's «+», the N shortcut) opens it on arrival.
        Livewire::withQueryParams(['action' => 'quickAdd'])
            ->actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->assertSet('defaultAction', 'quickAdd');
    }

    #[Test]
    public function an_empty_my_work_offers_one_next_step(): void
    {
        Livewire::actingAs($this->employee)
            ->test(TasksDashboard::class)
            ->assertSee(__('my_work.empty.heading'))
            ->assertSee(__('my_work.empty.description_add'))
            ->assertSeeHtml("mountAction('quickAdd')")
            ->assertSee(__('my_work.empty.buckets.overdue'));

        // Entering the workspace without any task permission: no quick add, no board; the page still answers.
        $bare = $this->userWithPermissions(null, [Permission::WorkspaceTasks]);

        Livewire::actingAs($bare)
            ->test(TasksDashboard::class)
            ->assertSee(__('my_work.empty.description'))
            ->assertActionHidden('quickAdd')
            ->assertDontSeeHtml("mountAction('quickAdd')");
    }

    #[Test]
    public function every_role_entering_the_tasks_workspace_opens_my_work_and_others_are_refused(): void
    {
        $team = $this->makeTeam();

        foreach ([$this->superAdmin(), $this->admin, $this->salesManager($team), $this->salesRep($team), $this->support(), $this->readOnly(), $this->employee] as $user) {
            $this->actingAs($user)->get(TasksDashboard::getUrl())->assertOk()->assertSee(__('my_work.pages.title'));
        }

        $this->actingAs(User::factory()->create())->get(TasksDashboard::getUrl())->assertForbidden();
    }

    #[Test]
    public function my_work_reads_in_both_languages(): void
    {
        $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Ship it', 'due_at' => '2026-10-04 09:00:00']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $this->employee->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($this->employee)->get(TasksDashboard::getUrl())->assertOk();

            $response->assertSee('dir="'.$direction.'"', escape: false)
                ->assertSee(__('my_work.pages.title', [], $locale))
                ->assertSee(__('enums.my_work_bucket.next_7_days', [], $locale))
                ->assertSee(trans_choice('tasks.due.overdue', 3, ['count' => 3], $locale));
        }
    }

    #[Test]
    public function the_page_renders_again_when_a_task_changes_elsewhere(): void
    {
        $task = $this->taskAssignedTo($this->employee, TaskStatus::Pending, ['title' => 'Undo me', 'due_at' => '2026-10-07 12:00:00']);

        $page = Livewire::actingAs($this->employee)->test(TasksDashboard::class)->assertSee('Undo me');

        $task->forceFill(['title' => 'Changed elsewhere'])->save();

        $page->dispatch(TaskActions::CHANGED_EVENT, task: $task->getKey())->assertSee('Changed elsewhere');
    }

    /**
     * @return array<string, list<int>>
     */
    private function bucketIds(User $reader): array
    {
        $ids = [];

        foreach (app(MyWorkFeed::class)->sections($reader) as $section) {
            $ids[$section->bucket->value] = array_map(static fn (Task $task): int => (int) $task->getKey(), $section->tasks);
        }

        return $ids;
    }

    /**
     * @return array<string, int>
     */
    private function bucketCounts(User $reader): array
    {
        $counts = [];

        foreach (app(MyWorkFeed::class)->sections($reader) as $section) {
            $counts[$section->bucket->value] = $section->total;
        }

        return $counts;
    }

    private function section(User $reader, MyWorkBucket $bucket): MyWorkSection
    {
        foreach (app(MyWorkFeed::class)->sections($reader) as $section) {
            if ($section->bucket === $bucket) {
                return $section;
            }
        }

        $this->fail("no {$bucket->value} section");
    }

    /**
     * @return list<TaskTransition>
     */
    private function movesFor(User $reader, Task $task): array
    {
        $this->actingAs($reader);

        return TaskActions::transitionsFor($task->refresh());
    }

    /** The click handler Filament renders for an action mounted with the row's task id. */
    private function mountPattern(string $action, Task $task): string
    {
        return '/wire:click="mountAction\(\''.$action.'\', JSON\.parse\(\'\{\\\\u0022task\\\\u0022:'.$task->getKey().'\}\'\)/';
    }
}
