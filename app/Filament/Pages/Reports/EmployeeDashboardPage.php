<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Enums\NavigationGroup;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Filament\Concerns\BuildsPageUrlsInOwningPanel;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use App\Services\Statistics\Reports\ActivityReport;
use App\Services\Statistics\Reports\EmployeeDashboard;
use App\Services\Statistics\Reports\EmployeeDashboardSnapshot;
use App\Services\Statistics\Reports\ReportRow;
use App\Support\Filament\PanelUrl;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;

/**
 * The employee dashboard (decision D-26): «لوحة الموظف» at
 * `/reports/employee` — the reader picks one employee and sees their task
 * work on one screen. Presentation only: EmployeeDashboard decides who is in
 * reach and computes every figure; this page gates, asks and formats.
 *
 * The page opens for holders of `reports.view` inside the Reports workspace
 * (D-23). The chosen employee lives in the address (`?employee=<id>`) and is
 * read once, on mount: an id outside the reader's reach — forged, deleted
 * from reach, or not an id at all — answers 404 before anything renders,
 * never a blank or partial page. The id is locked; choosing someone else in
 * the select navigates to that employee's address, which is checked the
 * same way. With no employee chosen the page shows a translated empty state.
 *
 * Every task on the lists links to the task only when the reader may open
 * it (TaskPolicy::view, through the service) and may enter the Tasks
 * workspace (PanelUrl::forReader); otherwise its title is plain text (A-20).
 * Empty rates and averages read «—», never 0%. Read-only: no action writes.
 *
 * @property-read Schema $form
 * @property-read Schema $kpis
 */
final class EmployeeDashboardPage extends Page
{
    use BuildsPageUrlsInOwningPanel;

    protected static ?string $slug = 'employee';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.reports.employee-dashboard';

    /**
     * The select's state: only ever used to navigate to another address.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** The employee shown, admitted on mount from the address; never written by the browser. */
    #[Locked]
    public ?int $employeeId = null;

