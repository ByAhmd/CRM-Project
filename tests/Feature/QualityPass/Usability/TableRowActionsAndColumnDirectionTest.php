<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Usability;

use App\Contracts\OwnedRecord;
use App\Filament\Support\LtrText;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Two table-wide presentation invariants the owner asked for, held across
 * every registered resource table and every relation manager rather than
 * against a list of file names, so a table or a column added later cannot
 * reintroduce either defect.
 *
 * 1. A row offers its actions through one three-dot menu instead of a row of
 *    links — and the menu never becomes a way to show an action the policy
 *    would refuse. A table with a single row action keeps it inline: a menu
 *    holding one item is worse to use than the item itself.
 * 2. No column declares its direction on its cell. `dir="ltr"` on the cell
 *    resolves `text-align: start` to the left while the column's own `<th>`
 *    follows the table and resolves it to the right, so in Arabic the header
 *    and its values sit on opposite edges of the column — the defect the
 *    owner reported on «الرمز» and «سعر الوحدة». Latin values go through
 *    App\Filament\Support\LtrText instead, which puts the direction on an
 *    inline span around the value and leaves the cell, and therefore the
 *    alignment, in the table's own direction.
 */
final class TableRowActionsAndColumnDirectionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function a_table_with_more_than_one_row_action_offers_them_through_one_labelled_menu(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $tables = $this->tables($this->superAdmin());
        $offenders = [];
        $rosters = [];

        foreach ($tables as $label => $table) {
            $recordActions = $table->getRecordActions();
            $flat = $table->getFlatRecordActions();

            if (count($flat) < 2) {
                // One action stays inline: a three-dot menu holding a single
                // item costs a click and hides the only thing the row offers.
                foreach ($recordActions as $action) {
                    if ($action instanceof ActionGroup) {
                        $offenders[] = "{$label}: a single row action is hidden inside a menu";
                    }
                }

                continue;
            }

            $grouped = [];

            foreach ($recordActions as $action) {
                if (! $action instanceof ActionGroup) {
                    $offenders[] = "{$label}: row action [{$action->getName()}] is rendered inline beside the menu";

                    continue;
                }

                if ($action->getLabel() !== __('app.actions.row_actions')) {
                    $offenders[] = "{$label}: the menu is not labelled from app.actions.row_actions";
                }

                $grouped = [...$grouped, ...array_keys($action->getFlatActions())];
            }

            // Comparing $grouped with $flat here would be a tautology:
            // Table::getFlatRecordActions() is built by this very loop, so both
            // sides would shrink together if an action were dropped. The real
            // guard is the explicit roster below.
            $rosters[$label] = $grouped;
        }

        $this->assertGreaterThan(40, count($tables), 'sanity: the walk covers the panel tables and relation managers');
        $this->assertSame([], $offenders, "row actions not collapsed into one menu:\n".implode("\n", $offenders));

        // The tables the owner reported: their menus must still hold exactly the
        // actions they held before the regrouping. Written out rather than
        // derived, so deleting an action fails here instead of passing quietly.
        foreach (self::EXPECTED_ROW_ACTIONS as $label => $expected) {
            $this->assertArrayHasKey($label, $rosters, "the walk no longer reaches {$label}");

            $this->assertEqualsCanonicalizing(
                $expected,
                $rosters[$label],
                "{$label}: the three-dot menu no longer holds exactly the row actions this table must offer",
            );
        }
    }

    /**
     * The row actions each reported table must keep offering, by action name.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_ROW_ACTIONS = [
        'App\Filament\Resources\Leads\Pages\ListLeads' => [
            'view', 'edit', 'changeStatus', 'convert', 'sendEmail', 'assign',
        ],
        'App\Filament\Resources\Deals\Pages\ListDeals' => [
            'view', 'edit', 'changeStage', 'markWon', 'markLost', 'reopen', 'assign',
        ],
        'App\Filament\Resources\Contacts\Pages\ListContacts' => [
            'view', 'edit', 'sendEmail', 'assign',
        ],
        'App\Filament\Resources\Accounts\Pages\ListAccounts' => [
            'view', 'edit', 'assign',
        ],
    ];

    #[Test]
    public function a_row_action_the_policy_refuses_is_not_offered_inside_the_menu(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $team = $this->makeTeam();
        $rep = $this->salesRep($team);
        $admin = $this->superAdmin($team);

        // Reassignment is the sharpest case in the panel: a sales rep may edit
        // their own record but may never hand it to somebody else (D-4). Were
        // the menu showing what the policy refuses, the rep would find «إسناد»
        // in it on every owned entity. The super admin holds every permission,
        // so the positive half does not depend on how a role is composed.
        $repTables = $this->listTables($rep);
        $adminTables = $this->listTables($admin);
        $checked = 0;

        foreach ($repTables as $label => $table) {
            $assign = $table->getFlatRecordActions()['assign'] ?? null;
            $adminTable = $adminTables[$label] ?? null;

            if (! $assign instanceof Action || ! $adminTable instanceof Table) {
                continue;
            }

            $record = $this->ownedRecordFor($table, $rep);

            if (! $record instanceof Model) {
                continue;
            }

            $checked++;

            // The gate reads the authenticated user, so each half is answered
            // as the actor whose menu it is.
            $this->actingAs($rep);

            $this->assertFalse(
                $this->isOfferedInMenu($table, 'assign', $record),
                "{$label}: a sales rep is offered the reassignment action inside the menu",
            );

            $this->actingAs($admin);

            $this->assertTrue(
                $this->isOfferedInMenu($adminTable, 'assign', $record),
                "{$label}: a super admin is not offered the reassignment action inside the menu",
            );
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'sanity: the walk reached the owned-entity tables');
    }

    #[Test]
    public function no_table_column_declares_its_direction_on_the_cell(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        /** @var array<string, Closure(Column): array<string, mixed>> $bags */
        $bags = [
            'extraAttributes' => static fn (Column $column): array => $column->getExtraAttributes(),
            'extraCellAttributes' => static fn (Column $column): array => $column->getExtraCellAttributes(),
            'extraHeaderAttributes' => static fn (Column $column): array => $column->getExtraHeaderAttributes(),
        ];

        $tables = $this->tables($this->superAdmin());
        $offenders = [];
        $columns = 0;

        foreach ($tables as $label => $table) {
            foreach ($table->getColumns() as $column) {
                $columns++;

                foreach ($bags as $name => $read) {
                    try {
                        $attributes = $read($column);
                    } catch (Throwable $exception) {
                        $offenders[] = "{$label}.{$column->getName()}: {$name}() cannot be read ({$exception->getMessage()}), so the direction invariant cannot be proven";

                        continue;
                    }

                    if (array_key_exists('dir', $attributes)) {
                        $direction = is_scalar($attributes['dir']) ? (string) $attributes['dir'] : 'a closure';

                        $offenders[] = "{$label}.{$column->getName()}: {$name}() declares dir=\"{$direction}\"; wrap the value with LtrText::column() instead, so the cell keeps the table's direction and lines up with its header";
                    }
                }
            }
        }

        $this->assertGreaterThan(80, $columns, 'sanity: the walk covers the panel columns');
        $this->assertSame([], $offenders, "columns whose direction is declared on the cell:\n".implode("\n", $offenders));
    }

    /**
     * The negative test above would also pass if the direction were simply
     * deleted everywhere, which would leave Latin codes and money reading in
     * the wrong character order under Arabic. This pins the other half: the
     * value really is wrapped, the wrapper really carries the direction, and
     * the value inside it really is escaped.
     */
    #[Test]
    public function a_latin_column_wraps_its_value_in_an_inline_ltr_span_and_escapes_it(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $column = LtrText::column(TextColumn::make('code'));

        $rendered = $column->formatState('<script>alert(1)</script>');

        $this->assertInstanceOf(
            HtmlString::class,
            $rendered,
            'the wrapped value is not marked as HTML, so the span would be printed as text',
        );

        $html = $rendered->toHtml();

        $this->assertStringStartsWith('<span dir="ltr">', $html, 'the value is not wrapped in an LTR span');
        $this->assertStringEndsWith('</span>', $html, 'the LTR span is not closed around the value');

        $this->assertStringNotContainsString(
            '<script>',
            $html,
            'the column value is concatenated into HTML unescaped — an XSS hole, since codes, e-mails and phone numbers are user-entered',
        );
        $this->assertStringContainsString('&lt;script&gt;', $html, 'the value was dropped rather than escaped');
    }

    /**
     * Whether the menu of $table offers the action named $name on $record.
     * Mirrors what Filament's table view does for every row: the group is
     * handed the record and each action decides for itself, authorisation
     * included.
     */
    private function isOfferedInMenu(Table $table, string $name, Model $record): bool
    {
        foreach ($table->getRecordActions() as $action) {
            if (! $action instanceof ActionGroup) {
                continue;
            }

            $group = $action->getClone();
            $group->record($record);

            foreach ($group->getActions() as $grouped) {
                if ($grouped->getName() === $name) {
                    return ! $grouped->isHiddenInGroup();
                }
            }
        }

        return false;
    }

    /**
     * A record of the table's model that $owner owns, so the row is inside
     * their scope and only the action's own gate can hide it.
     */
    private function ownedRecordFor(Table $table, User $owner): ?Model
    {
        /** @var class-string<Model>|null $model */
        $model = $table->getModel();

        if ($model === null || ! method_exists($model, 'factory')) {
            return null;
        }

        $instance = new $model;

        if (! $instance instanceof OwnedRecord) {
            return null;
        }

        $column = $instance::ownerColumn();

        try {
            $model::factory()->create([$column => $owner->getKey()]);
        } catch (Throwable) {
            return null;
        }

        return $model::query()->where($column, $owner->getKey())->first();
    }

    /**
     * Every table of the panel as $actor sees it: the listing of each
     * registered resource and each relation manager those resources declare.
     *
     * @return array<string, Table>
     */
    private function tables(User $actor): array
    {
        $tables = $this->listTables($actor);

        // The scoped resource queries below and the column and action closures
        // the tests read afterwards all consult the authenticated user.
        $this->actingAs($actor);

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $relations = $resource::getRelations();

            if ($relations === []) {
                continue;
            }

            $pages = $resource::getPages();
            $registration = $pages['view'] ?? $pages['edit'] ?? null;
            $owner = $this->ownerRecordFor($resource);

            if (! $registration instanceof PageRegistration || ! $owner instanceof Model) {
                continue;
            }

            foreach ($relations as $manager) {
                if (! is_string($manager)) {
                    continue;
                }

                $component = Livewire::actingAs($actor)
                    ->test($manager, ['ownerRecord' => $owner, 'pageClass' => $registration->getPage()])
                    ->instance();

                if ($component instanceof HasTable) {
                    $tables[$manager] = $component->getTable();
                }
            }
        }

        return $tables;
    }

    /**
     * The listing table of every registered resource $actor may open. A page
     * the actor's role keeps from them is left out rather than failing the
     * walk: the counts each test asserts catch a walk that fell silent.
     *
     * @return array<string, Table>
     */
    private function listTables(User $actor): array
    {
        $this->actingAs($actor);

        $tables = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $registration = $resource::getPages()['index'] ?? null;

            if (! $registration instanceof PageRegistration) {
                continue;
            }

            $page = $registration->getPage();

            try {
                $component = Livewire::actingAs($actor)->test($page)->instance();
            } catch (Throwable) {
                continue;
            }

            if ($component instanceof HasTable) {
                $tables[$page] = $component->getTable();
            }
        }

        return $tables;
    }

    /**
     * The record a relation manager hangs off: an existing one when the
     * lookups already seeded it, otherwise a made one.
     *
     * @param  class-string<resource>  $resource
     */
    private function ownerRecordFor(string $resource): ?Model
    {
        $existing = $resource::getEloquentQuery()->first();

        if ($existing instanceof Model) {
            return $existing;
        }

        /** @var class-string<Model> $model */
        $model = $resource::getModel();

        if (! method_exists($model, 'factory')) {
            return null;
        }

        try {
            $model::factory()->create();
        } catch (Throwable) {
            return null;
        }

        return $resource::getEloquentQuery()->first();
    }
}
