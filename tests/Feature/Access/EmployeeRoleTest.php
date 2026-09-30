<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\CrmRole;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Exceptions\Access\UnassignableUserException;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\TaskAttachmentsRelationManager;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\ActivityCountsWidget;
use App\Filament\Widgets\LeadsByStatusChart;
use App\Filament\Widgets\MyTasksTodayWidget;
use App\Filament\Widgets\PipelineByStageChart;
use App\Filament\Widgets\RevenueWonByMonthChart;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\StaleDealsWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\Attachments\AttachmentStorage;
use App\Services\Statistics\DashboardFilters;
use App\Services\Tasks\TaskService;
use App\Support\Access\RolePermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsUploadBytes;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Decision D-15 (2026-09-28): the Employee role, for staff who receive tasks
 * and report on them. Own tasks at own scope, personal to-dos, the shared
 * tasks board and the calendar, the dashboard's task widgets, and files on
 * the tasks they may open — no sales record, no assignment, no export. A
 * task an administrator hands them stays the administrator's (D-14
 * amendment): they start it, post updates and complete it, and never edit,
 * cancel or delete it.
 */
final class EmployeeRoleTest extends TestCase
{
    use BuildsUploadBytes;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-09-28 09:00:00'));
        config()->set('mail.default', 'log');

        Storage::fake($this->diskName());

