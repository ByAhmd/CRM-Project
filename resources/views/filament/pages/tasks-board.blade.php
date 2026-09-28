@php
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Services\Tasks\TaskBoardFeed;
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ([
                'open' => $stats['open'],
                'in_progress' => $stats['in_progress'],
                'overdue' => $stats['overdue'],
                'due_today' => $stats['due_today'],
                'completed_this_week' => $stats['completed_this_week'],
            ] as $stat => $value)
                <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ __('tasks.pages.board.stats.'.$stat) }}
                    </p>

                    <p @class([
                        'mt-1 text-2xl font-semibold tracking-tight',
                        'text-danger-600 dark:text-danger-400' => $stat === 'overdue' && $value > 0,
                        'text-gray-950 dark:text-white' => $stat !== 'overdue' || $value === 0,
                    ])>
                        {{ $value }}
                    </p>
                </div>
            @endforeach
        </div>

        @if ($truncated)
            <p class="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30">
                {{ __('tasks.pages.board.truncated', ['count' => TaskBoardFeed::MAX_TASKS]) }}
            </p>
        @endif

        @if (count($columns) === 0)
            <p class="rounded-xl bg-white px-4 py-12 text-center text-sm text-gray-500 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-400 dark:ring-white/10">
                {{ __('tasks.pages.board.empty') }}
            </p>
        @else
            {{--
                snap-x (proximity) + snap-start: a phone swipe settles on a
                whole column, exactly as the deal board scrolls (D-12).
            --}}
            <div class="flex snap-x items-start gap-4 overflow-x-auto pb-4">
                @foreach ($columns as $column)
                    @php
                        $assignee = $column['assignee'];
                    @endphp

                    <div
                        wire:key="assignee-{{ $assignee?->getKey() ?? 'none' }}"
                        class="flex w-72 min-w-72 shrink-0 snap-start flex-col rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                    >
                        <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                            <span class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                @if ($assignee === null)
                                    {{ __('tasks.pages.board.unassigned') }}
                                @elseif ($assignee->trashed())
                                    {{ __('tasks.pages.board.deleted_assignee', ['name' => $assignee->name]) }}
                                @else
                                    {{ $assignee->name }}
                                @endif
                            </span>

                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                {{ trans_choice('tasks.pages.board.open_count', $column['total'], ['count' => $column['total']]) }}
                            </span>
                        </div>

                        <div class="flex min-h-24 flex-col gap-2 p-2">
                            @foreach ($column['tasks'] as $task)
                                <div
                                    wire:key="task-{{ $task->getKey() }}"
                                    class="flex flex-col gap-1 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm dark:border-white/10 dark:bg-gray-800"
                                >
                                    @can('view', $task)
                                        <a
                                            href="{{ TaskResource::getUrl('view', ['record' => $task]) }}"
                                            class="font-semibold text-gray-950 hover:underline dark:text-white"
                                            title="{{ __('tasks.actions.view') }}"
                                        >
                                            {{ $task->title }}
                                        </a>
                                    @else
                                        <span class="font-semibold text-gray-950 dark:text-white">
                                            {{ $task->title }}
                                        </span>
                                    @endcan

                                    <div class="flex flex-wrap items-center gap-1">
                                        <x-filament::badge color="gray" size="sm" :icon="$task->kind->getIcon()">
                                            {{ $task->kind->getLabel() }}
                                        </x-filament::badge>

                                        <x-filament::badge :color="$task->priority->getColor()" size="sm">
                                            {{ $task->priority->getLabel() }}
                                        </x-filament::badge>
                                    </div>

                                    @if ($task->due_at !== null)
                                        <span @class([
                                            'text-xs',
                                            'font-medium text-danger-600 dark:text-danger-400' => $task->isOverdue(),
                                            'text-gray-500 dark:text-gray-400' => ! $task->isOverdue(),
                                        ])>
                                            <span class="crm-ltr">{{ $task->due_at->copy()->setTimezone($timezone)->format('Y-m-d H:i') }}</span>
                                        </span>
                                    @endif
                                </div>
                            @endforeach

                            @if ($column['more'] > 0)
                                <p class="px-1 py-1 text-center text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice('tasks.pages.board.more', $column['more'], ['count' => $column['more']]) }}
                                </p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
