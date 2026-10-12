<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\MyWorkView;
use App\Enums\TaskStatus;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\MyWorkFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * «عملي» in three views (D-27 amendment, the owner's report 2026-10-12: the
 * tasks he and his administrator handed out "do not appear" on the Tasks
 * dashboard — it listed only the reader's own tasks). Whoever hands work out
 * now sees what they handed out («أسندتُها») and, with a team or all scope,
 * the team's tasks («الفريق»); a reader with no open work of their own opens on
 * the team's, and the task list opens on «الكل» for them too. Every view stays
 * inside the reader's visibility scope (D-4): an employee is offered only their
 * own tasks, and a forged ?view= falls back.
 */
final class MyWorkViewsTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-12 10:00:00'));
        config()->set('mail.default', 'log');
    }

    #[Test]
    public function an_administrator_without_work_of_their_own_opens_on_the_teams_tasks(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $this->handedOut($admin, $employee, 'Count the stock', '2026-10-12 17:00:00');

        $feed = app(MyWorkFeed::class);
        $this->assertSame([MyWorkView::Mine, MyWorkView::Delegated, MyWorkView::Team], $feed->views($admin));
        $this->assertSame(MyWorkView::Team, $feed->resolveView($admin, null), 'no open work of their own: the team view opens');

        Livewire::actingAs($admin)
            ->test(TasksDashboard::class)
            ->assertSee('Count the stock')
            ->assertSee(__('my_work.fields.assigned_to', ['name' => $employee->name]))
            ->assertSee(MyWorkView::Delegated->getLabel())
            ->assertSee(MyWorkView::Team->getLabel());

        // Once they have open work of their own, their own tasks open first again.
        $this->taskAssignedTo($admin, TaskStatus::Pending, ['title' => 'My own', 'due_at' => '2026-10-13 12:00:00']);
        $this->assertSame(MyWorkView::Mine, $feed->resolveView($admin, null));
    }

    #[Test]
    public function the_handed_out_view_lists_only_what_the_reader_handed_out(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $employee = $this->employee();
        $this->handedOut($admin, $employee, 'Mine to follow', '2026-10-12 17:00:00');
        $this->handedOut($otherAdmin, $employee, 'Someone else handed this out', '2026-10-12 17:00:00');
        // Handed to oneself is the reader's own work, not something handed out.
        $this->handedOut($admin, $admin, 'Note to self', '2026-10-12 17:00:00');

        Livewire::actingAs($admin)
            ->withQueryParams(['view' => MyWorkView::Delegated->value])
            ->test(TasksDashboard::class)
            ->assertSee('Mine to follow')
            ->assertDontSee('Someone else handed this out')
            ->assertDontSee('Note to self');
    }

    #[Test]
    public function an_employee_is_offered_only_their_own_tasks_and_a_forged_view_falls_back(): void
    {
        $employee = $this->employee();
        $colleague = $this->employee();
        $this->taskAssignedTo($employee, TaskStatus::Pending, ['title' => 'My job', 'due_at' => '2026-10-12 17:00:00']);
        $this->taskAssignedTo($colleague, TaskStatus::Pending, ['title' => 'A colleague job', 'due_at' => '2026-10-12 17:00:00']);

        $feed = app(MyWorkFeed::class);
        $this->assertSame([MyWorkView::Mine], $feed->views($employee));

        foreach ([MyWorkView::Team->value, MyWorkView::Delegated->value, 'everything'] as $forged) {
            $this->assertSame(MyWorkView::Mine, $feed->resolveView($employee, $forged), "?view={$forged} must fall back to the reader's own tasks");

            Livewire::actingAs($employee)
                ->withQueryParams(['view' => $forged])
                ->test(TasksDashboard::class)
                ->assertSee('My job')
                ->assertDontSee('A colleague job')
                ->assertDontSee(MyWorkView::Team->getLabel());
        }
    }

    #[Test]
    public function the_team_view_stays_inside_the_readers_scope_and_its_rows_resolve_nothing_beyond(): void
    {
        $manager = $this->salesManager();
        $team = $this->makeTeam(manager: $manager);
        $manager->forceFill(['team_id' => $team->getKey()])->save();
        $member = $this->salesRep($team);
        $outsider = $this->salesRep();

        $inTeam = $this->taskAssignedTo($member, TaskStatus::Pending, ['title' => 'Team member task', 'due_at' => '2026-10-12 17:00:00']);
        $outside = $this->taskAssignedTo($outsider, TaskStatus::Pending, ['title' => 'Other team task', 'due_at' => '2026-10-12 17:00:00']);

        $feed = app(MyWorkFeed::class);
        $this->assertContains(MyWorkView::Team, $feed->views($manager));

        Livewire::actingAs($manager)
            ->withQueryParams(['view' => MyWorkView::Team->value])
            ->test(TasksDashboard::class)
            ->assertSee('Team member task')
            ->assertDontSee('Other team task');

        // A row action resolves its task from the browser's id inside the view's scope only.
        $this->assertTrue($feed->find($manager, $inTeam->getKey(), MyWorkView::Team)?->is($inTeam) ?? false);
        $this->assertNull($feed->find($manager, $outside->getKey(), MyWorkView::Team));
        $this->assertNull($feed->find($manager, $inTeam->getKey(), MyWorkView::Mine), 'not the manager\'s own task');
    }

    #[Test]
    public function in_the_team_view_an_administrator_completes_a_team_task_through_the_policy(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $task = $this->handedOut($admin, $employee, 'Ship the order', '2026-10-12 17:00:00');

        Livewire::actingAs($admin)
            ->withQueryParams(['view' => MyWorkView::Team->value])
            ->test(TasksDashboard::class)
            ->call('mountAction', 'quickComplete', ['task' => $task->getKey()])
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status, 'TaskPolicy lets the administrator complete it, through TaskService');

        // The employee, offered only their own tasks, never reaches a colleague's from a forged team view.
        $colleagueTask = $this->taskAssignedTo($this->employee(), TaskStatus::Pending, ['due_at' => '2026-10-12 17:00:00']);

        Livewire::actingAs($employee)
            ->withQueryParams(['view' => MyWorkView::Team->value])
            ->test(TasksDashboard::class)
            ->call('mountAction', 'quickComplete', ['task' => $colleagueTask->getKey()])
            ->assertActionNotMounted('quickComplete');

        $this->assertSame(TaskStatus::Pending, $colleagueTask->refresh()->status);
    }

    #[Test]
    public function the_task_list_opens_on_all_for_a_reader_without_open_work_of_their_own(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $this->handedOut($admin, $employee, 'Handed out', '2026-10-12 17:00:00');

        Livewire::actingAs($admin)->test(ListTasks::class)->assertSet('activeTab', 'all');

        $this->taskAssignedTo($admin, TaskStatus::Pending, ['title' => 'Own work', 'due_at' => '2026-10-13 12:00:00']);
        Livewire::actingAs($admin)->test(ListTasks::class)->assertSet('activeTab', 'my');

        // Someone who sees only their own tasks keeps «مهامي».
        Livewire::actingAs($employee)->test(ListTasks::class)->assertSet('activeTab', 'my');
    }

    #[Test]
    public function the_lists_handed_out_filter_matches_the_handed_out_view(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();
        $mine = $this->handedOut($admin, $employee, 'Handed out by me', '2026-10-12 17:00:00');
        $theirs = $this->handedOut($this->admin(), $employee, 'Handed out by another', '2026-10-12 17:00:00');

        Livewire::actingAs($admin)
            ->test(ListTasks::class, ['activeTab' => 'all'])
            ->filterTable('handed_out_by_me', true)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    /** A pending task the assigner handed out to the assignee (D-14: `assigned_by` is written directly by the services). */
    private function handedOut(User $assigner, User $assignee, string $title, string $due): Task
    {
        $task = $this->taskAssignedTo($assignee, TaskStatus::Pending, ['title' => $title, 'due_at' => $due, 'created_by' => $assigner->getKey()]);
        $task->forceFill(['assigned_by' => $assigner->getKey()])->save();

        return $task;
    }
}
