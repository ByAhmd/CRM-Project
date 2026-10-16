{{--
    One tasks-board card (D-14, D-27): the title (a link only where the
    reader's `view` policy allows), the priority flag, the status pill, the
    checklist meter, who holds the task and the relative due date.

    The pill is the keyboard and touch alternative to dragging: where the
    reader may move the task it opens a menu of exactly the moves the policy
    allows (TaskActions::transitionsFor), each mounting the page action that
    re-checks and writes through TaskService; elsewhere it is a plain pill.
    A card is draggable only on the by-status view and only when one of the
    reader's moves leads to a column on the board.

    Who holds it (D-28): on the by-status view everyone (avatars, the first
    assignee's name, «+N»); in an assignee's column, which already names
    them, only the colleagues sharing the task with them.

    Expects: $task (Task, Task::ASSIGNEE_RELATIONS eager-loaded, checklist
    counts on the query), $showAssignee (bool), $sortable (bool) and, when
    $showAssignee is false, $holder (the column's assignee, or null).
--}}
@php
    use App\Filament\Pages\TasksBoard;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Support\TaskActions;
    use App\Filament\Support\TaskVisuals;

    $moves = TaskActions::transitionsFor($task);
    $draggable = $sortable && TasksBoard::draggable($moves);
    $holders = $showAssignee ? TaskVisuals::assignees($task) : TaskVisuals::coHolders($task, $holder ?? null);
@endphp

<article
    wire:key="task-{{ $task->getKey() }}"
    @if ($draggable)
        x-sortable-item="{{ $task->getKey() }}"
        x-sortable-handle
    @endif
    data-task-status="{{ $task->status->value }}"
    @class([
        'crm-board-card flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm dark:border-white/10 dark:bg-gray-800',
        'crm-board-card--draggable cursor-grab' => $draggable,
    ])
>
    <div class="flex items-start justify-between gap-2">
        @can('view', $task)
            <a
                href="{{ TaskResource::getUrl('view', ['record' => $task]) }}"
                wire:navigate
                class="min-w-0 font-semibold text-gray-950 hover:underline dark:text-white"
                title="{{ __('tasks.actions.view') }}"
            >
                {{ $task->title }}
            </a>
        @else
            <span class="min-w-0 font-semibold text-gray-950 dark:text-white">
                {{ $task->title }}
            </span>
        @endcan

        <span class="shrink-0">{{ TaskVisuals::priorityFlag($task->priority) }}</span>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @if ($moves === [])
            <x-task.status-pill :status="$task->status" />
        @else
            <x-filament::dropdown placement="bottom-start" teleport>
                <x-slot name="trigger">
                    <button
                        type="button"
                        class="crm-board-pill"
                        aria-haspopup="menu"
                        aria-label="{{ __('tasks.board_ux.actions.change_status', ['status' => $task->status->getLabel(), 'task' => $task->title]) }}"
                        title="{{ __('tasks.actions.change_status') }}"
                    >
                        <x-task.status-pill :status="$task->status" />
                        <x-filament::icon icon="heroicon-m-chevron-down" class="crm-board-pill-chevron" />
                    </button>
                </x-slot>

                <x-filament::dropdown.list>
                    @foreach ($moves as $move)
                        <x-filament::dropdown.list.item
                            :color="$move->getColor()"
                            :icon="$move->getIcon()"
                            wire:click="mountAction('{{ TasksBoard::moveAction($move) }}', { task: {{ (int) $task->getKey() }} })"
                        >
                            {{ $move->getLabel() }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        @endif

        <x-task.checklist-meter :task="$task" />
    </div>

    @if ($showAssignee || $holders !== null || $task->due_at !== null)
        <div class="flex items-center justify-between gap-2 text-xs">
            @if ($holders !== null)
                <span class="crm-board-card-holders">{{ $holders }}</span>
            @elseif ($showAssignee)
                <x-task.avatar />
            @else
                <span></span>
            @endif

            @if ($task->due_at !== null)
                <span class="shrink-0"><x-task.due :task="$task" /></span>
            @endif
        </div>
    @endif
</article>
