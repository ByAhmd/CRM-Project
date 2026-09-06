<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\VisibilityLevel;
use App\Filament\Widgets\ActivityCountsWidget;
use App\Filament\Widgets\LeadsByStatusChart;
use App\Filament\Widgets\MyTasksTodayWidget;
use App\Filament\Widgets\PipelineByStageChart;
use App\Filament\Widgets\RevenueWonByMonthChart;
use App\Filament\Widgets\SalesKpisWidget;
use App\Filament\Widgets\StaleDealsWidget;
use App\Filament\Widgets\UpcomingFollowUpsWidget;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Team;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use App\Services\Statistics\DashboardFilters;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The panel landing page (plan section 3.9): the sales KPI strip, the
 * charts and the attention lists, every one fed by a statistics service
 * inside the viewer's visibility scope (D-4) and gated by its own
 * canView().
 *
 * The filters form (period, owner, team, pipeline) is live: every change
 * validates the period, is kept in the session, and reaches the widgets
 * as the reactive `pageFilters` property, which they turn into a
 * DashboardFilters through fromArray() — so an owner or a team outside the
 * viewer's reach is ignored twice, once by the form's options and once by
 * the DTO. The state is also bound to the URL, so a period arriving
 * through the query string (or the session) is validated on mount and
 * replaced by the defaults when it fails: what the form displays is
 * always the state the widgets use. The owner filter only shows to a viewer whose deal visibility
 * reaches a team; the team filter only to one who sees every deal. Every
 * panel user may open the page; what it shows is decided per widget.
 *
 * Two departures from the plan and the constitution are deliberate and
 * recorded here, where the next reader of this form will look:
 *
 * 1. There is no `dashboard.filters.apply` string. The form is live —
 *    every change is applied and persisted at once — so an apply button
 *    would have nothing to do and its label would be a dead key the
 *    localisation audit flags. The key is omitted rather than shipped
 *    unused.
 * 2. The filters Section declares `->columns(['md' => 2, 'xl' => 5])`
 *    instead of the constitution's default `->columns(1)`. Five stacked
 *    controls make an unusable filter bar; this is the standing exception
 *    for filter bars, as elsewhere in the codebase, and the rule the
 *    convention protects — an explicit `columns()` on every Section, never
 *    an implicit one — is still honoured.
 */
final class Dashboard extends BaseDashboard
{
    use HasFiltersForm {
        updatedFilters as persistFilters;
        mountHasFilters as fillFiltersFromRequest;
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboard.navigation');
    }

    public function getTitle(): string
    {
        return __('dashboard.title');
    }

    public function filtersForm(Schema $schema): Schema
    {
        $viewer = $this->viewer();
        $level = app(RecordVisibilityResolver::class)->levelFor($viewer, Deal::class);
        $timezone = DashboardFilters::timezone();

        return $schema
            ->components([
                Section::make(__('dashboard.filters.period'))
                    ->description(__('dashboard.helpers.range', ['days' => DashboardFilters::MAX_RANGE_DAYS]))
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('dashboard.filters.from'))
                            ->native(false)
                            ->format('Y-m-d')
                            ->required()
                            ->default(static fn (): string => Carbon::now($timezone)->startOfMonth()->toDateString()),

                        DatePicker::make('to')
                            ->label(__('dashboard.filters.to'))
                            ->native(false)
                            ->format('Y-m-d')
                            ->required()
                            ->default(static fn (): string => Carbon::now($timezone)->endOfMonth()->toDateString())
                            ->afterOrEqual('from')
                            ->rules([
                                static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    if (self::rangeTooLarge($get('from'), $value)) {
                                        $fail(__('dashboard.validation.range_too_large', ['days' => DashboardFilters::MAX_RANGE_DAYS]));
                                    }
                                },
                            ])
                            ->validationMessages([
                                'after_or_equal' => __('dashboard.validation.to_before_from'),
                            ]),

                        Select::make('owner_id')
                            ->label(__('dashboard.filters.owner'))
                            ->placeholder(__('dashboard.placeholders.all_owners'))
                            ->helperText(__('dashboard.helpers.owner'))
                            ->options(static fn (): array => app(RecordVisibilityResolver::class)
                                ->assignableUsers($viewer, Deal::permissionGroup())
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->visible($level->reachesTeam()),

                        Select::make('team_id')
                            ->label(__('dashboard.filters.team'))
                            ->placeholder(__('dashboard.placeholders.all_teams'))
                            ->options(static fn (): array => Team::query()
                                ->where('is_active', true)
                                ->orderBy('sort')
                                ->orderBy('id')
                                ->get()
                                ->mapWithKeys(static fn (Team $team): array => [$team->getKey() => $team->display_name])
                                ->all())
                            ->native(false)
                            ->visible($level === VisibilityLevel::All),

                        Select::make('pipeline_id')
                            ->label(__('dashboard.filters.pipeline'))
                            ->placeholder(__('dashboard.placeholders.all_pipelines'))
                            ->options(static fn (): array => Pipeline::query()
                                ->where('is_active', true)
                                ->orderBy('sort')
                                ->orderBy('id')
                                ->get()
                                ->mapWithKeys(static fn (Pipeline $pipeline): array => [$pipeline->getKey() => $pipeline->display_name])
                                ->all())
                            ->native(false),
                    ])
                    ->columns(['md' => 2, 'xl' => 5])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Livewire's mount hook of the filters trait: the base fills the form
     * from the query string or the session; the period is then validated
     * and, when it fails, every filter goes back to the defaults so no
     * invalid state is shown or persisted.
     */
    public function mountHasFilters(): void
    {
        $this->fillFiltersFromRequest();

        if ($this->filters === null) {
            return;
        }

        try {
            $this->getFiltersForm()->validate();
        } catch (ValidationException) {
            $this->resetFilters();
            $this->resetErrorBag();
        }
    }

    /**
     * Livewire's hook for every change of the live filters form: the period
     * is validated (the errors land on the fields) and the state is kept in
     * the session as the base trait does.
     */
    public function updatedFilters(): void
    {
        $this->getFiltersForm()->validate();
        $this->persistFilters();
    }

    /** Back to the defaults: the current month, everyone, every pipeline. */
    public function resetFilters(): void
    {
        $this->filters = null;
        $this->getFiltersForm()->fill();
        $this->persistFilters();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetFilters')
                ->label(__('dashboard.filters.reset'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->resetFilters()),
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            SalesKpisWidget::class,
            LeadsByStatusChart::class,
            PipelineByStageChart::class,
            RevenueWonByMonthChart::class,
            MyTasksTodayWidget::class,
            UpcomingFollowUpsWidget::class,
            StaleDealsWidget::class,
            ActivityCountsWidget::class,
        ];
    }

    public function getColumns(): int
    {
        return 2;
    }

    /** Whether the two dates span more than the widest period the dashboard aggregates. */
    private static function rangeTooLarge(mixed $from, mixed $to): bool
    {
        if (! is_string($from) || ! is_string($to) || trim($from) === '' || trim($to) === '') {
            return false;
        }

        try {
            $start = Carbon::parse(trim($from))->startOfDay();
            $end = Carbon::parse(trim($to))->startOfDay();
        } catch (InvalidFormatException) {
            return false;
        }

        return $end->greaterThanOrEqualTo($start) && $start->diffInDays($end) + 1 > DashboardFilters::MAX_RANGE_DAYS;
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
