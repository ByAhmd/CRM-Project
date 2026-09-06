<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Lead;
use App\Models\User;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\LeadFunnelMetrics;
use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The open lead funnel as a doughnut (plan section 3.9): one segment per
 * open status, painted with the status's own badge colour resolved from
 * the panel palette, counted inside the viewer's scope (D-4) and narrowed
 * by the owner and team filters. Empty when no open lead is in reach.
 */
final class LeadsByStatusChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Lead::class);
    }

    public function getHeading(): string
    {
        return __('dashboard.charts.leads_by_status');
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
        $series = app(LeadFunnelMetrics::class)->leadsByStatusSeries($this->viewer(), $this->filters());
        $counts = array_column($series, 'count');

        if (array_sum($counts) === 0) {
            return [];
        }

        return [
            'datasets' => [
                [
                    'label' => __('dashboard.charts.dataset_leads'),
                    'data' => $counts,
                    'backgroundColor' => array_map(static fn (array $entry): string => self::paletteColor($entry['color']->value), $series),
                ],
            ],
            'labels' => array_column($series, 'label'),
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
        return 'doughnut';
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
        ];
    }

    /** The 500 shade of a panel colour, so the chart follows the palette instead of a hardcoded hex. */
    private static function paletteColor(string $name): string
    {
        $shades = FilamentColor::getColor($name) ?? FilamentColor::getColor('gray') ?? [];

        return (string) ($shades[500] ?? '');
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
