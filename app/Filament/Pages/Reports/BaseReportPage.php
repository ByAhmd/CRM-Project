<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Filament\Exports\Reports\ReportRowExporter;
use App\Models\Pipeline;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Settings\SettingsRepository;
use App\Services\Statistics\Reports\ReportFilters;
use App\Services\Statistics\Reports\ReportRow;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the nine report pages share (module 23, decisions D-4, D-13) — the
 * only abstract page of the panel, documented here as CLAUDE.md asks.
 *
 * A report page presents: it gates on `reports.view`, shows the filter
 * form (period, owner, team, pipeline, grouping — each report says which
 * apply), asks its statistics service for the rows and the chart inside
 * the viewer's scope, and formats the figures for the locale. Every rule
 * lives in the service and in ReportFilters::resolve(), which is where the
 * submitted filters are clamped: the page never touches a query.
 *
 * The filters the report is rendered from live in the locked $applied
 * state — only run() and resetFilters() may change it, after the form validated —
 * so a browser cannot push filters the form did not accept. The chart is a
 * Chart.js canvas drawn by Filament's own chart component from the
 * service's chart() data, and the table is rendered by the shared blade
 * with a totals line. The export actions stream the same rows and totals
 * as CSV or XLSX through ReportRowExporter.
 *
 * @property-read Schema $form
 */
