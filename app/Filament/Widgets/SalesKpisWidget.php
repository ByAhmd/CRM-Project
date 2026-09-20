<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\DashboardMetrics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The sales KPI strip (plan section 3.9): the lead funnel of the period,
 * the open pipeline and the deals closed in the period, from
 * DashboardMetrics inside the viewer's scope (D-4). The lead stats show
 * to a viewer who may list leads, the deal stats to one who may list
 * deals; the widget shows when either holds. Money is in the organisation
 * currency (D-8).
 */
final class SalesKpisWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    /**
     * The KPI strip spans the whole grid at every width. A scalar span only
     * applies from the lg breakpoint (the widget view's gridColumn() macro
     * wraps it as ['lg' => …]), so the default breakpoint is declared
     * explicitly; the stats inside carry their own container grid.
     */
    protected int|string|array $columnSpan = ['default' => 'full'];

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->can('viewAny', Lead::class) || $user->can('viewAny', Deal::class));
    }

    /**
     * The figures behind the stats, for tests and for the stats themselves.
     *
     * @return array<string, mixed>
     */
    public function kpis(): array
    {
        return app(DashboardMetrics::class)->salesKpis($this->viewer(), $this->filters());
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $viewer = $this->viewer();
        $kpis = $this->kpis();
        $stats = [];

        if ($viewer->can('viewAny', Lead::class)) {
            $stats[] = Stat::make(__('dashboard.kpis.new_leads'), self::count($kpis['new_leads']))
                ->description(__('dashboard.kpis.new_leads_description', [
                    'qualified' => self::count($kpis['qualified']),
                    'converted' => self::count($kpis['converted']),
                ]))
                ->icon(Heroicon::OutlinedUserPlus)
                ->color('info');

            $stats[] = Stat::make(__('dashboard.kpis.conversion_rate'), self::percentage($kpis['conversion_rate']))
                ->description(__('dashboard.kpis.conversion_rate_description'))
                ->icon(Heroicon::OutlinedFunnel)
                ->color('primary');
        }

        if ($viewer->can('viewAny', Deal::class)) {
            $stats[] = Stat::make(__('dashboard.kpis.open_deals'), self::count($kpis['open_deals_count']))
                ->description(__('dashboard.kpis.open_deals_description', ['amount' => self::money($kpis['open_deals_amount'])]))
                ->icon(Heroicon::OutlinedBriefcase)
                ->color('primary');

            $stats[] = Stat::make(__('dashboard.kpis.weighted_pipeline'), self::money($kpis['weighted_pipeline']))
                ->description(__('dashboard.kpis.weighted_pipeline_description'))
                ->icon(Heroicon::OutlinedScale)
                ->color('info');

            $stats[] = Stat::make(__('dashboard.kpis.won'), self::count($kpis['won_count']))
                ->description(__('dashboard.kpis.won_description', ['amount' => self::money($kpis['won_amount'])]))
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->icon(Heroicon::OutlinedTrophy)
                ->color('success')
                ->chart($kpis['revenue_by_month']);

            $stats[] = Stat::make(__('dashboard.kpis.lost'), self::count($kpis['lost_count']))
                ->description(__('dashboard.kpis.lost_description', ['amount' => self::money($kpis['lost_amount'])]))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger');

            $stats[] = Stat::make(__('dashboard.kpis.win_rate'), self::percentage($kpis['win_rate']))
                ->description(__('dashboard.kpis.win_rate_description'))
                ->icon(Heroicon::OutlinedChartBar)
                ->color($kpis['win_rate'] >= 50 ? 'success' : 'warning');
        }

        return $stats;
    }

    private static function count(int $value): string
    {
        return (string) Number::format($value, locale: app()->getLocale());
    }

    private static function percentage(float $value): string
    {
        return (string) Number::percentage($value, precision: 1, locale: app()->getLocale());
    }

    /** Money formatted for the current locale, in the organisation currency (D-8). */
    private static function money(float $amount): string
    {
        return (string) Number::currency($amount, DealResource::currency(), app()->getLocale());
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
