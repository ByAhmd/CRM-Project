@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Widgets\View\Components\ChartWidgetComponent;

    $chartKey = md5(json_encode([$chart, $chartType, $chartOptions]));
@endphp

{{--
    The chart of a report page and of the employee dashboard (D-26): Filament's
    own Chart.js component, fed by the service's chart data. Dataset colours
    arrive as palette names; the hidden spans below carry the panel's colour
    for each name in the current theme, and crmReportChartData() reads their
    computed colour before the canvas is drawn, so nothing hard-codes a hex
    value. The wrapper is keyed on the data: a new run replaces it and the
    chart is re-created with the new figures. Every surface is the panel's own
    section component, so the chart follows the panel's colours in both themes
    and both directions.
--}}
<script>
    window.crmReportChartData ??= function (chartId, data) {
        const root = document.getElementById(chartId)

        for (const dataset of data.datasets ?? []) {
            const sentinel = root?.querySelector('[data-crm-chart-color="' + dataset.color + '"] .fi-wi-chart-border-color')

            if (sentinel) {
                dataset.backgroundColor = getComputedStyle(sentinel).color
                dataset.borderColor = dataset.backgroundColor
            }

            delete dataset.color
        }

        return data
    }
</script>

<x-filament::section
    :heading="$heading"
    wire:key="{{ $chartId }}-{{ $chartKey }}"
    id="{{ $chartId }}"
    class="fi-wi-chart"
    :aria-label="$heading"
>
    @foreach ($palette as $name)
        <span aria-hidden="true" data-crm-chart-color="{{ $name }}" {{ (new FilamentComponentAttributeBag)->color(ChartWidgetComponent::class, $name) }}>
            <span class="fi-wi-chart-border-color"></span>
        </span>
    @endforeach

    <div
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
        wire:ignore
        data-chart-type="{{ $chartType }}"
        x-data="chart({
            cachedData: crmReportChartData(@js($chartId), @js($chart)),
            options: @js($chartOptions),
            type: @js($chartType),
        })"
        {{ (new FilamentComponentAttributeBag)->color(ChartWidgetComponent::class, 'primary')->class(['fi-wi-chart-frame', 'fi-wi-chart-canvas-ctn', 'fi-wi-chart-frame-no-aspect-ratio']) }}
    >
        <canvas
            x-ref="canvas"
            role="img"
            aria-label="{{ $canvasLabel }}"
            style="width: 100%; max-height: 24rem"
        ></canvas>

        <span aria-hidden="true" x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
        <span aria-hidden="true" x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
        <span aria-hidden="true" x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
        <span aria-hidden="true" x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
        <span aria-hidden="true" x-ref="tooltipBackgroundColorElement" class="fi-wi-chart-tooltip-bg-color"></span>
        <span aria-hidden="true" x-ref="tooltipTextColorElement" class="fi-wi-chart-tooltip-text-color"></span>
        <span aria-hidden="true" x-ref="tooltipBorderColorElement" class="fi-wi-chart-tooltip-border-color"></span>
    </div>
</x-filament::section>