    private ?EmployeeDashboardSnapshot $snapshot = null;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Reports;
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.navigation.employee');
    }

    public function getTitle(): string
    {
        return __('reports.pages.employee.title');
    }

    public function getSubheading(): string
    {
        return __('reports.pages.employee.description');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::ReportsView->value);
    }

    public function mount(): void
    {
        $requested = request()->query('employee');

        if ($requested !== null && $requested !== '') {
            $id = is_string($requested) && ctype_digit($requested) ? (int) $requested : null;

            abort_if($id === null || $this->dashboard()->employee($this->actor(), $id) === null, 404);

            $this->employeeId = $id;
        }

        $this->form->fill(['employee' => $this->employeeId]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('reports.employee.sections.choose'))
                    ->schema([
                        Select::make('employee')
                            ->label(__('reports.employee.fields.employee'))
                            ->placeholder(__('reports.employee.placeholders.employee'))
                            ->options(fn (): array => $this->employeeOptions())
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (mixed $state) => $this->choose($state)),
                    ])
                    ->columns(1),
            ])
            ->columns(1)
            ->statePath('data');
    }

    /**
     * The KPI tiles: what stands now, and how the work went this week and
     * this month — the dashboard's stat tiles, so the count-up applies to
     * the whole numbers and nothing else.
     */
    public function kpis(Schema $schema): Schema
    {
        $snapshot = $this->snapshot();

        if ($snapshot === null) {
            return $schema->components([])->columns(1);
        }

        return $schema
            ->components([
                Section::make(__('reports.employee.sections.now'))
                    ->schema([
                        Stat::make(__('reports.employee.kpis.open'), self::count($snapshot->open))
                            ->icon(Heroicon::OutlinedClipboardDocumentList)
                            ->color('primary'),
                        Stat::make(__('reports.employee.kpis.overdue'), self::count($snapshot->overdue))
                            ->description(__('reports.employee.kpis.overdue_description'))
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->color($snapshot->overdue > 0 ? 'danger' : 'gray'),
                        Stat::make(__('reports.employee.kpis.due_today'), self::count($snapshot->dueToday))
                            ->description(__('reports.employee.kpis.due_today_description'))
                            ->icon(Heroicon::OutlinedCalendarDays)
                            ->color('warning'),
                    ])
                    ->columns(['default' => 1, 'md' => 3])
                    ->contained(false),
                Section::make(__('reports.employee.sections.performance'))
                    ->schema([
                        Stat::make(__('reports.employee.kpis.completed_week'), self::count($snapshot->completedThisWeek))
                            ->icon(Heroicon::OutlinedCheckCircle)
                            ->color('success'),
                        Stat::make(__('reports.employee.kpis.completed_month'), self::count($snapshot->completedThisMonth))
                            ->icon(Heroicon::OutlinedCalendar)
                            ->color('success'),
                        Stat::make(__('reports.employee.kpis.on_time_rate'), $snapshot->onTimeRate === null
                            ? __('common.placeholders.empty')
                            : BaseReportPage::format(ReportRow::FORMAT_PERCENT, $snapshot->onTimeRate))
                            ->description(__('reports.employee.kpis.on_time_rate_description'))
                            ->icon(Heroicon::OutlinedClock)
                            ->color('primary'),
                        // A duration is words around numbers, not a count:
                        // marked static so the count-up leaves it as it is.
                        Stat::make(__('reports.employee.kpis.average_time_to_finish'), new HtmlString(
                            '<span data-crm-static>'.e(BaseReportPage::format(ReportRow::FORMAT_DURATION, $snapshot->averageSecondsToFinish ?? ReportRow::NOT_MEASURED)).'</span>',
                        ))
                            ->description(__('reports.employee.kpis.average_time_to_finish_description'))
                            ->icon(Heroicon::OutlinedArrowTrendingUp)
                            ->color('info'),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->contained(false),
            ])
            ->columns(1);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $snapshot = $this->snapshot();

        if ($snapshot === null) {
            return [
                'snapshot' => null,
                'hasEmployees' => $this->dashboard()->employees($this->actor())->exists(),
            ];
        }

        $rtl = app()->getLocale() === 'ar';

        return [
            'snapshot' => $snapshot,
            'taskUrls' => $this->taskUrls($snapshot),
            'chart' => [
                'labels' => array_map(static fn (array $week): string => ActivityReport::dayLabel($week['start']->toDateString()), $snapshot->trend),
                'datasets' => [
                    ['label' => __('reports.employee.chart.completed'), 'data' => array_map(static fn (array $week): int => $week['completed'], $snapshot->trend), 'color' => 'success'],
                ],
            ],
            'chartOptions' => BaseReportPage::chartOptions($rtl, false),
            'palette' => BaseReportPage::CHART_PALETTE,
        ];
    }

    /** A count in the reader's locale. */
    public static function count(int $value): string
    {
        return (string) Number::format($value, locale: app()->getLocale());
    }

    /** A date on the organisation calendar as the reader's calendar writes it. */
    public static function date(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->locale(app()->getLocale())->isoFormat('LL');
    }

    /** A moment on the organisation clock as the reader's calendar writes it. */
    public static function moment(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->locale(app()->getLocale())->isoFormat('LLL');
    }

    /**
     * The employees in the reader's reach, an account that can no longer
     * sign in marked as such.
     *
     * @return array<int, string>
     */
    private function employeeOptions(): array
    {
        $options = [];

        foreach ($this->dashboard()->employees($this->actor())->get(['users.id', 'users.name', 'users.status', 'users.deleted_at']) as $employee) {
            $options[(int) $employee->getKey()] = match (true) {
                $employee->trashed() => __('reports.employee.options.deleted', ['name' => $employee->name]),
                $employee->status === UserStatus::Disabled => __('reports.employee.options.disabled', ['name' => $employee->name]),
                $employee->status === UserStatus::Pending => __('reports.employee.options.pending', ['name' => $employee->name]),
                default => $employee->name,
            };
        }

        return $options;
    }

    /** Navigates to the chosen employee's address, where mount() admits or refuses them. */
    private function choose(mixed $state): void
    {
        abort_unless(self::canAccess(), 403);

        $id = match (true) {
            is_int($state) => $state,
            is_string($state) && ctype_digit($state) => (int) $state,
            default => null,
        };

        $this->redirect(self::getUrl($id === null ? [] : ['employee' => $id]));
    }

    /** The dashboard of the admitted employee, computed once per request; null when none is chosen. */
    private function snapshot(): ?EmployeeDashboardSnapshot
    {
        if ($this->employeeId === null) {
            return null;
        }

        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        abort_unless(self::canAccess(), 403);

        $viewer = $this->actor();
        $employee = $this->dashboard()->employee($viewer, $this->employeeId);

        abort_if($employee === null, 404);

        return $this->snapshot = $this->dashboard()->snapshot($viewer, $employee);
    }

    /**
     * The task page of every listed task the reader may open, by task id;
     * a task missing here renders as plain text.
     *
     * @return array<int, string>
     */
    private function taskUrls(EmployeeDashboardSnapshot $snapshot): array
    {
        $ids = [];

        foreach ([$snapshot->overdueTasks, $snapshot->stalledTasks, $snapshot->checklistOpenTasks] as $list) {
            foreach ($list->tasks as $task) {
                if ($task->readable) {
                    $ids[] = $task->id;
                }
            }
        }

        foreach ($snapshot->latestActivity as $entry) {
            if ($entry->readable) {
                $ids[] = $entry->taskId;
            }
        }

        $urls = [];
        $viewer = $this->actor();

        foreach (array_unique($ids) as $id) {
            $url = PanelUrl::forReader($viewer, TaskResource::class, static fn (): string => TaskResource::getUrl('view', ['record' => $id]));

            if ($url !== null) {
                $urls[$id] = $url;
            }
        }

        return $urls;
    }

    private function dashboard(): EmployeeDashboard
    {
        return app(EmployeeDashboard::class);
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
