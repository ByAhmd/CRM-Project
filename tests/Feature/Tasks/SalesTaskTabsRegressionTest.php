<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\AccountTasksRelationManager;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\ContactTasksRelationManager;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadTasksRelationManager;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\Entry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The owner keeps the Sales side as it is (D-27, 2026-10-07): the task tab
 * on a lead, contact, account or deal page keeps its layout and behaviour
 * while the Tasks workspace is reworked. The tabs read their own entry
 * points — TasksTable::configureForSubjectTab(),
 * TaskInfolist::configureForSubjectTab() and the shared TaskActions dialogs
 * — and this test pins what they offer as it stood before D-27: the columns
 * and their phone budget, the filters, the one row menu and its actions, the
 * header and bulk actions, no grouping, the dialogs of Start and Complete,
 * and the structure of the task modal. Purely visual
 * cues a tab may inherit (D-27: status colours, relative dates) are not
 * pinned; the structure is.
 */
final class SalesTaskTabsRegressionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const array COLUMNS = [
        'title' => null,
        'kind' => 'md',
        'status' => null,
        'priority' => 'md',
        'due_at' => null,
        'assignee.name' => 'lg',
        'checklist_progress' => 'md',
        'recurrence_frequency' => 'md',
        'created_at' => null,
    ];

    private const array FILTERS = ['status', 'priority', 'kind', 'assignee_id', 'due_at', 'trashed'];

    private const array ROW_MENU = ['view', 'edit', 'start', 'postUpdate', 'checklist', 'comment', 'complete', 'cancel', 'reopen', 'assign', 'delete', 'restore'];

    private const array BULK = ['complete', 'assign', 'delete', 'restore'];

    /** The task modal of the tabs, top to bottom: sections, grids and entries in order. */
    private const array MODAL = [
        'section:Task details', 'grid', 'entry:status', 'entry:priority', 'entry:kind', 'entry:assignee.name', 'entry:title', 'entry:description',
        'section:Checklist', 'entry:checklistItems', 'entry:body', 'entry:ticked',
        'section:Progress and comments', 'entry:updates', 'grid', 'entry:kind', 'entry:author_name', 'entry:created_at', 'entry:status', 'entry:body',
        'section:Schedule', 'grid', 'entry:due_at', 'entry:reminder_at', 'entry:starts_at', 'entry:ends_at', 'entry:completed_at',
        'section:Related records', 'grid', 'entry:deal.title', 'entry:lead.full_name', 'entry:contact.full_name', 'entry:account.name',
        'section:Recurrence', 'grid', 'entry:recurrence_summary', 'entry:recurrence_ends_at', 'entry:series.title', 'entry:occurrences_count',
        'section:History', 'grid', 'entry:activities_count', 'entry:creator.name', 'entry:assigner.name', 'entry:created_at', 'entry:updated_at',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        app()->setLocale('en');

        $this->admin = $this->admin();
    }

    #[Test]
    public function the_four_task_tabs_keep_their_columns_filters_and_actions(): void
    {
        foreach ($this->tabs() as $label => [$manager, $owner, $page]) {
            $table = $this->tableOf($manager, $owner, $page);

            $columns = [];

            foreach ($table->getColumns() as $name => $column) {
                $columns[$name] = $column->getVisibleFrom();
            }

            $this->assertSame(self::COLUMNS, $columns, "{$label}: the columns, their order and their phone budget");
            $this->assertSame(self::FILTERS, array_keys($table->getFilters()), "{$label}: the filters");
            $this->assertSame([], $table->getGroups(), "{$label}: the tab is not grouped");

            $recordActions = $table->getRecordActions();
            $this->assertCount(1, $recordActions, "{$label}: one row menu");
            $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);
            $this->assertSame(self::ROW_MENU, array_keys($recordActions[0]->getFlatActions()), "{$label}: the row menu");

            $this->assertSame(['create'], array_map(static fn (Action|ActionGroup $action): ?string => $action instanceof Action ? $action->getName() : null, $table->getHeaderActions()), "{$label}: the add-task button");

            $bulk = [];

            foreach ($table->getToolbarActions() as $toolbar) {
                $bulk = [...$bulk, ...($toolbar instanceof ActionGroup ? array_keys($toolbar->getFlatActions()) : [$toolbar->getName()])];
            }

            $this->assertSame(self::BULK, $bulk, "{$label}: the bulk actions (no export on a tab)");
        }
    }

    #[Test]
    public function the_tab_keeps_its_start_and_complete_dialogs(): void
    {
        $deal = Deal::factory()->create(['owner_id' => $this->admin->getKey()]);
        $task = Task::factory()->create(['assignee_id' => $this->admin->getKey(), 'deal_id' => $deal->getKey()]);

        Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->mountAction(TestAction::make('start')->table($task))
            ->assertFormFieldExists('start_note');

        Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->mountAction(TestAction::make('complete')->table($task))
            ->assertFormFieldExists('completion_note');

        $this->assertTrue($task->refresh()->isOpen(), 'mounting the dialogs completes nothing: the tab keeps its two-step completion');
    }

    #[Test]
    public function the_task_modal_of_the_tabs_keeps_its_layout(): void
    {
        $this->actingAs($this->admin);

        $deal = Deal::factory()->create(['owner_id' => $this->admin->getKey()]);
        $manager = Livewire::actingAs($this->admin)
            ->test(DealTasksRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class])
            ->instance();
        assert($manager instanceof RelationManager);

        $this->assertSame(self::MODAL, $this->signature($manager->infolist(Schema::make($manager))->getComponents(withActions: false, withHidden: true)));

        // The Sales entry point is its own: the workspace's task page may change without reaching the tab.
        $this->assertSame(
            $this->signature(TaskInfolist::configureForSubjectTab(Schema::make())->getComponents(withActions: false, withHidden: true)),
            self::MODAL,
        );
    }

    /**
     * @return array<string, array{0: class-string<RelationManager>, 1: Model, 2: class-string}>
     */
    private function tabs(): array
    {
        return [
            'lead' => [LeadTasksRelationManager::class, Lead::factory()->create(['owner_id' => $this->admin->getKey()]), ViewLead::class],
            'contact' => [ContactTasksRelationManager::class, Contact::factory()->create(['owner_id' => $this->admin->getKey()]), ViewContact::class],
            'account' => [AccountTasksRelationManager::class, Account::factory()->create(['owner_id' => $this->admin->getKey()]), ViewAccount::class],
            'deal' => [DealTasksRelationManager::class, Deal::factory()->create(['owner_id' => $this->admin->getKey()]), ViewDeal::class],
        ];
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function tableOf(string $manager, Model $owner, string $page): Table
    {
        $instance = Livewire::actingAs($this->admin)
            ->test($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
            ->instance();
        assert($instance instanceof RelationManager);

        return $instance->getTable();
    }

    /**
     * The component tree in order, as "section:<heading>", "grid" and
     * "entry:<name>" — the declared children only, so no closure on a record
     * is evaluated.
     *
     * @param  array<mixed>  $components
     * @return list<string>
     */
    private function signature(array $components): array
    {
        $signature = [];

        foreach ($components as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            if ($component instanceof Section) {
                $heading = $component->getHeading();
                $signature[] = 'section:'.($heading instanceof Htmlable ? $heading->toHtml() : (string) $heading);
            } elseif ($component instanceof Grid) {
                $signature[] = 'grid';
            } elseif ($component instanceof Entry) {
                $signature[] = 'entry:'.$component->getName();
            }

            $children = $component->getDefaultChildComponents();

            if ($children instanceof Schema) {
                $children = $children->getComponents(withActions: false, withHidden: true);
            }

            $signature = [...$signature, ...$this->signature($children)];
        }

        return $signature;
    }
}
