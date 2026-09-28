<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\ActivityLogEvent;
use App\Enums\Permission;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Task;
use App\Notifications\RecordAssignedNotification;
use App\Services\Tasks\TaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-14 (2026-09-21): task assignment is admin-only. `task.assign`
 * belongs to super_admin and admin alone — a manager works the team's tasks
 * but never hands them around — and the task remembers who assigned it
 * (`assigned_by`) so its completion can be reported back.
 */
final class TaskAssignmentRulesTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
    }

    #[Test]
    public function only_super_admins_and_admins_hold_the_assign_verb(): void
    {
        $team = $this->makeTeam();
        $rep = $this->salesRep($team);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        // D-14 (2026-09-21): task assignment is admin-only
        $this->assertTrue($this->superAdmin()->can('assign', $task));
        $this->assertTrue($this->admin()->can('assign', $task));
        $this->assertFalse($this->salesManager($team)->can('assign', $task), 'D-14 (2026-09-21): task assignment is admin-only');
        $this->assertFalse($rep->can('assign', $task));
        $this->assertFalse($this->support()->can('assign', $task));
        $this->assertFalse($this->readOnly()->can('assign', $task));

        $this->assertFalse($this->salesManager()->can(Permission::TaskAssign->value));
        $this->assertTrue($this->admin()->can(Permission::TaskAssign->value));
    }

    #[Test]
    public function the_assign_action_is_visible_to_admins_and_hidden_from_managers_and_reps(): void
    {
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $task = Task::factory()->create(['assignee_id' => $rep->getKey()]);

        Livewire::actingAs($this->admin())
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertTableActionVisible('assign', $task);

        Livewire::actingAs($this->superAdmin())
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertTableActionVisible('assign', $task);

        // D-14 (2026-09-21): task assignment is admin-only
        Livewire::actingAs($manager)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertTableActionHidden('assign', $task);

        Livewire::actingAs($rep)
            ->test(ListTasks::class)
            ->assertTableActionHidden('assign', $task);
    }

    #[Test]
    public function a_manager_can_no_longer_create_a_task_for_a_team_member(): void
    {
        // D-14 (2026-09-21): task assignment is admin-only — the create path
        // re-checks the permission, so even a teammate assignment is refused.
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);

        $this->expectException(UnassignableUserException::class);

        try {
            app(TaskService::class)->create(['title' => 'Handed down', 'assignee_id' => $rep->getKey()], $manager);
        } finally {
            $this->assertSame(0, Task::query()->count());
        }
    }

    #[Test]
    public function creating_for_someone_else_records_the_actor_as_the_assigner(): void
    {
        $admin = $this->admin();
        $rep = $this->salesRep();

        $task = app(TaskService::class)->create(['title' => 'For the rep', 'assignee_id' => $rep->getKey()], $admin);

        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame($admin->getKey(), (int) $task->assigned_by);
        $this->assertTrue($admin->is($task->assigner));
    }

    #[Test]
    public function a_task_created_for_someone_else_tells_the_assignee_by_bell_and_mail(): void
    {
        // D-14: under admin-only assignment, creating a task for an employee is
        // how work is handed out — the assignee hears of it exactly as of a
        // reassignment, and assignment mail is on unless they switched it off.
        config(['mail.default' => 'smtp']);
        Notification::fake();

        $admin = $this->admin();
        $rep = $this->salesRep();

        $task = app(TaskService::class)->create(['title' => 'For the rep', 'assignee_id' => $rep->getKey()], $admin);

        Notification::assertSentTo(
            $rep,
            RecordAssignedNotification::class,
            static fn (RecordAssignedNotification $notification, array $channels): bool => $channels === ['database', 'mail'],
        );
        Notification::assertSentToTimes($rep, RecordAssignedNotification::class, 1);
        Notification::assertNotSentTo($admin, RecordAssignedNotification::class);

        // A creation, not a reassignment, in the ledger (A-20).
        $this->assertDatabaseHas('activity_log', ['description' => ActivityLogEvent::TaskCreated->value, 'subject_id' => $task->getKey()]);
        $this->assertDatabaseMissing('activity_log', ['description' => ActivityLogEvent::TaskAssigned->value, 'subject_id' => $task->getKey()]);
    }

    #[Test]
    public function a_task_created_for_oneself_tells_nobody(): void
    {
        Notification::fake();

        $rep = $this->salesRep();

        app(TaskService::class)->create(['title' => 'My own follow-up'], $rep);

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_failing_mail_server_cannot_undo_handing_out_a_task(): void
    {
        // The assignment mail leaves after the task commits and through the
        // queue; on the host a dead mail server only fails the queued job.
        // Tests run the queue synchronously, so the failure still surfaces
        // here — but after the commit, with the task and the bell in place.
        config(['mail.default' => 'smtp']);

        Event::listen(NotificationSending::class, static function (NotificationSending $event): void {
            if ($event->channel === 'mail') {
                throw new RuntimeException('The mail server is down.');
            }
        });

        $admin = $this->admin();
        $rep = $this->salesRep();

        try {
            app(TaskService::class)->create(['title' => 'Handed out while mail is down', 'assignee_id' => $rep->getKey()], $admin);
            $this->fail('the mail failure should surface from the synchronous test queue');
        } catch (RuntimeException $exception) {
            $this->assertSame('The mail server is down.', $exception->getMessage());
        }

        $task = Task::query()->where('title', 'Handed out while mail is down')->sole();
        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame(1, $rep->notifications()->count(), 'the bell is written before, and independently of, the mail');
    }

    #[Test]
    public function the_assignment_bell_is_written_at_once_while_its_mail_is_queued(): void
    {
        $record = Task::factory()->create();
        $notification = new RecordAssignedNotification($record, $this->admin());

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['database' => 'sync'], $notification->viaConnections());
    }

    #[Test]
    public function creating_for_oneself_records_the_actor_as_the_assigner_too(): void
    {
        $rep = $this->salesRep();

        $task = app(TaskService::class)->create(['title' => 'My own follow-up', 'assignee_id' => $rep->getKey()], $rep);

        $this->assertSame($rep->getKey(), (int) $task->assignee_id);
        $this->assertSame($rep->getKey(), (int) $task->assigned_by);
    }

    #[Test]
    public function reassigning_updates_the_assigner_and_unassigning_clears_it(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $rep = $this->salesRep();
        $colleague = $this->salesRep();
        $task = Task::factory()->create(['assignee_id' => $rep->getKey(), 'assigned_by' => null]);

        app(TaskService::class)->update($task, ['owner_id' => $colleague->getKey()], $admin);

        $fresh = $task->refresh();
        $this->assertSame($colleague->getKey(), (int) $fresh->assignee_id);
        $this->assertSame($admin->getKey(), (int) $fresh->assigned_by);

        app(TaskService::class)->update($task, ['owner_id' => null], $admin);

        $fresh = $task->refresh();
        $this->assertNull($fresh->assignee_id);
        $this->assertNull($fresh->assigned_by);
    }

    #[Test]
    public function a_team_scoped_assigner_hands_tasks_out_inside_the_team_only(): void
    {
        // task.assign respects record scope: a role a super admin grants
        // assignment at team level assigns inside the team and nowhere else.
        $team = $this->makeTeam();
        $assigner = $this->userWithPermissions($team, [Permission::TaskViewAny, Permission::TaskViewTeam, Permission::TaskCreate, Permission::TaskUpdate, Permission::TaskAssign]);
        $teammate = $this->salesRep($team);
        $stranger = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));

        $task = app(TaskService::class)->create(['title' => 'Inside the team', 'assignee_id' => $teammate->getKey()], $assigner);
        $this->assertSame($teammate->getKey(), (int) $task->assignee_id);

        $this->expectException(UnassignableUserException::class);

        try {
            app(TaskService::class)->create(['title' => 'Outside the team', 'owner_id' => $stranger->getKey()], $assigner);
        } finally {
            $this->assertSame(0, Task::query()->where('title', 'Outside the team')->count());
        }
    }
}
