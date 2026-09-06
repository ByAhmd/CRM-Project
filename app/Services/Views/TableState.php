<?php

declare(strict_types=1);

namespace App\Services\Views;

use App\Models\SavedView;
use Filament\QueryBuilder\Constraints\Constraint;
use Filament\QueryBuilder\Forms\Components\RuleBuilder;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\QueryBuilder;

/**
 * The part of a Filament list page's state a saved view remembers (A-8):
 * filter form state, sort, search and which toggleable columns are shown.
 *
 * Reading and writing go through Filament's own Livewire properties and
 * update hooks, so an applied view is persisted in the session exactly as if
 * the user had set it by hand. A view never touches the query itself — the
 * resource's scoped base query (D-4) stays in force underneath.
 *
 * A stored view may outlive the table it was taken from (a filter or a
 * rule-builder constraint renamed in a later release, a column no longer
 * sortable), so applyTo() first drops whatever the current table does not
 * know: unknown filter names, rule-builder rules whose constraint or operator
 * no longer exists (inside "or" groups too) and a sort on a column that is
 * not sortable any more. What survives is exactly what the user could have
 * set by hand today, which is what Filament can render without error.
 */
final readonly class TableState
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  ?array<string, bool>  $columns  toggleable column name => shown; null = leave as is
     */
    public function __construct(
        public array $filters,
        public ?string $sortColumn,
        public ?string $sortDirection,
        public ?string $search,
        public ?array $columns,
    ) {}

    public static function fromListPage(ListRecords $page): self
    {
        $search = trim((string) $page->tableSearch);

        return new self(
            filters: $page->tableFilters ?? [],
            sortColumn: $page->getTableSortColumn(),
            sortDirection: $page->getTableSortDirection(),
            search: $search === '' ? null : $search,
            columns: self::toggledColumns($page->tableColumns),
        );
    }

    public static function fromSavedView(SavedView $view): self
    {
        return new self(
            filters: $view->filters ?? [],
            sortColumn: $view->sort_column,
            sortDirection: $view->sort_direction,
            search: $view->search,
            columns: $view->columns,
        );
    }

    /**
     * Write the state onto the page and let Filament persist it, then return
     * the table to its first page.
     */
    public function applyTo(ListRecords $page): void
    {
        $filters = self::sanitiseFilters($page, $this->filters);
        $filters = $filters === [] ? null : $filters;
        $page->tableFilters = $filters;

        // The filters form writes to `tableDeferredFilters` when the table
        // defers filters (Filament's default); fill whichever the form owns,
        // then let Filament copy and persist it the way "Apply" would.
        if ($page->getTable()->hasDeferredFilters()) {
            $page->tableDeferredFilters = $filters;
            $page->getTableFiltersForm()->fill($filters);
            $page->applyTableFilters();
        } else {
            $page->getTableFiltersForm()->fill($filters);
            $page->updatedTableFilters();
        }

        if ($this->columns !== null) {
            $page->applyTableColumnManager(self::columnState($page->getDefaultTableColumnState(), $this->columns));
        }

        $sortColumn = self::sanitiseSortColumn($page, $this->sortColumn);

        $page->tableSort = $sortColumn === null
            ? null
            : $sortColumn.':'.($this->sortDirection === 'desc' ? 'desc' : 'asc');
        $page->updatedTableSort();

        $page->tableSearch = (string) $this->search;
        $page->updatedTableSearch();

        $page->resetPage();
    }

    /**
     * Only the filters the table declares today, and inside a rule builder
     * only the rules whose constraint and operator still exist.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function sanitiseFilters(ListRecords $page, array $filters): array
    {
        $known = $page->getTable()->getFilters();
        $clean = [];

        foreach ($filters as $name => $state) {
            $filter = $known[$name] ?? null;

            if ($filter === null) {
                continue;
            }

            if ($filter instanceof QueryBuilder && is_array($state)) {
                $state['rules'] = self::sanitiseRules($filter->getConstraints(), $state['rules'] ?? []);
            }

            $clean[$name] = $state;
        }

        return $clean;
    }

    /**
     * @param  array<string, Constraint>  $constraints
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function sanitiseRules(array $constraints, array $rules): array
    {
        $clean = [];

        foreach ($rules as $key => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = $rule['type'] ?? null;

            if ($type === RuleBuilder::OR_BLOCK_NAME) {
                $groups = [];

                foreach ($rule['data'][RuleBuilder::OR_BLOCK_GROUPS_REPEATER_NAME] ?? [] as $groupKey => $group) {
                    if (! is_array($group)) {
                        continue;
                    }

                    $group['rules'] = self::sanitiseRules($constraints, $group['rules'] ?? []);
                    $groups[$groupKey] = $group;
                }

                $rule['data'][RuleBuilder::OR_BLOCK_GROUPS_REPEATER_NAME] = $groups;
                $clean[$key] = $rule;

                continue;
            }

            $constraint = is_string($type) ? ($constraints[$type] ?? null) : null;

            if ($constraint === null) {
                continue;
            }

            $operator = $rule['data'][Constraint::OPERATOR_SELECT_NAME] ?? null;

            if (! is_string($operator) || $operator === '') {
                continue;
            }

            [$operatorName] = $constraint->parseOperatorString($operator);

            if ($constraint->getOperator($operatorName) === null) {
                continue;
            }

            $clean[$key] = $rule;
        }

        return $clean;
    }

    /** A sort survives only on a column the table can still sort by. */
    private static function sanitiseSortColumn(ListRecords $page, ?string $column): ?string
    {
        if ($column === null || $column === '') {
            return null;
        }

        return $page->getTable()->getSortableVisibleColumn($column) === null ? null : $column;
    }

    /**
     * Back to the table's own defaults: no filters, no search, the declared
     * default sort and the default column toggles.
     */
    public static function reset(ListRecords $page): void
    {
        $page->resetTableFiltersForm();
        $page->resetTableSearch();

        $page->tableSort = null;
        $page->updatedTableSort();

        $page->resetTableColumnManager();
        $page->resetPage();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return ?array<string, bool>
     */
    private static function toggledColumns(array $items): ?array
    {
        $columns = [];

        foreach ($items as $item) {
            if (($item['type'] ?? null) === ListRecords::TABLE_COLUMN_MANAGER_GROUP_TYPE) {
                foreach ($item['columns'] ?? [] as $column) {
                    if ($column['isToggleable'] ?? false) {
                        $columns[(string) $column['name']] = (bool) ($column['isToggled'] ?? true);
                    }
                }

                continue;
            }

            if ($item['isToggleable'] ?? false) {
                $columns[(string) $item['name']] = (bool) ($item['isToggled'] ?? true);
            }
        }

        return $columns === [] ? null : $columns;
    }

    /**
     * The page's default column manager state with the saved toggles applied.
     *
     * @param  array<int, array<string, mixed>>  $defaults
     * @param  array<string, bool>  $columns
     * @return array<int, array<string, mixed>>
     */
    private static function columnState(array $defaults, array $columns): array
    {
        foreach ($defaults as &$item) {
            if (($item['type'] ?? null) === ListRecords::TABLE_COLUMN_MANAGER_GROUP_TYPE) {
                foreach ($item['columns'] ?? [] as &$column) {
                    if (($column['isToggleable'] ?? false) && array_key_exists((string) $column['name'], $columns)) {
                        $column['isToggled'] = $columns[(string) $column['name']];
                    }
                }

                unset($column);

                continue;
            }

            if (($item['isToggleable'] ?? false) && array_key_exists((string) $item['name'], $columns)) {
                $item['isToggled'] = $columns[(string) $item['name']];
            }
        }

        unset($item);

        return $defaults;
    }
}
