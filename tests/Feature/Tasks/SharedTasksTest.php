<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\CrmRole;
use App\Enums\RecurrenceFrequency;
use App\Enums\TaskStatus;
use App\Enums\UserStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\TaskCoAssignee;
use App\Models\User;
use App\Notifications\RecordAssignedNotification;
use App\Notifications\TaskCommentNotification;
use App\Notifications\TaskCompletedNotification;
use App\Services\Access\RecordAssignmentService;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity as AuditEntry;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-28 (owner, 2026-10-15): "the ability to assign more than an
 * employee to the same task, so many employees have the same task". A task
 * keeps one status, checklist and thread; `assignee_ids` names every
 * assignee, the first one first (`tasks.assignee_id`, the one it is filed
 * under) and the others as co-assignees. Sharing is handing out (D-14):
 * `task.assign` and the actor's reach, each joiner told as a first assignee
 * is, one ledger entry per change. Every assignee owns the task for
 * visibility (D-4), finds it as their own, reports on it and completes it;
 * a handed-out co-assignee never edits it, and nobody else gains anything.
 */
final class SharedTasksTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $huda;

    private User $omar;

    private User $sara;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
        config()->set('mail.default', 'log');

        $this->admin = $this->admin();
        $this->huda = $this->employee();
        $this->omar = $this->employee();
        $this->sara = $this->employee();
        $this->tasks = app(TaskService::class);
    }

    // --- Creating a shared task ---

    #[Test]
    public function an_administrator_hands_one_task_to_several_employees_and_each_is_told(): void
    {
        Notification::fake();

        $task = $this->tasks->create([
            'title' => 'Count the stock',
            'assignee_ids' => [(string) $this->huda->getKey(), $this->omar->getKey(), $this->sara->getKey(), $this->omar->getKey()],
        ], $this->admin);

        $this->assertSame($this->huda->getKey(), $task->assignee_id, 'the first named is the one the task is filed under');
        $this->assertSame($this->admin->getKey(), $task->assigned_by);
        $this->assertSame([$this->huda->getKey(), $this->omar->getKey(), $this->sara->getKey()], $task->assigneeIds(), 'each once, in the order named');

        foreach (TaskCoAssignee::query()->where('task_id', $task->getKey())->get() as $row) {
            $this->assertSame($this->admin->getKey(), $row->assigned_by);
            $this->assertTrue($row->assigned_at->equalTo(now()), 'their time to finish starts now (D-21)');
        }

        foreach ([$this->huda, $this->omar, $this->sara] as $employee) {
            Notification::assertSentToTimes($employee, RecordAssignedNotification::class, 1);
        }

        Notification::assertNotSentTo($this->admin, RecordAssignedNotification::class);

        $entry = AuditEntry::query()->where('description', ActivityLogEvent::TaskAssigneesChanged->value)->sole();
        $this->assertSame([$this->omar->name, $this->sara->name], $entry->getExtraProperty('assignees_added'));
        $this->assertTrue($entry->subject?->is($task) ?? false);
    }

    #[Test]
    public function sharing_needs_task_assign_and_every_joiner_within_reach(): void
    {
        // An employee may create a task for themselves, never share one.
        $own = $this->tasks->create(['title' => 'My own', 'assignee_ids' => [$this->huda->getKey()]], $this->huda);
        $this->assertSame([$this->huda->getKey()], $own->assigneeIds());

        foreach ([[$this->huda->getKey(), $this->omar->getKey()], [$this->omar->getKey(), $this->huda->getKey()]] as $ids) {
            try {
                $this->tasks->create(['title' => 'Shared by an employee', 'assignee_ids' => $ids], $this->huda);
                $this->fail('an employee shared a task');
            } catch (UnassignableUserException) {
                // Refused before anything is written.
            }
        }

        // A disabled colleague is outside every reach.
        $this->sara->forceFill(['status' => UserStatus::Disabled])->save();

        try {
            $this->tasks->create(['title' => 'With a disabled colleague', 'assignee_ids' => [$this->huda->getKey(), $this->sara->getKey()]], $this->admin);
            $this->fail('a disabled user joined a task');
        } catch (UnassignableUserException) {
            // Refused.
        }

        $this->assertSame(0, Task::query()->where('title', 'like', '%by an employee%')->orWhere('title', 'With a disabled colleague')->count());
        $this->assertSame(0, TaskCoAssignee::query()->count());
    }

    // --- Reach: every assignee owns it, nobody else gains anything ---

    #[Test]
    public function every_assignee_sees_and_works_on_the_shared_task_and_an_outsider_does_not(): void
    {
        $outsider = $this->employee();
        $task = $this->shared('Pack the order', $this->huda, $this->omar);

        $this->actingAs($this->omar);
        $this->assertTrue(TaskResource::getEloquentQuery()->whereKey($task->getKey())->exists(), 'the co-assignee lists it');
        $this->assertTrue(Task::query()->assignedTo($this->omar)->whereKey($task->getKey())->exists(), 'and holds it as their own');

        foreach (['view', 'progress', 'start', 'postUpdate', 'complete', 'tickChecklist', 'comment'] as $verb) {
            $this->assertTrue($this->omar->can($verb, $task), "the co-assignee may {$verb}");
        }

        // Handed out by the administrator (D-14 amendment): the details and the task's fate stay with them.
        foreach (['update', 'cancel', 'delete', 'assign'] as $verb) {
            $this->assertFalse($this->omar->can($verb, $task), "the co-assignee may not {$verb}");
        }

        $this->actingAs($outsider);
        $this->assertFalse(TaskResource::getEloquentQuery()->whereKey($task->getKey())->exists());
        $this->assertFalse(Task::query()->assignedTo($outsider)->whereKey($task->getKey())->exists());

        foreach (['view', 'progress', 'complete', 'comment'] as $verb) {
            $this->assertFalse($outsider->can($verb, $task), "an outsider may not {$verb}");
        }
    }

    #[Test]
    public function a_manager_reaches_a_shared_task_through_a_team_member_holding_it(): void
    {
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $member = $this->salesRep($team);
        $otherTeam = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $task = $this->shared('Visit the client', $otherTeam, $member);
        $notOurs = $this->shared('Another team only', $otherTeam);

        $this->actingAs($manager);
        $visible = app(RecordVisibilityResolver::class)->visible($manager, Task::query())->pluck('id')->all();

        $this->assertContains($task->getKey(), $visible);
        $this->assertNotContains($notOurs->getKey(), $visible);
        $this->assertTrue($manager->can('view', $task));
        $this->assertFalse($manager->can('view', $notOurs));

        $writable = app(RecordVisibilityResolver::class)->writableOwner($manager, Task::class);
        $this->assertTrue($writable((int) $task->assignee_id, (int) $task->getKey()), 'the calendar reaches it through the co-assignee');
        $this->assertFalse($writable((int) $notOurs->assignee_id, (int) $notOurs->getKey()));
    }

    #[Test]
    public function the_visibility_memo_follows_a_sharing_made_or_undone_in_the_same_request(): void
    {
        $task = $this->shared('Late joiner', $this->huda);

        $this->assertFalse($this->omar->can('view', $task), 'not shared yet (and now memoised)');

        $this->tasks->update($task, ['assignee_ids' => [$this->huda->getKey(), $this->omar->getKey()]], $this->admin);
        $this->assertTrue($this->omar->can('view', $task), 'the new row clears the memo');

        $this->tasks->update($task, ['assignee_ids' => [$this->huda->getKey()]], $this->admin);
        $this->assertFalse($this->omar->can('view', $task), 'the removed row clears it again');
    }

    #[Test]
    public function the_visibility_memo_follows_a_co_assignee_joining_or_leaving_the_team_in_the_same_request(): void
    {
        // At team level the shared tasks are reached through the team's members, so a membership
        // change inside one request moves the per-record answer for a co-assignee exactly as for a
        // first assignee — and it always agrees with the list.
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $elsewhere = $this->makeTeam('Jeddah Team', 'فريق جدة');
        $outsider = $this->salesRep($elsewhere);

        $leaver = $this->salesRep($team);
        $removed = $this->salesRep($team);
        $joiner = $this->salesRep($elsewhere);
        $firstLeaver = $this->salesRep($team);

        $sharedByLeaver = $this->shared('Shared with a leaver', $outsider, $leaver);
        $sharedByRemoved = $this->shared('Shared with a removed account', $outsider, $removed);
        $sharedByJoiner = $this->shared('Shared with a joiner', $outsider, $joiner);
        $heldByFirstLeaver = $this->shared('Held by a leaver alone', $firstLeaver);

        $this->actingAs($manager);
        $resolver = app(RecordVisibilityResolver::class);
        $answers = function () use ($manager, $resolver, $sharedByLeaver, $sharedByRemoved, $sharedByJoiner, $heldByFirstLeaver): array {
            $listed = $resolver->visible($manager, Task::query())->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $writable = $resolver->writableOwner($manager, Task::class);
            $answers = [];

            foreach (['leaver' => $sharedByLeaver, 'removed' => $sharedByRemoved, 'joiner' => $sharedByJoiner, 'first leaver' => $heldByFirstLeaver] as $name => $task) {
                $fresh = Task::query()->findOrFail($task->getKey());

                $answers[$name] = [
                    'listed' => in_array((int) $task->getKey(), $listed, true),
                    'view' => $manager->can('view', $fresh),
                    'update' => $manager->can('update', $fresh),
                    'writable' => $writable((int) $fresh->assignee_id, (int) $fresh->getKey()),
                ];
            }

            return $answers;
        };
        $reached = ['listed' => true, 'view' => true, 'update' => true, 'writable' => true];
        $refused = ['listed' => false, 'view' => false, 'update' => false, 'writable' => false];

        // Every answer is memoised now.
        $this->assertSame(['leaver' => $reached, 'removed' => $reached, 'joiner' => $refused, 'first leaver' => $reached], $answers());

        // In the same request one co-assignee moves to another team, one account is removed, one
        // co-assignee joins the manager's team — and, as the control, a first assignee leaves.
        $leaver->forceFill(['team_id' => $elsewhere->getKey()])->save();
        $removed->delete();
        $joiner->forceFill(['team_id' => $team->getKey()])->save();
        $firstLeaver->forceFill(['team_id' => $elsewhere->getKey()])->save();

        $this->assertSame(['leaver' => $refused, 'removed' => $refused, 'joiner' => $reached, 'first leaver' => $refused], $answers());
    }

    // --- Working on it together ---

    #[Test]
    public function a_co_assignee_completes_the_task_for_everyone_and_the_assigner_is_told(): void
    {
        $task = $this->shared('Close the month', $this->huda, $this->omar);
        Notification::fake();

        $this->tasks->start($task, $this->omar);
        $this->tasks->complete($task, $this->omar, 'Done together');

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status, 'one status for the whole task');
        Notification::assertSentTo($this->admin, TaskCompletedNotification::class);
        Notification::assertNotSentTo($this->omar, TaskCompletedNotification::class);
    }

    #[Test]
    public function a_comment_tells_every_assignee_but_its_author(): void
    {
        $task = $this->shared('Prepare the samples', $this->huda, $this->omar, $this->sara);
        Notification::fake();

        $this->tasks->comment($task, $this->omar, 'I took the first half');

        Notification::assertSentTo([$this->huda, $this->sara, $this->admin], TaskCommentNotification::class);
        Notification::assertNotSentTo($this->omar, TaskCommentNotification::class);
    }

    // --- Changing who holds it ---

    #[Test]
    public function an_edit_changes_the_co_assignees_tells_only_who_joined_and_records_both_sides(): void
    {
        $task = $this->shared('Inventory', $this->huda, $this->omar);
        $newcomer = $this->employee();
        Notification::fake();

        $this->tasks->update($task, ['assignee_ids' => [$this->huda->getKey(), $newcomer->getKey(), $this->sara->getKey()]], $this->admin);

        $this->assertSame([$this->huda->getKey(), $newcomer->getKey(), $this->sara->getKey()], $task->refresh()->assigneeIds());
        Notification::assertSentTo([$newcomer, $this->sara], RecordAssignedNotification::class);
        Notification::assertNotSentTo([$this->huda, $this->omar], RecordAssignedNotification::class);

        $entry = AuditEntry::query()->where('description', ActivityLogEvent::TaskAssigneesChanged->value)->latest('id')->firstOrFail();
        $this->assertSame([$newcomer->name, $this->sara->name], $entry->getExtraProperty('assignees_added'));
        $this->assertSame([$this->omar->name], $entry->getExtraProperty('assignees_removed'));

        // An edit without `assignee_ids` (the Sales tab, the other details) keeps them.
        $this->tasks->update($task, ['title' => 'Inventory (Q4)'], $this->admin);
        $this->assertSame([$this->huda->getKey(), $newcomer->getKey(), $this->sara->getKey()], $task->refresh()->assigneeIds());
    }

    #[Test]
    public function only_a_holder_of_task_assign_changes_who_shares_a_task(): void
    {
        $support = $this->support();
        $task = $this->shared('Support may edit, not share', $this->huda, $this->omar);

        $this->assertTrue($support->can('update', $task));

        foreach ([[$this->huda->getKey()], [$this->huda->getKey(), $this->omar->getKey(), $this->sara->getKey()]] as $ids) {
            try {
                $this->tasks->update($task, ['assignee_ids' => $ids], $support);
                $this->fail('a user without task.assign changed the co-assignees');
            } catch (UnassignableUserException) {
                // Refused.
            }
        }

        // Nor the first assignee, whichever key carries it — the co-assignees untouched, or the two swapped.
        foreach ([
            ['assignee_ids' => [$this->sara->getKey(), $this->omar->getKey()]],
            ['assignee_ids' => [$this->omar->getKey(), $this->huda->getKey()]],
            ['owner_id' => $this->sara->getKey()],
            ['assignee_id' => $this->sara->getKey()],
            ['owner_id' => null],
        ] as $data) {
            try {
                $this->tasks->update($task, ['title' => 'Forged', ...$data], $support);
                $this->fail('a user without task.assign changed who holds the task: '.json_encode($data));
            } catch (UnassignableUserException) {
                // Refused before anything is written.
            }
        }

        $this->assertSame([$this->huda->getKey(), $this->omar->getKey()], $task->refresh()->assigneeIds());
        $this->assertSame('Support may edit, not share', $task->title);

        // Keeping them as they are is not a change.
        $this->tasks->update($task, ['title' => 'Edited', 'assignee_ids' => [$this->huda->getKey(), $this->omar->getKey()]], $support);
        $this->assertSame('Edited', $task->refresh()->title);
        $this->assertSame([$this->huda->getKey(), $this->omar->getKey()], $task->assigneeIds());
    }

    #[Test]
    public function the_first_assignee_is_never_also_a_co_assignee_whichever_path_moves_them(): void
    {
        $task = $this->shared('Swap', $this->huda, $this->omar);
        Notification::fake();

        // Through the form: Omar named first now — already holding the task, so not told again.
        $this->tasks->update($task, ['assignee_ids' => [$this->omar->getKey(), $this->huda->getKey()]], $this->admin);

        $this->assertSame($this->omar->getKey(), $task->refresh()->assignee_id);
        $this->assertSame([$this->omar->getKey(), $this->huda->getKey()], $task->assigneeIds());
        Notification::assertNotSentTo($this->omar, RecordAssignedNotification::class);

        // Through the assign action (RecordAssignmentService): the guard drops the duplicate row.
        app(RecordAssignmentService::class)->assign($task, $this->huda, $this->admin);

        $this->assertSame($this->huda->getKey(), $task->refresh()->assignee_id);
        $this->assertSame(0, TaskCoAssignee::query()->where('task_id', $task->getKey())->where('user_id', $this->huda->getKey())->count());
        $this->assertSame([$this->huda->getKey()], $task->assigneeIds(), 'Omar left the task when Huda took it over');
    }

    #[Test]
    public function clearing_the_first_assignee_moves_the_next_one_up_and_no_other_team_gains_the_task(): void
    {
        // D-28 "removing them makes the next one first": a shared task never drops to unassigned while
        // others hold it — an unassigned task is open to every team-level reader (D-4).
        $jeddah = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $riyadh = $this->salesRep($this->makeTeam());
        $dammamManager = $this->salesManager();
        $dammam = $this->makeTeam('Dammam Team', 'فريق الدمام', $dammamManager);
        $dammamManager->forceFill(['team_id' => $dammam->getKey()])->save();

        $byAction = $this->shared('Cleared through the assign action', $jeddah, $riyadh);
        $bySalesTab = $this->shared('Cleared through the Sales tab owner field', $jeddah, $riyadh);
        Notification::fake();

        $this->assertFalse($dammamManager->can('view', $byAction), 'no reach before');

        $owner = app(RecordAssignmentService::class)->assign($byAction, null, $this->admin);
        $this->tasks->update($bySalesTab, ['owner_id' => null], $this->admin);

        $this->assertTrue($owner?->is($riyadh) ?? false, 'the assign action reports who holds it now');

        foreach ([$byAction, $bySalesTab] as $task) {
            $task->refresh();

            $this->assertSame($riyadh->getKey(), (int) $task->assignee_id, 'the next one is first now');
            $this->assertSame([$riyadh->getKey()], $task->assigneeIds());
            $this->assertSame($this->admin->getKey(), (int) $task->assigned_by, 'handed to them by whoever added them');

            $this->actingAs($dammamManager);

            foreach (['view', 'update', 'delete', 'assign'] as $verb) {
                $this->assertFalse($dammamManager->can($verb, $task), "another team's manager may not {$verb} it");
            }

            $this->assertFalse(app(RecordVisibilityResolver::class)->visible($dammamManager, Task::query())->whereKey($task->getKey())->exists());
            $this->assertFalse(TaskResource::getEloquentQuery()->whereKey($task->getKey())->exists());
        }

        Notification::assertNotSentTo($riyadh, RecordAssignedNotification::class);

        // Clearing everyone at once is what it says: nobody holds the task.
        $everyone = $this->shared('Cleared altogether', $jeddah, $riyadh);
        $this->tasks->update($everyone, ['assignee_ids' => []], $this->admin);

        $this->assertNull($everyone->refresh()->assignee_id);
        $this->assertSame([], $everyone->assigneeIds());
    }

    #[Test]
    public function moving_someone_between_first_and_co_assignee_keeps_who_handed_it_to_them_and_since_when(): void
    {
        $other = $this->makeUser(CrmRole::Admin, ['name' => 'Yasmin Admin']);

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00'));
        $task = $this->shared('Swap the roles', $this->huda);

        // Another administrator adds Omar a day later.
        $this->travelTo(Carbon::parse('2026-10-13 09:00:00'));
        $this->tasks->update($task, ['assignee_ids' => [$this->huda->getKey(), $this->omar->getKey()]], $other);

        $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
        Notification::fake();
        $entries = AuditEntry::query()->where('description', ActivityLogEvent::TaskAssigneesChanged->value)->count();

        // Omar first now, Huda beside him: both already held the task.
        $this->tasks->update($task, ['assignee_ids' => [$this->omar->getKey(), $this->huda->getKey()]], $this->admin);
        $task->refresh();

        $this->assertSame([$this->omar->getKey(), $this->huda->getKey()], $task->assigneeIds());
        $this->assertTrue($task->assigned_at?->equalTo(Carbon::parse('2026-10-13 09:00:00')) ?? false, 'Omar has held it since he was added (D-21)');
        $this->assertSame($other->getKey(), (int) $task->assigned_by, 'handed to Omar by the administrator who added him (D-14)');

        $huda = TaskCoAssignee::query()->where('task_id', $task->getKey())->where('user_id', $this->huda->getKey())->sole();
        $this->assertTrue($huda->assigned_at->equalTo(Carbon::parse('2026-10-12 09:00:00')), 'Huda has held it since it was created');
        $this->assertSame($this->admin->getKey(), (int) $huda->assigned_by);

        Notification::assertNotSentTo([$this->huda, $this->omar], RecordAssignedNotification::class);
        $this->assertSame($entries, AuditEntry::query()->where('description', ActivityLogEvent::TaskAssigneesChanged->value)->count(), 'nobody joined or left');
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskAssigned->value, 'subject_id' => $task->getKey(), 'causer_id' => $this->admin->getKey()]);

        // The assign action onto the co-assignee: no second notice, their own stamps.
        app(RecordAssignmentService::class)->assign($task, $this->huda, $other);
        $task->refresh();

        $this->assertSame([$this->huda->getKey()], $task->assigneeIds(), 'the assign action replaces the first assignee');
        $this->assertTrue($task->assigned_at?->equalTo(Carbon::parse('2026-10-12 09:00:00')) ?? false);
        $this->assertSame($this->admin->getKey(), (int) $task->assigned_by);
        Notification::assertNotSentTo($this->huda, RecordAssignedNotification::class);

        // Someone new is still handed the task now, and told.
        app(RecordAssignmentService::class)->assign($task, $this->sara, $other);

        $this->assertTrue($task->refresh()->assigned_at?->equalTo(now()) ?? false);
        $this->assertSame($other->getKey(), (int) $task->assigned_by);
        Notification::assertSentToTimes($this->sara, RecordAssignedNotification::class, 1);
    }

    #[Test]
    public function a_disabled_co_assignee_moves_up_when_the_first_assignee_is_removed(): void
    {
        $edited = $this->shared('Edited without the first', $this->huda, $this->sara);
        $cleared = $this->shared('Cleared by the assign action', $this->huda, $this->sara);
        $this->sara->forceFill(['status' => UserStatus::Disabled])->save();

        // She already holds both tasks, so the actor's reach is not asked again.
        $this->tasks->update($edited, ['assignee_ids' => [$this->sara->getKey()]], $this->admin);
        app(RecordAssignmentService::class)->assign($cleared, null, $this->admin);

        $this->assertSame([$this->sara->getKey()], $edited->refresh()->assigneeIds());
        $this->assertSame([$this->sara->getKey()], $cleared->refresh()->assigneeIds());

        // Handing her a task she does not hold is still refused.
        $this->expectException(UnassignableUserException::class);
        app(RecordAssignmentService::class)->assign($this->shared('Not hers', $this->omar), $this->sara, $this->admin);
    }

    /**
     * Removing the first assignee moves up the earliest colleague whose
     * account is not deleted, on both paths (the assign action, the Sales
     * tab's owner field): a task filed under a removed account reads as
     * unassigned wherever only the first assignee is shown. With only
     * deleted accounts left it still never drops to unassigned.
     */
    #[Test]
    public function a_live_colleague_moves_up_ahead_of_a_deleted_account(): void
    {
        $cleared = $this->shared('Cleared by the assign action', $this->huda, $this->omar, $this->sara);
        $edited = $this->shared('Cleared on the Sales tab', $this->huda, $this->omar, $this->sara);
        $onlyDeleted = $this->shared('Only a deleted colleague left', $this->huda, $this->omar);
        $this->omar->delete();

        app(RecordAssignmentService::class)->assign($cleared, null, $this->admin);
        $this->tasks->update($edited, ['owner_id' => null], $this->admin);
        app(RecordAssignmentService::class)->assign($onlyDeleted, null, $this->admin);

        $this->assertSame([$this->sara->getKey(), $this->omar->getKey()], $cleared->refresh()->assigneeIds());
        $this->assertSame([$this->sara->getKey(), $this->omar->getKey()], $edited->refresh()->assigneeIds());
        $this->assertSame([$this->omar->getKey()], $onlyDeleted->refresh()->assigneeIds(), 'never unassigned while someone holds it');
    }

    #[Test]
    public function a_repeating_shared_task_stays_shared(): void
    {
        $task = $this->tasks->create([
            'title' => 'Weekly count',
            'due_at' => '2026-10-15 17:00:00',
            'recurrence_frequency' => RecurrenceFrequency::Weekly->value,
            'recurrence_interval' => 1,
            'assignee_ids' => [$this->huda->getKey(), $this->omar->getKey()],
        ], $this->admin);

        $this->tasks->complete($task, $this->huda);

        $next = Task::query()->where('series_id', $task->getKey())->sole();
        $this->assertSame([$this->huda->getKey(), $this->omar->getKey()], $next->assigneeIds());
        $this->assertSame($this->admin->getKey(), TaskCoAssignee::query()->where('task_id', $next->getKey())->value('assigned_by'));
    }

    #[Test]
    public function assigned_to_finds_the_tasks_a_user_holds_either_way(): void
    {
        $first = $this->shared('Huda first', $this->huda, $this->omar);
        $second = $this->shared('Omar first', $this->omar, $this->huda);
        $notHers = $this->shared('Sara alone', $this->sara);

        $ids = Task::query()->assignedTo($this->huda)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$first->getKey(), $second->getKey()], $ids);
        $this->assertNotContains($notHers->getKey(), $ids);
        $this->assertTrue($second->isAssignedTo($this->huda));
        $this->assertFalse($notHers->isAssignedTo($this->huda));
        $this->assertTrue($second->isShared());
        $this->assertFalse($notHers->isShared());
    }

    /** A pending task the administrator handed out to the given employees, the first one first. */
    private function shared(string $title, User ...$assignees): Task
    {
        return $this->tasks->create([
            'title' => $title,
            'assignee_ids' => array_map(static fn (User $user): int => (int) $user->getKey(), $assignees),
        ], $this->admin);
    }
}
