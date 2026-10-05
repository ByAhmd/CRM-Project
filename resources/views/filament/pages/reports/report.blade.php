@php
    use App\Filament\Pages\Reports\BaseReportPage;
    use App\Services\Statistics\Reports\ReportRow;

    // Figures sit in an LTR isolate; a duration is words in the reader's
    // language («يومان و5 ساعات», D-21), so it is rendered like text.
    $numeric = static fn (string $format): bool => $format !== ReportRow::FORMAT_TEXT && $format !== ReportRow::FORMAT_DURATION;
    $cell = static fn (string $format, int|float|string $value): string => $format === ReportRow::FORMAT_DURATION ? BaseReportPage::format($format, $value) : (string) $value;
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
            @include('filament.pages.reports.chart', [
                'chart' => $chart,
                'chartType' => $chartType,
                'chartOptions' => $chartOptions,
                'chartId' => $chartId,
                'palette' => $palette,
                'heading' => __('reports.sections.chart'),
                'canvasLabel' => $this->getTitle(),
            ])

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
                                        {{-- A row the reader may open more about (D-26: an employee's
                                             dashboard from the task report) links; every other row,
                                             and a row out of the reader's reach, stays plain text. --}}
                                        @if (isset($rowUrls[$loop->index]))
                                            <a {{ \Filament\Support\generate_href_html($rowUrls[$loop->index]) }} class="crm-report-row-link">{{ $row->label }}</a>
                                        @else
                                            {{ $row->label }}
                                        @endif
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
                                                {{ $cell($format, $row->value($key)) }}
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
                                                {{ $cell($format, $totals->value($key)) }}
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
