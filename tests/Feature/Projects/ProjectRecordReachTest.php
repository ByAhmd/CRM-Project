<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\CreateActivity;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Services\Activities\TimelineEntry;
use App\Services\Activities\TimelineReader;
use App\Services\Tasks\TaskBoardFeed;
use App\Support\RecordLabel;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * What follows a record rather than a list is never narrowed by the project
 * switcher (decision D-30): a task or an activity about a record of another
 * project stays readable and reachable from every link, the Tasks workspace's
 * pickers offer and keep records of every project, and the record pages'
 * tabs list the record's own children — while the Sales lists keep showing
 * the current project only, the activities list included.
 */
final class ProjectRecordReachTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private Project $default;

    private Project $iqan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $this->default = $this->defaultProject();
        $this->iqan = $this->makeProject();
    }

    #[Test]
    public function a_task_about_a_record_of_another_project_stays_readable_from_the_tasks_workspace(): void
    {
        // A team-level manager working in the default project; an employee outside the team holds a task
        // about an «إيقان» deal of the manager's team — the deal is the task's only tie to the manager.
        $team = $this->makeTeam();
        $manager = $this->salesManager($team);
        $rep = $this->salesRep($team);
        $employee = $this->employee();
        $this->useProject($manager, $this->default);

        $deal = Deal::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $rep->getKey(), 'title' => 'Iqan rollout']);
        $task = Task::factory()->create(['assignee_id' => $employee->getKey(), 'deal_id' => $deal->getKey(), 'title' => 'Prepare the rollout']);

        $this->assertTrue($manager->can('view', $task), 'precondition: the policy reads the task through its deal');

        Livewire::actingAs($manager)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$task]);

        $this->actingAs($manager)->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk()->assertSee('Prepare the rollout');

        Livewire::actingAs($manager)
            ->test(TasksBoard::class)
            ->set('boardView', TasksBoard::VIEW_STATUS)
            ->assertSee('Prepare the rollout');

        $this->actingAs($manager);
        $onBoard = collect(app(TaskBoardFeed::class)->byStatus(TaskResource::getEloquentQuery()))
            ->flatMap(static fn (array $column): array => array_map(static fn (Task $task): int => (int) $task->getKey(), $column['tasks']))
            ->all();
        $this->assertContains($task->getKey(), $onBoard);

        // The Sales deals list keeps its rule: the «إيقان» deal is not listed while the default project is current.
        Livewire::actingAs($manager)
            ->test(ListDeals::class)
            ->set('activeTab', 'all')
            ->assertCanNotSeeTableRecords([$deal]);
    }

    #[Test]
    public function the_task_form_keeps_a_link_into_another_project_and_the_tasks_pickers_search_every_project(): void
    {
        $admin = $this->admin();
        $this->useProject($admin, $this->default);
        $iqanLead = Lead::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $admin->getKey(), 'first_name' => 'Iqan', 'last_name' => 'Prospect']);
        $defaultLead = Lead::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $admin->getKey(), 'first_name' => 'Main', 'last_name' => 'Prospect']);
        $task = Task::factory()->create(['assignee_id' => $admin->getKey(), 'lead_id' => $iqanLead->getKey(), 'title' => 'Call back']);

        $form = Livewire::actingAs($admin)->test(EditTask::class, ['record' => $task->getRouteKey()]);

        // The stored link keeps its name, and the search offers both projects (the Tasks workspace is not partitioned).
        $form->assertFormFieldExists('lead_id', function (Select $select) use ($iqanLead, $defaultLead): bool {
            $this->assertSame(RecordLabel::of($iqanLead), $select->getOptionLabel());
            $this->assertEqualsCanonicalizing([$iqanLead->getKey(), $defaultLead->getKey()], array_map('intval', array_keys($select->getSearchResults('Prospect'))));

            return true;
        });

        $form
            ->fillForm(['title' => 'Call back today'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $task->refresh();
        $this->assertSame('Call back today', $fresh->title);
        $this->assertSame($iqanLead->getKey(), (int) $fresh->lead_id, 'the link into «إيقان» survived the edit');

        // The Sales activity page is the current project's page: its search offers the default project only.
        Livewire::actingAs($admin)
            ->test(CreateActivity::class)
            ->assertFormFieldExists('lead_id', function (Select $select) use ($defaultLead): bool {
                $this->assertSame([$defaultLead->getKey()], array_map('intval', array_keys($select->getSearchResults('Prospect'))));

                return true;
            });
    }

    #[Test]
    public function an_activity_on_a_record_of_another_project_opens_from_its_link_while_the_sales_list_shows_the_current_project(): void
    {
        // A rep at own level; a support user's activity on the rep's «إيقان» lead is readable through the lead only.
        $rep = $this->salesRep();
        $support = $this->support();
        $this->useProject($rep, $this->default);
        $iqanLead = Lead::factory()->create(['project_id' => $this->iqan->getKey(), 'owner_id' => $rep->getKey()]);
        $defaultLead = Lead::factory()->create(['project_id' => $this->default->getKey(), 'owner_id' => $rep->getKey()]);
        $iqanActivity = Activity::factory()->create(['lead_id' => $iqanLead->getKey(), 'owner_id' => $support->getKey(), 'created_by' => $support->getKey(), 'subject' => 'Iqan site visit']);
        $defaultActivity = Activity::factory()->create(['lead_id' => $defaultLead->getKey(), 'owner_id' => $rep->getKey(), 'created_by' => $rep->getKey(), 'subject' => 'Main call']);

        $this->assertTrue($rep->can('view', $iqanActivity), 'precondition: the policy reads the activity through its lead');

        // The page opens from any link whatever project is current, and the lead's timeline links it.
        $this->actingAs($rep)->get(ActivityResource::getUrl('view', ['record' => $iqanActivity]))->assertOk()->assertSee('Iqan site visit');

        $entry = app(TimelineReader::class)->for($iqanLead, $rep)->first(static fn (TimelineEntry $entry): bool => $entry->sourceType === 'activity' && $entry->sourceId === (int) $iqanActivity->getKey());
        $this->assertInstanceOf(TimelineEntry::class, $entry);
        $this->assertSame(ActivityResource::getUrl('view', ['record' => $iqanActivity]), $entry->url);

        // The readable set the Tasks workspace calendar reads holds both; the Sales list shows the current project only.
        $this->assertEqualsCanonicalizing(
            [$iqanActivity->getKey(), $defaultActivity->getKey()],
            array_map('intval', ActivityResource::getEloquentQuery()->pluck('activities.id')->all()),
        );

        Livewire::actingAs($rep)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$defaultActivity])
            ->assertCanNotSeeTableRecords([$iqanActivity]);

        $this->useProject($rep, $this->iqan);

        Livewire::actingAs($rep)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$iqanActivity])
            ->assertCanNotSeeTableRecords([$defaultActivity]);

        // The same rule at the `all` level: the list follows the current project, not the reach.
        $admin = $this->admin();
        $this->useProject($admin, $this->default);

        Livewire::actingAs($admin)
            ->test(ListActivities::class)
            ->assertCanSeeTableRecords([$defaultActivity])
            ->assertCanNotSeeTableRecords([$iqanActivity]);

        $this->actingAs($admin)->get(ActivityResource::getUrl('view', ['record' => $iqanActivity]))->assertOk();
    }
}
