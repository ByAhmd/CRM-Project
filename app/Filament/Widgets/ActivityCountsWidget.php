<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\User;
use App\Services\Statistics\ActivityMetrics;
use App\Services\Statistics\DashboardFilters;
use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The activities logged in the period, one bar per kind (plan section
 * 3.9), painted with the kind's own colour from the panel palette, inside
 * the viewer's scope (D-4) and narrowed by the owner and team filters.
 *
 * Counted by kind over the filtered period rather than per day over a
 * fixed window, so the chart answers the same question as the rest of the
 * page for the same filters; ActivityMetrics::perDay() stays available
 * for a trailing-window view. Empty when nothing was logged.
 */
final class ActivityCountsWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 8;

    /**
     * The LAST widget of the dashboard, with no partner: at half width a
     * permanently empty cell would sit beside it from md up, so it spans the
     * row at every breakpoint like the KPI strip.
     */
    protected int|string|array $columnSpan = ['default' => 'full'];

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Activity::class);
    }

    public function getHeading(): string
    {
        return __('dashboard.charts.activity_counts');
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
        $counts = app(ActivityMetrics::class)->countsByKind($this->viewer(), $this->filters());

        if (array_sum($counts) === 0) {
            return [];
        }

        $kinds = ActivityKind::cases();

        return [
            'datasets' => [
                [
                    'label' => __('dashboard.charts.dataset_count'),
                    'data' => array_map(static fn (ActivityKind $kind): int => $counts[$kind->value] ?? 0, $kinds),
                    'backgroundColor' => array_map(static fn (ActivityKind $kind): string => self::paletteColor($kind->getColor()), $kinds),
                ],
            ],
            'labels' => array_map(static fn (ActivityKind $kind): string => $kind->getLabel(), $kinds),
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
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        $rtl = app()->getLocale() === 'ar';

        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['reverse' => $rtl],
                'y' => ['position' => $rtl ? 'right' : 'left', 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
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
