<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\CrmRole;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * The shared tasks board (decision D-14): a deliberately organisation-wide,
 * read-only page. Every holder of `task.view_any` — every seeded role, the
 * employee (D-15) included —
 * reaches it and sees every user's open tasks and assignees, own/team
 * visibility notwithstanding; a card links to its task page only where the
 * viewer's ordinary `view` policy allows.
 */
final class TasksBoardPageTest extends TestCase
{
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const int TOLERANCE = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-21 10:00:00'));
    }

    #[Test]
    public function every_seeded_role_reaches_the_board_and_a_user_without_task_view_any_does_not(): void
    {
        foreach (CrmRole::cases() as $role) {
            $this->actingAs($this->makeUser($role))->get(TasksBoard::getUrl())->assertOk();
        }

        $stranger = $this->userWithPermissions(null, []);

        $this->actingAs($stranger)->get(TasksBoard::getUrl())->assertForbidden();
    }

    #[Test]
    public function a_rep_sees_another_reps_task_and_its_assignee_on_the_board(): void
    {
        // D-14's whole point: the board is the one page-scoped exception to
        // D-4's own/team visibility, so a rep sees a foreign rep's workload.
        $viewer = $this->salesRep($this->makeTeam());
        $other = $this->makeUser(CrmRole::SalesRep, ['name' => 'Basma Outsider'], $this->makeTeam('Jeddah Team', 'فريق جدة'));
        $foreign = Task::factory()->create(['assignee_id' => $other->getKey(), 'title' => 'Call the Jeddah client']);
        Task::factory()->unassigned()->create(['title' => 'Nobody owns this yet']);

        $this->assertFalse($viewer->can('view', $foreign), 'precondition: the resolver hides this task from the viewer everywhere else');

        Livewire::actingAs($viewer)
            ->test(TasksBoard::class)
            ->assertOk()
            ->assertSee('Call the Jeddah client')
            ->assertSee('Basma Outsider')
            ->assertSee('Nobody owns this yet')
            ->assertSee(__('tasks.pages.board.unassigned'));
    }

    #[Test]
    public function a_deleted_users_open_tasks_keep_their_own_column_marked_as_a_deleted_account(): void
    {
        // A soft delete leaves tasks.assignee_id in place, so those tasks are
        // still that user's — they must not pose as a second «unassigned»
        // column (with a duplicate Livewire key) beside the real one.
        $gone = $this->makeUser(CrmRole::SalesRep, ['name' => 'Departed Rep']);
        Task::factory()->create(['assignee_id' => $gone->getKey(), 'title' => 'Left behind']);
        Task::factory()->unassigned()->create(['title' => 'Nobody owns this yet']);
        $gone->delete();

        $board = Livewire::actingAs($this->admin())->test(TasksBoard::class);

        $board->assertOk()
            ->assertSee('Left behind')
            ->assertSee(__('tasks.pages.board.deleted_assignee', ['name' => 'Departed Rep']));

        $html = $board->html();

        $this->assertSame(1, substr_count($html, 'wire:key="assignee-none"'), 'exactly one unassigned column');
        $this->assertSame(1, substr_count($html, 'wire:key="assignee-'.$gone->getKey().'"'), 'the deleted user keeps their own column');
    }

    #[Test]
    public function a_card_links_to_the_task_only_when_the_viewer_may_view_it(): void
    {
        $viewer = $this->salesRep($this->makeTeam());
        $other = $this->salesRep($this->makeTeam('Jeddah Team', 'فريق جدة'));
        $mine = Task::factory()->create(['assignee_id' => $viewer->getKey(), 'title' => 'My own call']);
        $foreign = Task::factory()->create(['assignee_id' => $other->getKey(), 'title' => 'Foreign follow-up']);

        Livewire::actingAs($viewer)
            ->test(TasksBoard::class)
            ->assertOk()
            ->assertSee('My own call')
            ->assertSee('Foreign follow-up')
            ->assertSeeHtml('href="'.TaskResource::getUrl('view', ['record' => $mine]).'"')
            ->assertDontSeeHtml('href="'.TaskResource::getUrl('view', ['record' => $foreign]).'"');

        // An admin reads everything, so both cards link.
        Livewire::actingAs($this->admin())
            ->test(TasksBoard::class)
            ->assertSeeHtml('href="'.TaskResource::getUrl('view', ['record' => $mine]).'"')
            ->assertSeeHtml('href="'.TaskResource::getUrl('view', ['record' => $foreign]).'"');
    }

    #[Test]
    public function the_board_shows_stats_the_more_counter_and_the_empty_state(): void
    {
        $rep = $this->salesRep();

        Livewire::actingAs($rep)
            ->test(TasksBoard::class)
            ->assertOk()
            ->assertSee(__('tasks.pages.board.empty'))
            ->assertSee(__('tasks.pages.board.stats.open'))
            ->assertSee(__('tasks.pages.board.stats.completed_this_week'));

        $busy = $this->makeUser(CrmRole::SalesRep, ['name' => 'Busy Rep']);
        Task::factory()->count(11)->create(['assignee_id' => $busy->getKey()]);

        Livewire::actingAs($rep)
            ->test(TasksBoard::class)
            ->assertDontSee(__('tasks.pages.board.empty'))
            ->assertSee('Busy Rep')
            // 11 open tasks, 8 cards: the counter names the 3 hidden ones.
            ->assertSee(trans_choice('tasks.pages.board.more', 3, ['count' => 3]));
    }

    #[Test]
    public function the_board_issues_no_per_task_queries(): void
    {
        $viewer = $this->salesRep($this->makeTeam());
        $assignees = [
            $this->makeUser(CrmRole::SalesRep, ['name' => 'Rep A']),
            $this->makeUser(CrmRole::SalesRep, ['name' => 'Rep B']),
        ];

        foreach ($assignees as $assignee) {
            Task::factory()->count(2)->create(['assignee_id' => $assignee->getKey()]);
        }

        $this->renderBoard($viewer);

        $small = $this->queriesDuring(fn () => $this->renderBoard($viewer));

        foreach ($assignees as $assignee) {
            Task::factory()->count(5)->create(['assignee_id' => $assignee->getKey()]);
        }

        foreach (['Rep C', 'Rep D', 'Rep E'] as $name) {
            Task::factory()->count(4)->create(['assignee_id' => $this->makeUser(CrmRole::SalesRep, ['name' => $name])->getKey()]);
        }

        $large = $this->queriesDuring(fn () => $this->renderBoard($viewer));

        $this->assertLessThanOrEqual(
            count($small) + self::TOLERANCE,
            count($large),
            sprintf("TasksBoard: %d queries for 4 cards, %d for 26 cards. Most repeated:\n%s", count($small), count($large), $this->topRepeated($large)),
        );
    }

    private function renderBoard(User $viewer): void
    {
        Livewire::actingAs($viewer)->test(TasksBoard::class)->assertOk();
    }
}
