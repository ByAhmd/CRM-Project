@php
    use App\Filament\Pages\TasksBoard;
    use App\Services\Tasks\TaskBoardFeed;
    use App\Enums\TaskStatus;
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        {{-- The two views (D-14 by assignee, D-27 by status) and «مهامي فقط», which narrows either one. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::tabs :label="__('tasks.board_ux.views.label')">
                <x-filament::tabs.item
                    :active="$boardView === TasksBoard::VIEW_ASSIGNEE"
                    icon="heroicon-o-users"
                    wire:click="$set('boardView', '{{ TasksBoard::VIEW_ASSIGNEE }}')"
                >
                    {{ __('tasks.board_ux.views.assignee') }}
                </x-filament::tabs.item>

                <x-filament::tabs.item
                    :active="$boardView === TasksBoard::VIEW_STATUS"
                    icon="heroicon-o-view-columns"
                    wire:click="$set('boardView', '{{ TasksBoard::VIEW_STATUS }}')"
                >
                    {{ __('tasks.board_ux.views.status') }}
                </x-filament::tabs.item>
            </x-filament::tabs>

            <x-filament::button
                :color="$mine ? 'primary' : 'gray'"
                :outlined="! $mine"
                :icon="$mine ? 'heroicon-m-check' : 'heroicon-o-user'"
                size="sm"
                class="crm-board-mine"
                wire:click="$toggle('mine')"
                aria-pressed="{{ $mine ? 'true' : 'false' }}"
            >
                {{ __('tasks.filters.mine') }}
            </x-filament::button>
        </div>

        @if ($boardView === TasksBoard::VIEW_STATUS)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('tasks.board_ux.helpers.status_view') }}
            </p>

            {{--
                One column per status. Dropping a card calls moveTask() with
                the column it left and the one it reached; the server decides,
                and every drop re-renders the columns (the revision is in the
                key), so a refused card returns to where it was. The sortable
                animation is switched off for readers who ask for less motion.
                snap-x + snap-start: a phone swipe settles on a whole column,
                as the deal board scrolls (D-12). On touch a card is dragged
                only after a short press (Sortable's delayOnTouchOnly), so a
                swipe across a card scrolls the columns instead of picking it
                up; the status pill stays the tap-only way to move it.
            --}}
            <div wire:key="status-board-{{ $revision }}" class="flex snap-x items-start gap-4 overflow-x-auto pb-4">
                @foreach ($statusColumns as $column)
                    @php
                        $status = $column['status'];
                        $headingId = 'tasks-board-status-'.$status->value;
                    @endphp

                    <section
                        wire:key="status-{{ $status->value }}"
                        aria-labelledby="{{ $headingId }}"
                        class="crm-board-column flex w-72 min-w-72 shrink-0 snap-start flex-col rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                    >
                        <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                            <h2 id="{{ $headingId }}" class="flex min-w-0 items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                <x-task.status-pill :status="$status" />

                                @if ($status === TaskStatus::Completed)
                                    <span class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('tasks.board_ux.columns.this_week') }}</span>
                                @endif
                            </h2>

                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                {{ trans_choice('tasks.board_ux.columns.count', $column['total'], ['count' => $column['total']]) }}
                            </span>
                        </div>

                        <div
                            x-sortable
                            x-sortable-group="tasks"
                            x-bind:data-sortable-animation-duration="window.matchMedia('(prefers-reduced-motion: no-preference)').matches ? 200 : 0"
                            x-init="$nextTick(() => { if ($el.sortable) { $el.sortable.option('delay', 250); $el.sortable.option('delayOnTouchOnly', true) } })"
                            data-status="{{ $status->value }}"
                            x-on:end.stop="if ($event.from !== $event.to) { $wire.moveTask(parseInt($event.item.getAttribute('x-sortable-item')), $event.from.dataset.status, $event.to.dataset.status) }"
                            class="crm-board-drop flex min-h-24 flex-col gap-2 p-2"
                        >
                            @forelse ($column['tasks'] as $task)
                                @include('filament.pages.tasks-board-card', ['task' => $task, 'showAssignee' => true, 'sortable' => true])
                            @empty
                                <p class="px-1 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    {{ $mine ? __('tasks.board_ux.empty.column_mine') : __('tasks.board_ux.empty.column') }}
                                </p>
                            @endforelse

                            @if ($column['more'] > 0)
                                <p class="px-1 py-1 text-center text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice('tasks.pages.board.more', $column['more'], ['count' => $column['more']]) }}
                                </p>
                            @endif
                        </div>
                    </section>
                @endforeach
            </div>
        @else
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
                <div class="flex flex-col items-center gap-3 rounded-xl bg-white px-4 py-12 text-center ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $mine ? __('tasks.board_ux.empty.board_mine') : __('tasks.pages.board.empty') }}
                    </p>

                    @if ($mine)
                        <x-filament::button color="gray" size="sm" wire:click="$set('mine', false)">
                            {{ __('tasks.board_ux.actions.show_everyone') }}
                        </x-filament::button>
                    @endif
                </div>
            @else
                {{--
                    snap-x (proximity) + snap-start: a phone swipe settles on a
                    whole column, exactly as the deal board scrolls (D-12).
                --}}
                <div wire:key="assignee-board-{{ $revision }}" class="flex snap-x items-start gap-4 overflow-x-auto pb-4">
                    @foreach ($columns as $column)
                        @php
                            $assignee = $column['assignee'];
                        @endphp

                        <section
                            wire:key="assignee-{{ $assignee?->getKey() ?? 'none' }}"
                            class="crm-board-column flex w-72 min-w-72 shrink-0 snap-start flex-col rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                        >
                            <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                                <h2 class="flex min-w-0 items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                    @if ($assignee !== null)
                                        {{ \App\Filament\Support\InitialsAvatar::forName($assignee->name) }}
                                    @endif

                                    <span class="truncate">
                                        @if ($assignee === null)
                                            {{ __('tasks.pages.board.unassigned') }}
                                        @elseif ($assignee->trashed())
                                            {{ __('tasks.pages.board.deleted_assignee', ['name' => $assignee->name]) }}
                                        @else
                                            {{ $assignee->name }}
                                        @endif
                                    </span>
                                </h2>

                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice('tasks.pages.board.open_count', $column['total'], ['count' => $column['total']]) }}
                                </span>
                            </div>

                            <div class="flex min-h-24 flex-col gap-2 p-2">
                                @foreach ($column['tasks'] as $task)
                                    @include('filament.pages.tasks-board-card', ['task' => $task, 'showAssignee' => false, 'sortable' => false])
                                @endforeach

                                @if ($column['more'] > 0)
                                    <p class="px-1 py-1 text-center text-xs text-gray-500 dark:text-gray-400">
                                        {{ trans_choice('tasks.pages.board.more', $column['more'], ['count' => $column['more']]) }}
                                    </p>
                                @endif
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