        $this->employee = $this->employee();
        $this->admin = $this->admin();
    }

    #[Test]
    public function the_role_holds_exactly_its_task_and_attachment_permissions(): void
    {
        $expected = ['attachment.create', 'attachment.download', 'task.create', 'task.delete', 'task.update', 'task.view_any'];
        $matrix = array_map(static fn (Permission $permission): string => $permission->value, RolePermissionMatrix::for(CrmRole::Employee));
        sort($matrix);

        $this->assertSame($expected, $matrix);
        $this->assertSame($expected, $this->employee->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertFalse(CrmRole::Employee->isLocked(), 'a super admin may still adjust the role (D-3)');
        $this->assertSame(__('enums.roles.employee'), CrmRole::Employee->label());
        $this->assertSame('Employee', __('enums.roles.employee', [], 'en'));
        $this->assertSame('موظف', __('enums.roles.employee', [], 'ar'));
    }

    #[Test]
    public function the_list_shows_only_their_own_tasks_while_the_board_shows_everyones(): void
    {
        $own = app(TaskService::class)->create(['title' => 'Prepare the stock sheet'], $this->employee);
        $colleague = $this->employee();
        $foreign = app(TaskService::class)->create(['title' => 'Colleague archive run', 'assignee_id' => $colleague->getKey()], $this->admin);

        Livewire::actingAs($this->employee)
            ->test(ListTasks::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->assertFalse($this->employee->can('view', $foreign));
        $this->actingAs($this->employee)->get(TaskResource::getUrl('view', ['record' => $foreign]))->assertNotFound();

        Livewire::actingAs($this->employee)
            ->test(TasksBoard::class)
            ->assertOk()
            ->assertSee('Prepare the stock sheet')
            ->assertSee('Colleague archive run')
            ->assertSeeHtml('href="'.TaskResource::getUrl('view', ['record' => $own]).'"')
            ->assertDontSeeHtml('href="'.TaskResource::getUrl('view', ['record' => $foreign]).'"');
    }

    #[Test]
    public function they_create_a_to_do_for_themselves_and_cannot_assign_one_to_anyone_else(): void
    {
        $lead = Lead::factory()->create(['owner_id' => $this->admin->getKey()]);

        Livewire::actingAs($this->employee)
            ->test(CreateTask::class)
            ->assertFormFieldHidden('owner_id')
            ->assertFormFieldHidden('lead_id')
            ->assertFormFieldHidden('deal_id')
            // A forged link to a lead they cannot read is never submitted: the picker is not there.
            ->fillForm(['title' => 'Renew my badge', 'lead_id' => $lead->getKey()])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->where('title', 'Renew my badge')->sole();

        $this->assertSame($this->employee->getKey(), (int) $task->assignee_id);
        $this->assertNull($task->lead_id);
        $this->assertFalse($task->isHandedOutTo($this->employee));

        $this->expectException(UnassignableUserException::class);

        try {
            app(TaskService::class)->create(['title' => 'For the admin', 'assignee_id' => $this->admin->getKey()], $this->employee);
        } finally {
            $this->assertSame(0, Task::query()->where('title', 'For the admin')->count());
        }
    }

    #[Test]
    public function their_own_to_do_stays_fully_theirs(): void
    {
        $own = app(TaskService::class)->create(['title' => 'Book the meeting room'], $this->employee);

        foreach (['view', 'update', 'cancel', 'delete', 'progress', 'start', 'postUpdate', 'complete', 'comment'] as $ability) {
            $this->assertTrue($this->employee->can($ability, $own), "own to-do: {$ability}");
        }

        $this->assertFalse($this->employee->can('assign', $own));
        $this->actingAs($this->employee)->get(TaskResource::getUrl('edit', ['record' => $own]))->assertOk();
    }

    #[Test]
    public function on_a_task_an_admin_handed_them_they_report_progress_and_never_edit_cancel_or_delete(): void
    {
        $task = $this->handedOut();

        foreach (['view', 'progress', 'start', 'postUpdate', 'complete', 'comment'] as $ability) {
            $this->assertTrue($this->employee->can($ability, $task), "handed-out task: {$ability}");
        }

        foreach (['update', 'cancel', 'delete', 'restore', 'assign'] as $ability) {
            $this->assertFalse($this->employee->can($ability, $task), "handed-out task: {$ability} is the assigner's");
        }

        $this->actingAs($this->employee)->get(TaskResource::getUrl('edit', ['record' => $task]))->assertForbidden();

        Livewire::actingAs($this->employee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('edit')
            ->assertActionHidden('cancel')
            ->assertActionHidden('delete')
            ->assertActionHidden('assign')
            ->callAction('start', data: ['start_note' => 'Starting now'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.started'));

        $this->assertSame(TaskStatus::InProgress, $task->refresh()->status);

        Livewire::actingAs($this->employee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('postUpdate', data: ['body' => 'Half of the shelves counted'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.update_posted'));

        Livewire::actingAs($this->employee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('complete', data: ['completion_note' => 'Counted and filed'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('tasks.notifications.completed'));

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(3, $task->updates()->count(), 'start, update and completion are in the progress log');

        // A forged cancel on the reopened task is refused by the policy, not by a hidden button.
        app(TaskService::class)->reopen($task, $this->admin);

        Livewire::actingAs($this->employee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->call('mountAction', 'cancel')
            ->assertActionNotMounted('cancel');

        $this->assertNotSame(TaskStatus::Cancelled, $task->refresh()->status);
    }

    #[Test]
    public function the_dashboard_shows_the_task_widgets_and_none_of_the_sales_widgets_or_filters(): void
    {
        $this->actingAs($this->employee)->get(Dashboard::getUrl())->assertOk();

        foreach ([MyTasksTodayWidget::class, UpcomingFollowUpsWidget::class] as $widget) {
            $this->assertTrue($widget::canView(), "{$widget} hides from the employee");
        }

        foreach ([SalesKpisWidget::class, LeadsByStatusChart::class, PipelineByStageChart::class, RevenueWonByMonthChart::class, StaleDealsWidget::class, ActivityCountsWidget::class] as $widget) {
            $this->assertFalse($widget::canView(), "{$widget} shows to the employee");
        }

        // The task lists run on fixed windows, so the period / owner / pipeline filters would change nothing.
        $range = __('dashboard.helpers.range', ['days' => DashboardFilters::MAX_RANGE_DAYS]);

        Livewire::actingAs($this->employee)
            ->test(Dashboard::class)
            ->assertActionHidden('resetFilters')
            ->assertDontSee($range);

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertActionVisible('resetFilters')
            ->assertSee($range);
    }

    #[Test]
    public function a_handed_out_task_linked_to_a_lead_they_cannot_view_renders_without_a_link_to_it(): void
    {
        $lead = Lead::factory()->create(['owner_id' => $this->admin->getKey(), 'first_name' => 'Hidden', 'last_name' => 'Prospect']);
        $task = $this->handedOut(['lead_id' => $lead->getKey()]);
        $leadUrl = LeadResource::getUrl('view', ['record' => $lead]);

        $this->assertFalse($this->employee->can('view', $lead), 'precondition: the employee reads no lead');

        $this->actingAs($this->employee)->get(TaskResource::getUrl('view', ['record' => $task]))->assertOk();

        Livewire::actingAs($this->employee)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertOk()
            ->assertDontSeeHtml($leadUrl);

        Livewire::actingAs($this->employee)
            ->test(ListTasks::class)
            ->assertCanSeeTableRecords([$task])
            ->assertDontSeeHtml($leadUrl);

        Livewire::actingAs($this->employee)
            ->test(TasksBoard::class)
            ->assertSee('Count the warehouse stock')
            ->assertDontSeeHtml($leadUrl);

        // The administrator, who may open the lead, gets the link.
        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSeeHtml($leadUrl);

        // The lead page itself does not even confirm the lead exists.
        $this->actingAs($this->employee)->get($leadUrl)->assertNotFound();
    }

    #[Test]
    public function they_attach_and_download_files_on_their_tasks_and_never_on_a_lead(): void
    {
        $task = $this->handedOut();
        $path = "tmp/{$this->employee->getKey()}/count-sheet.pdf";
        Storage::disk($this->diskName())->put($path, $this->pdfBytes());

        Livewire::actingAs($this->employee)
            ->test(TaskAttachmentsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => ViewTask::class])
            ->assertTableActionVisible('upload')
            ->callTableAction('upload', data: ['file' => ['pending' => $path], 'original_name' => 'count-sheet.pdf'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('attachments.notifications.uploaded'));

        $mine = Attachment::query()->sole();

        $this->assertTrue($mine->attachable->is($task));
        $this->assertSame(sprintf('crm/task/%d/%s.pdf', $task->getKey(), $mine->uuid), $mine->path);
        $this->assertTrue($this->employee->can('download', $mine));
        $this->assertFalse($this->employee->can('delete', $mine), 'D-15 grants no attachment.delete');
        $this->actingAs($this->employee)->get($mine->downloadUrl())->assertOk();

        // A file on a lead: refused on every path, as is uploading to the lead.
        $lead = Lead::factory()->create(['owner_id' => $this->admin->getKey()]);
        $leadFile = "tmp/{$this->admin->getKey()}/proposal.pdf";
        Storage::disk($this->diskName())->put($leadFile, $this->pdfBytes());
        $onLead = app(AttachmentStorage::class)->store($leadFile, $lead, $this->admin);

        $this->assertFalse($this->employee->can('download', $onLead));
        $this->assertFalse($this->employee->can('create', [Attachment::class, $lead]));
        $this->actingAs($this->employee)->get($onLead->downloadUrl())->assertForbidden();

        // Another employee's task and its files stay out of reach too.
        $colleague = $this->employee();
        $theirTask = app(TaskService::class)->create(['title' => 'Colleague task', 'assignee_id' => $colleague->getKey()], $this->admin);
        $theirFile = "tmp/{$this->admin->getKey()}/their-sheet.pdf";
        Storage::disk($this->diskName())->put($theirFile, $this->pdfBytes());
        $onTheirTask = app(AttachmentStorage::class)->store($theirFile, $theirTask, $this->admin);

        $this->assertFalse($this->employee->can('create', [Attachment::class, $theirTask]));
        $this->actingAs($this->employee)->get($onTheirTask->downloadUrl())->assertForbidden();
    }

    /**
     * A task the administrator handed to the employee.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function handedOut(array $attributes = []): Task
    {
        $task = app(TaskService::class)->create(['title' => 'Count the warehouse stock', 'assignee_id' => $this->employee->getKey(), ...$attributes], $this->admin);

        $this->assertTrue($task->isHandedOutTo($this->employee), 'precondition: the admin handed the task out');

        return $task;
    }

    private function diskName(): string
    {
        return (string) config('crm.attachments.disk');
    }
}
