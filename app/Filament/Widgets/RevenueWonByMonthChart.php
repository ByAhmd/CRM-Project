<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Deal;
use App\Models\User;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\RevenueMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

/**
 * The amount won per month over the last twelve months (plan section
 * 3.9), as a line in the panel's success colour, month labels in the
 * current locale, inside the viewer's scope (D-4) and narrowed by the
 * owner, team and pipeline filters. Empty when nothing was won.
 */
final class RevenueWonByMonthChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $color = 'success';

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Deal::class);
    }

    public function getHeading(): string
    {
        return __('dashboard.charts.revenue_by_month');
    }

    public function getEmptyStateHeading(): string
    {
        return __('dashboard.empty.chart');
    }

    public function getEmptyStateDescription(): string
    {
        return __('dashboard.empty.chart_description');
    }

    /**
     * The chart data, public so tests can assert the labels and datasets.
     *
     * @return array<string, mixed>
     */
    public function chartData(): array
    {
        $series = app(RevenueMetrics::class)->revenueWonByMonth($this->viewer(), $this->filters());
        $amounts = array_column($series, 'amount');

        if (array_sum($amounts) === 0.0) {
            return [];
        }

        return [
            'datasets' => [
                [
                    'label' => __('dashboard.charts.dataset_revenue'),
                    'data' => $amounts,
                    'fill' => 'start',
                ],
            ],
            'labels' => array_map(static fn (array $bucket): string => self::monthLabel($bucket['month']), $series),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        return $this->chartData();
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        $rtl = app()->getLocale() === 'ar';

        return [
            'plugins' => [
                'legend' => [
                    'rtl' => $rtl,
                    'textDirection' => $rtl ? 'rtl' : 'ltr',
                ],
            ],
            'scales' => [
                'x' => ['reverse' => $rtl],
                'y' => ['position' => $rtl ? 'right' : 'left', 'beginAtZero' => true],
            ],
        ];
    }

    /** A 'Y-m' bucket as a short month name and year in the current locale. */
    public static function monthLabel(string $month): string
    {
        return Carbon::createFromFormat('Y-m-d', $month.'-01', DashboardFilters::timezone())
            ->locale(app()->getLocale())
            ->translatedFormat('M Y');
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
