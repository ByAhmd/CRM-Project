<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\User;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\PipelineMetrics;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The open deals nobody touched for a fortnight (plan section 3.9), the
 * longest untouched first, each row opening the deal. Fed by
 * PipelineMetrics inside the viewer's scope (D-4) and narrowed by the
 * owner, team and pipeline filters; capped at the ten oldest. Money is in
 * the organisation currency (D-8).
 */
final class StaleDealsWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 7;

    /**
     * Six columns of deal data need the whole row at every width; a scalar
     * 'full' would only apply from lg (gridColumn() wraps a scalar as
     * ['lg' => …]), so the default breakpoint is declared explicitly.
     */
    protected int|string|array $columnSpan = ['default' => 'full'];

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Deal::class);
    }

    public function table(Table $table): Table
    {
        $viewer = $this->viewer();
        $filters = $this->filters();

        return $table
            ->heading(__('dashboard.tables.stale_deals'))
            ->description(__('dashboard.tables.stale_deals_description', ['days' => PipelineMetrics::STALE_DAYS]))
            ->query(static fn (): Builder => app(PipelineMetrics::class)
                ->staleDealsQuery($viewer, $filters)
                ->with(['account', 'stage', 'owner'])
                ->limit(PipelineMetrics::STALE_LIMIT))
            ->columns([
                TextColumn::make('title')
                    ->label(__('dashboard.tables.columns.title'))
                    ->weight('semibold'),

                // The glance is the deal and the money going stale; who and
                // where step in from md/lg (same budget rule as the resource
                // tables, MobileColumnBudgetTest).
                TextColumn::make('account.name')
                    ->label(__('dashboard.tables.columns.account'))
                    ->placeholder(__('common.placeholders.empty'))
                    ->visibleFrom('lg'),

                TextColumn::make('stage')
                    ->label(__('dashboard.tables.columns.stage'))
                    ->state(static fn (Deal $record): ?string => $record->stage?->display_name)
                    ->badge()
                    ->color(static fn (Deal $record): ?string => $record->stage?->color->value)
                    ->visibleFrom('md'),

                TextColumn::make('amount')
                    ->label(__('dashboard.tables.columns.amount'))
                    ->money(currency: static fn (): string => DealResource::currency(), locale: static fn (): string => app()->getLocale()),

                TextColumn::make('owner.name')
                    ->label(__('dashboard.tables.columns.owner'))
                    ->placeholder(__('assignment.placeholders.unassigned'))
                    ->visibleFrom('lg'),

                TextColumn::make('last_activity')
                    ->label(__('dashboard.tables.columns.last_activity_at'))
                    ->state(static fn (Deal $record): ?CarbonInterface => $record->last_activity_at ?? $record->updated_at)
                    ->dateTime('Y-m-d H:i', timezone: DashboardFilters::timezone())
                    ->color('danger')
                    ->visibleFrom('md'),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByRaw('COALESCE(deals.last_activity_at, deals.updated_at) ASC')->orderBy('deals.id'))
            ->recordUrl(static fn (Deal $record): string => DealResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedSparkles)
            ->emptyStateHeading(__('dashboard.empty.stale_deals'))
            ->emptyStateDescription(__('dashboard.empty.stale_deals_description'));
    }

    private function filters(): DashboardFilters
    {
        return DashboardFilters::fromArray($this->pageFilters, $this->viewer());
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
