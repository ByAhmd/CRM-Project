@php
    use App\Filament\Pages\Reports\BaseReportPage;
    use App\Services\Statistics\Reports\ReportRow;
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Widgets\View\Components\ChartWidgetComponent;

    $numeric = static fn (string $format): bool => $format !== ReportRow::FORMAT_TEXT;
    $chartKey = md5(json_encode([$chart, $chartType, $chartOptions]));
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        <form wire:submit="run" class="flex flex-col gap-4">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-play">
                    {{ __('reports.filters.run') }}
                </x-filament::button>

                <x-filament::button type="button" color="gray" wire:click="resetFilters">
                    {{ __('reports.filters.reset') }}
                </x-filament::button>
            </div>
        </form>

        {{--
            $isEmpty is computed from the figures, not from the row count: the
            zero-filling reports always emit one line per active lookup, so a
            period without a single record would otherwise render a table and a
            chart of zeros instead of the empty state.
        --}}
        @if ($isEmpty)
            <x-filament::section>
                <p class="text-center">
                    {{ __('reports.empty.no_data') }}
                </p>
            </x-filament::section>
        @else
            {{--
                The chart is Filament's own Chart.js component, fed by the service's
                chart() data. Dataset colours arrive as palette names; the hidden
                spans below carry the panel's colour for each name in the current
                theme, and crmReportChartData() reads their computed colour before
                the canvas is drawn, so nothing hard-codes a hex value. The wrapper
                is keyed on the data: a new run replaces it and the chart is
                re-created with the new figures. Every surface is the panel's own
                section component, so the reports follow the panel's colours in
                both themes and both directions.
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
                :heading="__('reports.sections.chart')"
                wire:key="{{ $chartId }}-{{ $chartKey }}"
                id="{{ $chartId }}"
                class="fi-wi-chart"
                :aria-label="__('reports.sections.chart')"
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
                        aria-label="{{ $this->getTitle() }}"
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

            <x-filament::section :heading="__('reports.sections.table')" :aria-label="__('reports.sections.table')">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-current/10">
                                <th scope="col" class="px-3 py-2 text-start font-medium">
                                    {{ $labelHeading }}
                                </th>

                                @foreach ($columns as $key => $heading)
                                    {{-- Uniform classic (owner, 2026-09-21): every column —
                                         numeric included — aligns to the start, header and
                                         values on the same edge; the crm-ltr isolate below
                                         keeps the digits in reading order. --}}
                                    <th scope="col" class="px-3 py-2 text-start font-medium">
                                        {{ $heading }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($rows as $row)
                                <tr wire:key="report-row-{{ $loop->index }}" class="border-b border-current/10">
                                    <td class="px-3 py-2 text-start font-medium">
                                        {{ $row->label }}
                                    </td>

                                    @foreach ($columns as $key => $heading)
                                        @php
                                            $format = $formats[$key] ?? ReportRow::FORMAT_TEXT;
                                        @endphp

                                        @if ($numeric($format))
                                            <td class="px-3 py-2 text-start">
                                                <span class="crm-ltr">{{ BaseReportPage::format($format, $row->value($key)) }}</span>
                                            </td>
                                        @else
                                            <td class="px-3 py-2 text-start">
                                                {{ $row->value($key) }}
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>

                        @if ($totals !== null)
                            <tfoot>
                                <tr class="border-t border-current/20 font-semibold">
                                    <td class="px-3 py-2 text-start">
                                        {{ $totals->label }}
                                    </td>

                                    @foreach ($columns as $key => $heading)
                                        @php
                                            $format = $formats[$key] ?? ReportRow::FORMAT_TEXT;
                                        @endphp

                                        @if ($numeric($format))
                                            <td class="px-3 py-2 text-start">
                                                <span class="crm-ltr">{{ BaseReportPage::format($format, $totals->value($key)) }}</span>
                                            </td>
                                        @else
                                            <td class="px-3 py-2 text-start">
                                                {{ $totals->value($key) }}
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