abstract class BaseReportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected string $view = 'filament.pages.reports.report';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The validated filter state the report is rendered from; only run()
     * and resetFilters() write it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $applied = [];

    /** The key of the report in lang/reports.php (`leads`, `funnel`, …). */
    abstract public static function reportKey(): string;

    /** The permission group of the report's main entity, for the owner list and the team rule. */
    abstract protected static function permissionGroup(): string;

    /**
     * @return Collection<int, ReportRow>
     */
    abstract protected function reportRows(User $viewer, ReportFilters $filters): Collection;

    /**
     * The totals line under the rows; null for a report without one.
     *
     * @param  Collection<int, ReportRow>  $rows
     */
    abstract protected function reportTotals(Collection $rows): ?ReportRow;

    /**
     * The chart of the report, built from the rows the page already
     * fetched so a render never runs the aggregates twice (D-1: the shared
     * host pays for every query). A report whose chart is a different
     * series than its table — win / loss — ignores the rows and asks its
     * service for that series instead.
     *
     * @param  Collection<int, ReportRow>  $rows
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int|float>, color: string}>}
     */
    abstract protected function reportChart(User $viewer, ReportFilters $filters, Collection $rows): array;

    /**
     * @return array<string, string>
     */
    abstract protected function reportColumns(): array;

    /**
     * @return array<string, string>
     */
    abstract protected function reportFormats(): array;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Reports;
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.navigation.'.static::reportKey());
    }

    public function getTitle(): string
    {
        return __('reports.pages.'.static::reportKey().'.title');
    }

    public function getSubheading(): string
    {
        return __('reports.pages.'.static::reportKey().'.description');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::ReportsView->value);
    }

    /** Whether the period filter applies; snapshot reports (pipeline, forecast) say no. */
    protected static function usesPeriod(): bool
    {
        return true;
    }

    /** Whether the report offers the pipeline filter at all. */
    protected static function usesPipeline(): bool
    {
        return false;
    }

    /**
     * Whether the report is *defined over one pipeline* — the pipeline
     * report and the forecast are, and they pre-select the default
     * pipeline. Offering the filter is not the same thing: sales
     * performance and win / loss read every deal in scope unless the
     * viewer narrows to a pipeline, so for them the filter starts empty
     * and "all pipelines" stays reachable.
     */
    protected static function pipelineIsRequired(): bool
    {
        return false;
    }

    /**
     * The groupings the report offers, as option values; empty when it has none.
     *
     * @return list<string>
     */
    protected static function groupByOptions(): array
    {
        return [];
    }

    protected static function defaultGroupBy(): ?string
    {
        return null;
    }

    /** The heading of the label column; a grouped report overrides it to follow the grouping. */
    protected function labelHeading(ReportFilters $filters): string
    {
        return __('reports.columns.'.static::columnsKey().'.label');
    }

    /** The block of lang reports.columns the report's headings come from. */
    protected static function columnsKey(): string
    {
        return static::reportKey();
    }

    /** The Chart.js chart type: bar or line. */
    protected static function chartType(): string
    {
        return 'bar';
    }

    protected static function chartIsStacked(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->applied = $this->defaultFilters();
        $this->form->fill($this->applied);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('reports.sections.filters'))
                    ->schema([
                        Grid::make(['md' => 2, 'xl' => 4])->schema($this->filterFields()),
                    ])
                    ->columns(1),
            ])
            ->columns(1)
            ->statePath('data');
    }

    /** Validates the form and renders the report from its state. */
    public function run(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->applied = $this->form->getState();
    }

    /** Back to the defaults: the current month, the whole scope, the default pipeline. */
    public function resetFilters(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->applied = $this->defaultFilters();
        $this->form->fill($this->applied);
    }

    /** The filters the report is currently rendered from. */
    public function filters(): ReportFilters
    {
        return ReportFilters::resolve(
            $this->applied,
            $this->actor(),
            app(RecordVisibilityResolver::class),
            static::permissionGroup(),
            $this->settings()->timezone(),
            static::groupByOptions(),
            static::defaultGroupBy(),
        );
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('exportCsv')
                    ->label(__('reports.actions.export_csv'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->authorize(static fn (): bool => static::canAccess())
                    ->action(fn (): StreamedResponse => $this->export(ReportRowExporter::FORMAT_CSV)),

                Action::make('exportXlsx')
                    ->label(__('reports.actions.export_xlsx'))
                    ->icon(Heroicon::OutlinedTableCells)
                    ->authorize(static fn (): bool => static::canAccess())
                    ->action(fn (): StreamedResponse => $this->export(ReportRowExporter::FORMAT_XLSX)),
            ])
                ->label(__('reports.actions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray'),
        ];
    }

    /** Money for the current locale in the organisation currency (D-8), rates with one decimal, counts plain. */
    public static function format(string $format, int|float|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $locale = app()->getLocale();

        return match ($format) {
            ReportRow::FORMAT_MONEY => (string) Number::currency((float) $value, app(SettingsRepository::class)->currency(), $locale),
            ReportRow::FORMAT_PERCENT => (string) Number::percentage((float) $value, 1, locale: $locale),
            ReportRow::FORMAT_DECIMAL => (string) Number::format((float) $value, 1, locale: $locale),
            default => (string) Number::format((int) $value, locale: $locale),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $viewer = $this->actor();
        $filters = $this->filters();
        $rows = $this->reportRows($viewer, $filters);
        $isEmpty = self::rowsAreEmpty($rows);
        $locale = app()->getLocale();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'isEmpty' => $isEmpty,
            'totals' => $rows->isEmpty() ? null : $this->reportTotals($rows),
            'columns' => $this->reportColumns(),
            'formats' => $this->reportFormats(),
            'labelHeading' => $this->labelHeading($filters),
            'chart' => $isEmpty ? ['labels' => [], 'datasets' => []] : $this->reportChart($viewer, $filters, $rows),
            'chartType' => static::chartType(),
            'chartOptions' => self::chartOptions($locale === 'ar', static::chartIsStacked()),
            'chartId' => 'crm-report-chart-'.Str::slug(static::reportKey()),
            'palette' => ['primary', 'success', 'danger', 'warning', 'info', 'gray'],
        ];
    }

    /**
     * The filter fields the report uses, in form order.
     *
     * @return list<Component>
     */
    private function filterFields(): array
    {
        $viewer = $this->actor();
        $group = static::permissionGroup();
        $fields = [];

        if (static::usesPeriod()) {
            $fields[] = DatePicker::make('from')
                ->label(__('reports.filters.from'))
                ->required()
                ->native(false);

            $fields[] = DatePicker::make('to')
                ->label(__('reports.filters.to'))
                ->required()
                ->native(false)
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $from = $this->data['from'] ?? null;

                    if (! is_string($from) || ! is_string($value) || $from === '' || $value === '') {
                        return;
                    }

                    $fromDay = CarbonImmutable::parse($from);
                    $toDay = CarbonImmutable::parse($value);

                    if ($toDay->lessThan($fromDay)) {
                        $fail(__('reports.validation.to_before_from'));

                        return;
                    }

                    if ($fromDay->diffInDays($toDay) + 1 > ReportFilters::MAX_RANGE_DAYS) {
                        $fail(__('reports.validation.range_too_large', ['days' => ReportFilters::MAX_RANGE_DAYS]));
                    }
                }]);
        }

        $fields[] = Select::make('owner_id')
            ->label(__('reports.filters.owner'))
            ->placeholder(__('reports.filters.all_owners'))
            ->options(static fn (): array => app(RecordVisibilityResolver::class)->assignableUsers($viewer, $group)->pluck('name', 'id')->all())
            ->searchable()
            ->native(false);

        if ($viewer->can($group.'.view_all')) {
            $fields[] = Select::make('team_id')
                ->label(__('reports.filters.team'))
                ->placeholder(__('reports.filters.all_teams'))
                ->options(static fn (): array => Team::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()
                    ->mapWithKeys(static fn (Team $team): array => [(int) $team->getKey() => $team->display_name])
                    ->all())
                ->native(false);
        }

        if (static::usesPipeline()) {
            $pipeline = Select::make('pipeline_id')
                ->label(__('reports.filters.pipeline'))
                ->options(static fn (): array => Pipeline::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()
                    ->mapWithKeys(static fn (Pipeline $pipeline): array => [(int) $pipeline->getKey() => $pipeline->display_name])
                    ->all())
                ->native(false);

            $fields[] = static::pipelineIsRequired()
                ? $pipeline->required()
                : $pipeline->placeholder(__('reports.filters.all_pipelines'));
        }

        if (static::groupByOptions() !== []) {
            $fields[] = Select::make('group_by')
                ->label(__('reports.filters.group_by'))
                ->options(array_combine(
                    static::groupByOptions(),
                    array_map(static fn (string $option): string => __('reports.filters.options.group_by.'.$option), static::groupByOptions()),
                ))
                ->required()
                ->native(false);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFilters(): array
    {
        $timezone = $this->settings()->timezone();
        $defaults = ReportFilters::currentMonth($timezone);

        return [
            'from' => $defaults->fromDate($timezone),
            'to' => $defaults->toDate($timezone),
            'owner_id' => null,
            'team_id' => null,
            'pipeline_id' => static::pipelineIsRequired() ? self::defaultPipelineId() : null,
            'group_by' => static::defaultGroupBy(),
        ];
    }

    private function export(string $format): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $viewer = $this->actor();
        $filters = $this->filters();
        $rows = $this->reportRows($viewer, $filters);
        $timezone = $this->settings()->timezone();

        return ReportRowExporter::download(
            $format,
            sprintf('report-%s-%s-%s', static::reportKey(), $filters->fromDate($timezone), $filters->toDate($timezone)),
            $this->labelHeading($filters),
            $this->reportColumns(),
            $this->reportFormats(),
            $rows,
            $rows->isEmpty() ? null : $this->reportTotals($rows),
        );
    }

    /**
     * Chart.js options: stacked axes when asked, legend and tooltip mirrored
     * for Arabic, the first category at the reading start.
     *
     * @return array<string, mixed>
     */
    private static function chartOptions(bool $rtl, bool $stacked): array
    {
        $direction = $rtl ? 'rtl' : 'ltr';

        return [
            'plugins' => [
                'legend' => ['rtl' => $rtl, 'textDirection' => $direction],
                'tooltip' => ['rtl' => $rtl, 'textDirection' => $direction],
            ],
            'scales' => [
                'x' => ['stacked' => $stacked, 'reverse' => $rtl],
                'y' => ['stacked' => $stacked, 'beginAtZero' => true],
            ],
        ];
    }

    /**
     * Whether the report has nothing to show. The row count alone will not
     * say: the zero-filling reports (lead, source, pipeline, forecast)
     * always emit one line per active lookup, so a period without a single
     * record still produces a full table. A report is empty when every
     * figure of every row is zero (or blank), and then the page shows the
     * translated empty state instead of a table and a chart of zeros.
     *
     * @param  Collection<int, ReportRow>  $rows
     */
    private static function rowsAreEmpty(Collection $rows): bool
    {
        return $rows->isEmpty() || $rows->every(static fn (ReportRow $row): bool => collect($row->values)->every(
            static fn (int|float|string $value): bool => is_string($value) ? $value === '' : (float) $value === 0.0,
        ));
    }

    /** The default active pipeline, else the first active one. */
    private static function defaultPipelineId(): ?int
    {
        $id = Pipeline::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function settings(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }
}
