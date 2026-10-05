<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Filament\Exports\TaskExporter;
use App\Filament\Pages\Calendar;
use App\Filament\Pages\TasksBoard;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Filament\Support\ImportExportActions;
use App\Models\Deal;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\Tasks\TaskChecklist;
use App\Services\Tasks\TaskService;
use Closure;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Callout;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRoutineTasks;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Feature\QualityPass\Performance\Concerns\CountsQueries;
use Tests\TestCase;

/**
 * Decision D-25 on the screens: the task form writes the checklist through
 * TaskService on every path it is used — the edit page, the relation
 * manager's modal and the calendar's modal all load the stored items, so a
 * save never wipes them — the Complete dialog (single and bulk) says how
 * many items are not done, in both languages, and the progress shows as
 * done/total on the task page, in the task list, on the tasks-board card
 * and in the export, without a query per row.
 */
final class TaskChecklistScreensTest extends TestCase
{
    use BuildsRoutineTasks;
    use CountsQueries;
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
        config()->set('mail.default', 'log');

        $this->admin = $this->admin();
        $this->rep = $this->salesRep();
        $this->tasks = app(TaskService::class);
    }

    // --- Every fill path of the task form keeps the checklist ---

    #[Test]
    public function the_create_page_writes_the_checklist_through_the_service(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateTask::class)
            ->fillForm([
                'title' => 'Prepare the visit',
                'owner_id' => $this->rep->getKey(),
                'checklist' => [['id' => null, 'body' => 'Print the brochure'], ['id' => null, 'body' => 'Book the car']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->where('title', 'Prepare the visit')->firstOrFail();
        $this->assertSame(['Print the brochure', 'Book the car'], TaskChecklist::bodies($task));
    }

    #[Test]
    public function the_edit_page_loads_the_items_so_a_save_keeps_them_and_their_ticks(): void
    {
        $task = $this->withChecklist(['One', 'Two']);
        [$one] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey()]);

        Livewire::actingAs($this->admin)
            ->test(EditTask::class, ['record' => $task->getKey()])
            ->assertFormSet(['title' => 'Prepare the visit'])
            ->fillForm(['title' => 'Prepare the visit, renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Prepare the visit, renamed', $task->refresh()->title);
        $this->assertSame(['One', 'Two'], TaskChecklist::bodies($task));
        $this->assertNotNull($one->refresh()->completed_at, 'the tick survived the save');
    }

    #[Test]
    public function the_relation_managers_edit_modal_loads_the_items_so_a_save_keeps_them(): void
    {
        $deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
        $task = $this->withChecklist(['Dial', 'Log the call'], ['deal_id' => $deal->getKey()]);

        Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->callTableAction('edit', $task, data: ['title' => 'Call the buyer, renamed'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Call the buyer, renamed', $task->refresh()->title);
        $this->assertSame(['Dial', 'Log the call'], TaskChecklist::bodies($task));
    }

    #[Test]
    public function the_calendars_edit_modal_loads_the_items_so_a_save_keeps_them(): void
    {
        $task = $this->withChecklist(['Agenda', 'Minutes'], ['due_at' => '2026-10-07 10:00:00']);

        Livewire::actingAs($this->admin)
            ->test(Calendar::class)
            ->mountAction('editTask', ['task' => $task->getKey()])
            ->assertActionMounted('editTask')
            ->fillForm(['title' => 'Weekly meeting'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('Weekly meeting', $task->refresh()->title);
        $this->assertSame(['Agenda', 'Minutes'], TaskChecklist::bodies($task));
    }

    #[Test]
    public function the_edit_page_shows_a_closed_tasks_checklist_frozen_and_saves_its_details(): void
    {
        $task = $this->withChecklist(['One']);
        $this->tasks->complete($task, $this->rep);

        Livewire::actingAs($this->admin)
            ->test(EditTask::class, ['record' => $task->getKey()])
            ->assertSee(__('tasks.helpers.checklist_frozen'))
            ->assertFormFieldDisabled('checklist')
            ->fillForm(['title' => 'Closed, renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Closed, renamed', $task->refresh()->title);
        $this->assertSame(['One'], TaskChecklist::bodies($task));
    }

    #[Test]
    public function a_checklist_change_sent_after_the_task_closed_is_never_written(): void
    {
        $task = $this->withChecklist(['One']);

        $form = Livewire::actingAs($this->admin)->test(EditTask::class, ['record' => $task->getKey()]);

        // The assignee completes the task while the form is open.
        $this->tasks->complete($task, $this->rep);

        $form->fillForm(['checklist' => [['id' => null, 'body' => 'Sneaked in']], 'title' => 'Renamed late'])
            ->call('save');

        $this->assertSame(['One'], TaskChecklist::bodies($task), 'the frozen checklist was not touched');
        $this->assertSame('Renamed late', $task->refresh()->title, 'the other details still save');
    }

    #[Test]
    public function a_refused_checklist_halts_the_save_with_a_notice(): void
    {
        $task = $this->withChecklist(['One']);

        Livewire::actingAs($this->admin)
            ->test(EditTask::class, ['record' => $task->getKey()])
            ->fillForm(['title' => 'Too long a step', 'checklist' => [['id' => null, 'body' => str_repeat('x', TaskChecklist::BODY_MAX + 1)]]])
            ->call('save')
            ->assertHasFormErrors(['checklist.0.body' => 'max']);

        // A request the form's rules would not let through reaches the service's own refusal.
        $this->expectException(Halt::class);

        TaskForm::saving(fn (): Task => $this->tasks->update($task, ['checklist' => array_fill(0, TaskChecklist::MAX_ITEMS + 1, 'Step')], $this->admin));
    }

    #[Test]
    public function the_routine_edit_page_loads_its_checklist_so_a_save_keeps_it(): void
    {
        $this->travelToRiyadh('2026-10-04 08:00');
        $routine = $this->routine($this->admin, ['checklist' => ['Count', 'Report']], $this->rep);

        Livewire::actingAs($this->admin)
            ->test(EditTaskTemplate::class, ['record' => $routine->getKey()])
            ->assertFormSet(['title' => 'Weekly stock count'])
            ->fillForm(['title' => 'Weekly stock count, renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Count', 'Report'], TaskChecklist::templateBodies($routine));
    }

    // --- The Complete warnings ---

    #[Test]
    public function the_complete_dialog_warns_how_many_items_are_not_done_in_both_languages(): void
    {
        $task = $this->withChecklist(['One', 'Two', 'Three', 'Four', 'Five']);
        [$one, $two, $three] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey(), $two->getKey(), $three->getKey()]);

        // The modal renders lazily, so the warning is read off the mounted schema.
        app()->setLocale('ar');
        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->mountAction('complete')
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('لم يُنجز 2 من 5 بنود في قائمة التحقق.'));

        app()->setLocale('en');
        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->mountAction('complete')
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('Not done: 2 of 5 checklist items.'))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status, 'the warning does not block completion');
    }

    #[Test]
    public function the_complete_dialog_says_nothing_when_every_item_is_ticked_or_there_is_no_checklist(): void
    {
        app()->setLocale('en');
        $task = $this->withChecklist(['Only']);
        [$only] = $this->items($task);

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->mountAction('complete')
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('The only checklist item is not done.'));

        $this->tasks->tickChecklist($task, $this->rep, [$only->getKey()]);

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->mountAction('complete')
            ->assertSchemaComponentHidden('checklistWarning');

        $plain = $this->tasks->create(['title' => 'Plain', 'assignee_id' => $this->rep->getKey()], $this->admin);

        Livewire::actingAs($this->rep)
            ->test(ViewTask::class, ['record' => $plain->getKey()])
            ->mountAction('complete')
            ->assertSchemaComponentHidden('checklistWarning');
    }

    #[Test]
    public function the_row_complete_of_the_task_list_and_of_a_tasks_relation_manager_carries_the_warning(): void
    {
        // The table rows carry the withCount aliases, so this is the path the
        // counts reach differently from the task page.
        $deal = Deal::factory()->create(['owner_id' => $this->rep->getKey()]);
        $task = $this->withChecklist(['One', 'Two', 'Three'], ['deal_id' => $deal->getKey()]);
        [$one, $two] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey(), $two->getKey()]);

        app()->setLocale('en');
        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->mountAction(TestAction::make('complete')->table($task))
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('Not done: 1 of 3 checklist items.'));

        Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->mountAction(TestAction::make('complete')->table($task))
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('Not done: 1 of 3 checklist items.'));

        app()->setLocale('ar');
        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->mountAction(TestAction::make('complete')->table($task))
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('لم يُنجز 1 من 3 بنود في قائمة التحقق.'));
    }

    #[Test]
    public function the_calendar_footer_complete_carries_the_warning(): void
    {
        app()->setLocale('en');
        $task = $this->withChecklist(['One', 'Two'], ['due_at' => '2026-10-07 10:00:00']);

        Livewire::actingAs($this->admin)
            ->test(Calendar::class)
            ->mountAction([TestAction::make('editTask')->arguments(['task' => $task->getKey()]), 'complete'])
            ->assertSchemaComponentExists('checklistWarning', checkComponentUsing: self::warning('Not done: 2 of 2 checklist items.'));
    }

    #[Test]
    public function the_bulk_complete_dialog_warns_over_the_open_tasks_selected_in_both_languages(): void
    {
        $first = $this->withChecklist(['A', 'B', 'C']);
        $second = $this->withChecklist(['D', 'E']);
        [$a] = $this->items($first);
        $this->tasks->tickChecklist($first, $this->rep, [$a->getKey()]);
        $closed = $this->withChecklist(['Closed one']);
        $this->tasks->complete($closed, $this->rep);
        $plain = $this->tasks->create(['title' => 'Plain', 'assignee_id' => $this->rep->getKey()], $this->admin);

        app()->setLocale('en');
        $english = Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->selectTableRecords([$first->getKey(), $second->getKey(), $closed->getKey()])
            ->mountAction(TestAction::make('complete')->table()->bulk());

        $this->assertSame(
            'Not done: 4 of the 5 checklist items on the selected tasks. '.__('tasks.helpers.checklist_complete_anyway'),
            $this->bulkDescription($english),
            'the closed task\'s item is not counted',
        );

        app()->setLocale('ar');
        $arabic = Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->selectTableRecords([$first->getKey(), $second->getKey()])
            ->mountAction(TestAction::make('complete')->table()->bulk());

        $this->assertStringStartsWith('لم يُنجز 4 من 5 بنود في قوائم تحقق المهام المحددة.', $this->bulkDescription($arabic));

        $arabic->callMountedAction();

        $this->assertSame(TaskStatus::Completed, $first->refresh()->status);
        $this->assertSame(TaskStatus::Completed, $second->refresh()->status);

        $none = Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->selectTableRecords([$plain->getKey()])
            ->mountAction(TestAction::make('complete')->table()->bulk());

        $this->assertStringNotContainsString(
            __('tasks.helpers.checklist_complete_anyway'),
            $this->bulkDescription($none),
            'no checklist, no warning: the plain confirmation stays',
        );
    }

    // --- Progress on the task page, the list, the board and the export ---

    #[Test]
    public function the_task_page_lists_the_items_with_who_ticked_them_without_lazy_loading(): void
    {
        app()->setLocale('en');
        $task = $this->withChecklist(['Print the brochure', 'Book the <b>car</b>']);
        [$print] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$print->getKey()]);

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertOk()
            ->assertSee(__('tasks.sections.checklist'))
            ->assertSee('1/2')
            ->assertSee('Print the brochure')
            ->assertSee('Book the <b>car</b>')
            ->assertDontSeeHtml('<b>car</b>')
            ->assertSee(__('tasks.fields.checklist_ticked', ['name' => $this->rep->name, 'date' => '2026-10-05 09:00']));
    }

    #[Test]
    public function a_task_without_a_checklist_shows_no_checklist_section(): void
    {
        $task = $this->tasks->create(['title' => 'Plain', 'assignee_id' => $this->rep->getKey()], $this->admin);

        Livewire::actingAs($this->admin)
            ->test(ViewTask::class, ['record' => $task->getKey()])
            ->assertActionHidden('checklist')
            ->assertDontSee(__('tasks.sections.checklist'));
    }

    #[Test]
    public function the_task_list_shows_done_over_total_without_a_query_per_row(): void
    {
        $task = $this->withChecklist(['One', 'Two', 'Three']);
        [$one] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey()]);

        Livewire::actingAs($this->rep)
            ->test(ListTasks::class)
            ->assertTableColumnStateSet('checklist_progress', '1/3', $task);

        $small = $this->queriesDuring(fn () => Livewire::actingAs($this->rep)->test(ListTasks::class)->assertOk());

        for ($i = 0; $i < 6; $i++) {
            $this->withChecklist(['A', 'B']);
        }

        $large = $this->queriesDuring(fn () => Livewire::actingAs($this->rep)->test(ListTasks::class)->assertOk());

        $this->assertSame(
            [],
            array_values(array_diff(
                $this->queriesTouching($large, 'task_checklist_items'),
                $this->queriesTouching($small, 'task_checklist_items'),
            )),
            'the list reads the checklist counts with the page query only',
        );
        $this->assertSame(count($this->queriesTouching($small, 'task_checklist_items')), count($this->queriesTouching($large, 'task_checklist_items')));
    }

    #[Test]
    public function the_board_card_shows_done_over_total(): void
    {
        $task = $this->withChecklist(['One', 'Two']);
        [$one] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey()]);

        $cards = Livewire::actingAs($this->rep)->test(TasksBoard::class)->assertOk();
        $cards->assertSeeHtml('<span class="crm-ltr">1/2</span>');

        $queries = $this->queriesDuring(fn () => Livewire::actingAs($this->rep)->test(TasksBoard::class)->assertOk());
        $this->assertSame([], array_values(array_filter(
            $this->queriesTouching($queries, 'task_checklist_items'),
            static fn (string $sql): bool => ! str_contains($sql, '`tasks`'),
        )), 'the counts come with the card query, never on their own');
    }

    #[Test]
    public function the_export_carries_done_of_total_in_words_and_counts_with_the_query(): void
    {
        $task = $this->withChecklist(['One', 'Two', 'Three']);
        [$one, $two] = $this->items($task);
        $this->tasks->tickChecklist($task, $this->rep, [$one->getKey(), $two->getKey()]);
        $plain = $this->tasks->create(['title' => 'Plain', 'assignee_id' => $this->rep->getKey()], $this->admin);

        $exporter = $this->exporter();

        $this->assertSame('2 of 3', $this->cell($exporter, $task->fresh() ?? $task), 'a record fetched without the counts still exports');
        // Never a bare 2/3: a spreadsheet opening the CSV would read it as a date.
        $this->assertDoesNotMatchRegularExpression('#^\d+\s*/\s*\d+$#', $this->cell($exporter, $task->fresh() ?? $task));
        $this->assertSame('2 من 3', $this->cell($this->exporter('ar'), $task->fresh() ?? $task));
        $this->assertSame('', $this->cell($exporter, $plain->fresh() ?? $plain));

        $counted = TaskExporter::modifyQuery(Task::query()->whereKey($task->getKey()))->firstOrFail();
        $this->assertSame(0, $this->countQueries(fn () => $this->cell($exporter, $counted)), 'the counts ride on the export query');
        $this->assertSame('2 of 3', $this->cell($exporter, $counted));
    }

    /**
     * The mounted bulk Complete dialog's description.
     *
     * @param  Testable<Component>  $component
     */
    private function bulkDescription(Testable $component): string
    {
        $page = $component->instance();
        $this->assertInstanceOf(ListTasks::class, $page);

        return (string) $page->getMountedAction()?->getModalDescription();
    }

    /** Matches the Complete dialog's warning: shown, with the given text. */
    private static function warning(string $text): Closure
    {
        return static fn (Callout $callout): bool => $callout->isVisible() && (string) $callout->getHeading() === $text;
    }

    /**
     * A task the admin hands to the rep.
     *
     * @param  list<string>  $items
     * @param  array<string, mixed>  $attributes
     */
    private function withChecklist(array $items, array $attributes = []): Task
    {
        return $this->tasks->create([
            'title' => 'Prepare the visit',
            'assignee_id' => $this->rep->getKey(),
            'checklist' => array_map(static fn (string $body): array => ['body' => $body], $items),
            ...$attributes,
        ], $this->admin);
    }

    /**
     * @return list<TaskChecklistItem>
     */
    private function items(Task $task): array
    {
        return TaskChecklistItem::query()->where('task_id', $task->getKey())->orderBy('position')->orderBy('id')->get()->all();
    }

    /** The task exporter with the checklist column alone. */
    private function exporter(string $locale = 'en'): TaskExporter
    {
        $export = new Export;
        $export->user()->associate($this->admin);
        $export->exporter = TaskExporter::class;
        $export->file_disk = ImportExportActions::FILE_DISK;
        $export->file_name = 'test';
        $export->total_rows = 1;
        $export->save();

        return new TaskExporter($export, ['checklist' => 'Checklist'], [ImportExportActions::LOCALE_OPTION => $locale]);
    }

    private function cell(TaskExporter $exporter, Task $task): string
    {
        return (string) ($exporter($task)[0] ?? '');
    }
}
