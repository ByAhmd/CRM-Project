<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Deal;
use App\Models\User;
use App\Services\Statistics\DashboardFilters;
use App\Services\Statistics\PipelineMetrics;
use Filament\Support\Facades\FilamentColor;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * The open deals per stage of the selected pipeline (plan section 3.9),
 * as two bars per stage — the amount and the weighted amount (D-8) — with
 * the stage names in the current locale, inside the viewer's scope (D-4)
 * and narrowed by the owner, team and pipeline filters. The default
 * pipeline is charted when none was chosen, and the heading then names it
 * — the KPI strip above spans every pipeline, so the bars are not meant to
 * add up to it. Empty when no open deal is in reach.
 */
final class PipelineByStageChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    /**
     * Full width alone on phones, half the grid from md up so it pairs
     * with the lead funnel chart — declared per breakpoint instead of
     * relying on the grid's implicit auto placement.
     */
    protected int|string|array $columnSpan = ['default' => 'full', 'md' => 1];

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Deal::class);
    }

    /**
     * With no pipeline chosen the bars cover the default pipeline while
     * the KPI strip above spans every pipeline, so the heading names the
     * pipeline it charts and the two readings cannot be confused.
     */
    public function getHeading(): string
    {
        $filters = $this->filters();

        if ($filters->pipelineId !== null) {
            return __('dashboard.charts.pipeline_by_stage');
        }

        $pipeline = app(PipelineMetrics::class)->chartedPipeline($filters);

        return $pipeline === null
            ? __('dashboard.charts.pipeline_by_stage')
            : __('dashboard.charts.pipeline_by_stage_default', ['pipeline' => $pipeline->display_name]);
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
        $stages = app(PipelineMetrics::class)->byStage($this->viewer(), $this->filters());

        if (array_sum(array_column($stages, 'count')) === 0) {
            return [];
        }

        return [
            'datasets' => [
                [
                    'label' => __('dashboard.charts.dataset_amount'),
                    'data' => array_column($stages, 'amount'),
                    'backgroundColor' => self::paletteColor('primary'),
                ],
                [
                    'label' => __('dashboard.charts.dataset_weighted'),
                    'data' => array_column($stages, 'weighted'),
                    'backgroundColor' => self::paletteColor('info'),
                ],
            ],
            'labels' => array_column($stages, 'label'),
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
