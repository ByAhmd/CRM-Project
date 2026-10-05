@php
    use App\Enums\TaskStatus;
    use App\Filament\Pages\Reports\EmployeeDashboardPage;
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Str;

    use function Filament\Support\generate_href_html;

    $lists = $snapshot === null ? [] : [
        'overdue' => $snapshot->overdueTasks,
        'stalled' => $snapshot->stalledTasks,
        'checklist_open' => $snapshot->checklistOpenTasks,
    ];
@endphp

{{--
    The employee dashboard (D-26). Every surface is the panel's own section,
    stat and empty-state component, so it follows the panel's colours in both
    themes and both directions; the few list rules live in the shared theme
    (crm-employee-*), on logical properties. A task title links only when the
    page put its address in $taskUrls (the reader may open the task and enter
    the Tasks workspace); otherwise it is plain text (A-20).
--}}
<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        {{ $this->form }}

        @if ($snapshot === null)
            <x-filament::empty-state
                :heading="$hasEmployees ? __('reports.employee.empty.choose.heading') : __('reports.employee.empty.nobody.heading')"
                :description="$hasEmployees ? __('reports.employee.empty.choose.description') : __('reports.employee.empty.nobody.description')"
                :icon="Heroicon::OutlinedUserCircle"
            />
        @else
            <div class="fi-wi-stats-overview crm-employee-kpis">
                {{ $this->kpis }}
            </div>

            @include('filament.pages.reports.chart', [
                'chart' => $chart,
                'chartType' => 'bar',
                'chartOptions' => $chartOptions,
                'chartId' => 'crm-employee-trend-chart',
                'palette' => $palette,
                'heading' => __('reports.employee.sections.trend'),
                'canvasLabel' => __('reports.employee.sections.trend'),
            ])

            <div class="crm-employee-attention">
                @foreach ($lists as $key => $list)
                    <x-filament::section :heading="__('reports.employee.lists.'.$key.'.heading')" :aria-label="__('reports.employee.lists.'.$key.'.heading')">
                        @if ($list->isEmpty())
                            <p class="crm-employee-empty">{{ __('reports.employee.lists.'.$key.'.empty') }}</p>
                        @else
                            <ul class="crm-employee-list" role="list">
                                @foreach ($list->tasks as $task)
                                    <li class="crm-employee-list-item" wire:key="employee-{{ $key }}-{{ $task->id }}">
                                        @if (isset($taskUrls[$task->id]))
                                            <a {{ generate_href_html($taskUrls[$task->id]) }} class="crm-employee-list-title">{{ $task->title }}</a>
                                        @else
                                            <span class="crm-employee-list-title">{{ $task->title }}</span>
                                        @endif

                                        <span class="crm-employee-list-meta">
                                            @switch($key)
                                                @case('overdue')
                                                    {{ __('reports.employee.lists.overdue.detail', ['date' => EmployeeDashboardPage::date($task->moment), 'days' => trans_choice('reports.employee.lists.days', $task->days)]) }}
                                                    @break
                                                @case('stalled')
                                                    {{ __('reports.employee.lists.stalled.detail', ['date' => EmployeeDashboardPage::date($task->moment), 'days' => trans_choice('reports.employee.lists.days', $task->days)]) }}
                                                    @break
                                                @default
                                                    {{ __('reports.employee.lists.checklist_open.detail', ['date' => EmployeeDashboardPage::date($task->moment), 'progress' => $task->checklist]) }}
                                            @endswitch
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($list->more() > 0)
                                <p class="crm-employee-more">{{ trans_choice('reports.employee.lists.more', $list->more()) }}</p>
                            @endif
                        @endif
                    </x-filament::section>
                @endforeach
            </div>

            <x-filament::section :heading="__('reports.employee.sections.activity')" :aria-label="__('reports.employee.sections.activity')">
                @if ($snapshot->latestActivity === [])
                    <p class="crm-employee-empty">{{ __('reports.employee.empty.activity') }}</p>
                @else
                    <ol class="crm-employee-list" role="list">
                        @foreach ($snapshot->latestActivity as $entry)
                            <li class="crm-employee-list-item" wire:key="employee-activity-{{ $loop->index }}">
                                <span class="crm-employee-activity-head">
                                    <x-filament::badge :color="$entry->kind->getColor()" :icon="$entry->kind->getIcon()" size="sm">
                                        {{ $entry->kind->getLabel() }}
                                    </x-filament::badge>

                                    @if ($entry->status instanceof TaskStatus)
                                        <x-filament::badge :color="$entry->status->getColor()" size="sm">
                                            {{ $entry->status->getLabel() }}
                                        </x-filament::badge>
                                    @endif

                                    @if (isset($taskUrls[$entry->taskId]))
                                        <a {{ generate_href_html($taskUrls[$entry->taskId]) }} class="crm-employee-list-title">{{ $entry->taskTitle }}</a>
                                    @else
                                        <span class="crm-employee-list-title">{{ $entry->taskTitle }}</span>
                                    @endif
                                </span>

                                {{-- The thread's text shows only on a task the reader may open (TaskPolicy::view). --}}
                                @if ($entry->readable && $entry->body !== '')
                                    <span class="crm-employee-activity-body">{{ Str::limit($entry->body, 160) }}</span>
                                @endif

                                <time class="crm-employee-list-meta" datetime="{{ $entry->at->toIso8601String() }}">{{ EmployeeDashboardPage::moment($entry->at) }}</time>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
