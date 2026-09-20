<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Usability;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\Concerns\WalksPanelTables;
use Tests\TestCase;

/**
 * The phone column budget, held across every registered resource table and
 * every relation manager rather than against a list of file names, so a table
 * or a column added later cannot quietly reintroduce the ten-column squeeze a
 * 375-px screen used to render.
 *
 * 1. At the base breakpoint a table shows at most FOUR columns — the record's
 *    identity, its status or kind, and the one figure a user checks on the go.
 *    Everything else declares `visibleFrom('md')` or `visibleFrom('lg')`,
 *    which hides the cell with a CSS breakpoint while keeping it in the DOM,
 *    so wider screens and the render-assertions of other tests are untouched.
 *    At least one column must remain at base: a table whose every column
 *    steps in later would render empty rows on a phone.
 *
 * 2. `toggleable(isToggledHiddenByDefault: true)` is NOT an acceptable way to
 *    meet the budget: a toggled-off column is excluded from the render, which
 *    breaks every test that asserts cell contents, and it hides the column on
 *    desktop too. The exact set of columns that used it before the budget was
 *    introduced is pinned below; a new one fails here on purpose so it is a
 *    reviewed decision instead of a lazy default.
 *
 * The walk mirrors TableRowActionsAndColumnDirectionTest in this directory.
 * The helpers are deliberately private copies rather than a shared trait:
 * that file is a permanent quality-pass regression test that this change must
 * not touch, and the walk is the technique being copied, not an API.
 */
final class MobileColumnBudgetTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;
    use WalksPanelTables;

    /** The most columns a table may show at the base breakpoint. */
    private const int MOBILE_COLUMN_BUDGET = 4;

    /** The breakpoints Filament's responsive column classes exist for. */
    private const array BREAKPOINTS = ['sm', 'md', 'lg', 'xl', '2xl'];

    /**
     * The columns that were already toggled off by default when the budget was
     * introduced, by table. Written out rather than derived, so hiding a new
     * column from the render — instead of from a breakpoint — fails here.
     *
     * @var array<string, list<string>>
     */
    private const array TOGGLED_HIDDEN_BY_DEFAULT = [
        'App\Filament\Resources\Accounts\Pages\ListAccounts' => ['created_at', 'email'],
        'App\Filament\Resources\Accounts\RelationManagers\AccountTasksRelationManager' => ['created_at'],
        'App\Filament\Resources\ActivityTypes\Pages\ListActivityTypes' => ['sort'],
        'App\Filament\Resources\Contacts\Pages\ListContacts' => ['created_at'],
        'App\Filament\Resources\Contacts\RelationManagers\ContactTasksRelationManager' => ['created_at'],
        'App\Filament\Resources\CustomFields\Pages\ListCustomFields' => ['sort'],
        'App\Filament\Resources\DealCloseReasons\Pages\ListDealCloseReasons' => ['sort'],
        'App\Filament\Resources\Deals\Pages\ListDeals' => ['products_count'],
        'App\Filament\Resources\Deals\RelationManagers\DealTasksRelationManager' => ['created_at'],
        'App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates' => ['sort'],
        'App\Filament\Resources\Exports\Pages\ListExports' => ['completed_at'],
        'App\Filament\Resources\Imports\Pages\ListImports' => ['completed_at', 'processed_rows'],
        'App\Filament\Resources\Industries\Pages\ListIndustries' => ['sort'],
        'App\Filament\Resources\LeadScoringRules\Pages\ListLeadScoringRules' => ['sort'],
        'App\Filament\Resources\LeadSources\Pages\ListLeadSources' => ['sort'],
        'App\Filament\Resources\LeadStatuses\Pages\ListLeadStatuses' => ['sort'],
        'App\Filament\Resources\Leads\Pages\ListLeads' => ['email', 'phone'],
        'App\Filament\Resources\Leads\RelationManagers\LeadTasksRelationManager' => ['created_at'],
        'App\Filament\Resources\Pipelines\Pages\ListPipelines' => ['sort'],
        'App\Filament\Resources\Pipelines\RelationManagers\StagesRelationManager' => ['sort'],
        'App\Filament\Resources\Tasks\Pages\ListTasks' => ['created_at'],
        'App\Filament\Resources\Teams\Pages\ListTeams' => ['sort'],
    ];

    #[Test]
    public function every_table_shows_at_most_four_columns_at_the_base_breakpoint(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $tables = $this->tables($this->superAdmin());
        $offenders = [];
        $columns = 0;

        foreach ($tables as $label => $table) {
            $base = [];

            foreach ($table->getColumns() as $column) {
                $columns++;
                $breakpoint = $column->getVisibleFrom();

                if ($breakpoint !== null && ! in_array($breakpoint, self::BREAKPOINTS, true)) {
                    $offenders[] = "{$label}.{$column->getName()}: visibleFrom(\"{$breakpoint}\") is not a Tailwind breakpoint, so the column would never be shown";

                    continue;
                }

                // A toggled-off column is not rendered at any width, and a
                // column with a breakpoint is hidden below it; everything
                // else is what a phone shows.
                if ($breakpoint === null && ! $column->isToggledHiddenByDefault()) {
                    $base[] = $column->getName();
                }
            }

            if (count($base) > self::MOBILE_COLUMN_BUDGET) {
                $offenders[] = "{$label}: ".count($base).' columns at the base breakpoint ('.implode(', ', $base).'); declare visibleFrom() until at most '.self::MOBILE_COLUMN_BUDGET.' remain';
            }

            if ($base === []) {
                $offenders[] = "{$label}: no column is left at the base breakpoint, so a phone would render empty rows";
            }
        }

        $this->assertGreaterThan(40, count($tables), 'sanity: the walk covers the panel tables and relation managers');
        $this->assertGreaterThan(80, $columns, 'sanity: the walk covers the panel columns');
        $this->assertSame([], $offenders, "tables over the phone column budget:\n".implode("\n", $offenders));
    }

    #[Test]
    public function no_column_is_toggled_off_by_default_beyond_the_pinned_set(): void
    {
        $this->seedAccess();
        $this->seedLookups();
        $this->usePanel();

        $tables = $this->tables($this->superAdmin());
        $actual = [];

        foreach ($tables as $label => $table) {
            $hidden = [];

            foreach ($table->getColumns() as $column) {
                if ($column->isToggledHiddenByDefault()) {
                    $hidden[] = $column->getName();
                }
            }

            if ($hidden !== []) {
                sort($hidden);
                $actual[$label] = $hidden;
            }
        }

        ksort($actual);

        $expected = self::TOGGLED_HIDDEN_BY_DEFAULT;
        ksort($expected);

        $this->assertSame(
            $expected,
            $actual,
            'the set of columns toggled off by default changed: a column hidden from the render breaks the tests that assert '
            .'cell contents, so meet the phone budget with visibleFrom() and change the pin only for a reviewed decision',
        );
    }
}
