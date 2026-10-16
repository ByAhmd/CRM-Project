@php
    use App\Enums\MyWorkView;
    use App\Filament\Pages\TasksDashboard;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Support\InitialsAvatar;
    use App\Filament\Support\TaskVisuals;
    use Filament\Support\Icons\Heroicon;
@endphp

{{--
    «عملي» (My Work), the Tasks home (D-27). The view's tasks — the reader's
    own, the ones they handed out, or the team's — in the
    MyWorkFeed buckets, each a <details> so a bucket folds without
    JavaScript; wire:ignore.self keeps the reader's folding across the
    re-render that follows an action (the rows inside still update). Every
    control on a row is a TaskActions action rendered with the row's task
    (the browser sends only the id; the page resolves it again through
    MyWorkFeed::find() and the action authorises itself), and a control the
    policy refuses is simply not drawn — a plain pill or date stands in.
    Who holds a task (D-28) is drawn by TaskVisuals from the relations the
    feed loads with every row: outside «مهامي» everyone holding it, inside
    it the colleagues the reader shares it with.
--}}
<x-filament-panels::page>
    <div class="crm-my-work">
        {{-- Whose tasks: one's own, the ones handed out, the team's (D-27 amendment,
             2026-10-12) — tabs only for a reader offered more than one view. --}}
        @if (count($views) > 1)
            <x-filament::tabs :label="__('my_work.helpers.views')" class="crm-my-work-views">
                @foreach ($views as $option)
                    <x-filament::tabs.item
                        :active="$option === $shownView"
                        :icon="$option->getIcon()"
                        wire:click="showView('{{ $option->value }}')"
                    >
                        {{ $option->getLabel() }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        @endif

        <nav class="crm-my-work-summary" aria-label="{{ __('my_work.helpers.summary') }}">
            <ul class="crm-my-work-summary-list" role="list">
                @foreach ($sections as $section)
                    <li>
                        <a
                            href="#my-work-{{ $section->bucket->value }}"
                            @class([
                                'crm-my-work-chip',
                                'crm-my-work-chip--' . $section->bucket->value,
                                'crm-my-work-chip--empty' => $section->isEmpty(),
                            ])
                        >
                            <x-filament::icon :icon="$section->bucket->getIcon()" class="crm-my-work-chip-icon" />
                            <span>{{ $section->bucket->getLabel() }}</span>
                            <span class="crm-my-work-count" dir="ltr">{{ $section->total }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        @unless ($hasOpenWork)
            <x-filament::empty-state
                :icon="Heroicon::OutlinedCheckBadge"
                icon-color="success"
                :heading="match ($shownView) {
                    MyWorkView::Mine => __('my_work.empty.heading'),
                    MyWorkView::Delegated => __('my_work.empty.delegated_heading'),
                    MyWorkView::Team => __('my_work.empty.team_heading'),
                }"
                :description="match ($shownView) {
                    MyWorkView::Mine => $canAddTasks ? __('my_work.empty.description_add') : ($boardUrl !== null ? __('my_work.empty.description_board') : __('my_work.empty.description')),
                    MyWorkView::Delegated => __('my_work.empty.delegated_description'),
                    MyWorkView::Team => __('my_work.empty.team_description'),
                }"
                class="crm-my-work-empty"
            >
                <x-slot name="footer">
                    @if ($canAddTasks)
                        <x-filament::button
                            color="primary"
                            :icon="Heroicon::OutlinedPlus"
                            wire:click="mountAction('quickAdd')"
                        >
                            {{ __('tasks.actions.quick_add') }}
                        </x-filament::button>
                    @elseif ($boardUrl !== null)
                        <x-filament::button
                            tag="a"
                            color="gray"
                            :href="$boardUrl"
                            :icon="Heroicon::OutlinedSquares2x2"
                            wire:navigate
                        >
                            {{ __('my_work.actions.open_board') }}
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament::empty-state>
        @endunless

        @foreach ($sections as $section)
            @php
                $bucket = $section->bucket;
                $headingId = 'my-work-' . $bucket->value . '-heading';
            @endphp

            {{--
                The bucket's heading sits outside <summary>: a summary is a button whose
                children screen readers flatten, so a heading inside it drops out of
                heading navigation (H). The summary keeps the visible words.
            --}}
            <section class="crm-my-work-bucket-section" aria-labelledby="{{ $headingId }}">
            <h2 id="{{ $headingId }}" class="sr-only">{{ $bucket->getLabel() }}</h2>

            <details
                id="my-work-{{ $bucket->value }}"
                {{-- The key carries "empty"/"filled": wire:ignore.self keeps a reader's own
                     folding across re-renders, but a bucket that just gained (or lost) its
                     tasks is a new element, so it takes the default open state again. --}}
                wire:key="my-work-bucket-{{ $bucket->value }}-{{ $section->isEmpty() ? 'empty' : 'filled' }}"
                wire:ignore.self
                @class([
                    'crm-my-work-bucket',
                    'crm-my-work-bucket--' . $bucket->value,
                ])
                @if ($bucket->holdsOpenWork() && ! $section->isEmpty()) open @endif
            >
                <summary class="crm-my-work-bucket-summary">
                    <x-filament::icon :icon="$bucket->getIcon()" class="crm-my-work-bucket-icon" />
                    <span class="crm-my-work-bucket-heading">{{ $bucket->getLabel() }}</span>
                    <span class="crm-my-work-count" dir="ltr">{{ $section->total }}</span>
                </summary>

                @if ($section->isEmpty())
                    <p class="crm-my-work-bucket-empty">{{ __('my_work.empty.buckets.' . $bucket->value) }}</p>
                @else
                    <ul class="crm-my-work-list" role="list" aria-labelledby="{{ $headingId }}">
                        @foreach ($section->tasks as $task)
                            @php
                                $taskId = (int) $task->getKey();
                                // Named for a screen reader with the task, as the list's check and the board's pill are.
                                $complete = ($this->quickCompleteAction)(['task' => $taskId])
                                    ->record($task)
                                    ->iconButton()
                                    ->label(__('tasks.list_ux.helpers.mark_complete', ['task' => $task->title]));
                                $status = ($this->changeStatusAction)(['task' => $taskId])
                                    ->record($task)
                                    ->badge()
                                    ->label($task->status->getLabel())
                                    ->icon(TaskVisuals::statusIcon($task->status))
                                    ->color($task->status->getColor())
                                    ->tooltip(__('tasks.actions.change_status'))
                                    ->extraAttributes(['aria-label' => e(TaskVisuals::statusPillLabel($task))]);
                                $due = TaskVisuals::relativeDue($task);
                                $reschedule = ($this->rescheduleAction)(['task' => $taskId])
                                    ->record($task)
                                    ->link()
                                    ->icon(null)
                                    ->label($due ?? __('tasks.due.none'))
                                    ->tooltip(__('tasks.actions.reschedule'));
                                $subject = $task->subjectLabel();
                                $meter = TaskVisuals::checklistMeterFor($task);
                                // Who handed it to the reader: the assigner, or whoever added them to a shared task (D-28).
                                $assigner = TaskVisuals::handedOutBy($task, $reader);
                                // Outside «مهامي» the row is someone else's: it names who it is for instead.
                                $forOthers = $shownView !== MyWorkView::Mine;
                            @endphp

                            <li
                                wire:key="my-work-task-{{ $taskId }}"
                                @class([
                                    'crm-my-work-row',
                                    'crm-my-work-row--closed' => ! $task->isOpen(),
                                ])
                            >
                                <div class="crm-my-work-check">
                                    @if ($complete->isVisible())
                                        {{ $complete }}
                                    @else
                                        {{ TaskVisuals::completionCheck($task, actionable: false) }}
                                    @endif
                                </div>

                                <div class="crm-my-work-main">
                                    <a
                                        href="{{ TaskResource::getUrl('view', ['record' => $task]) }}"
                                        wire:navigate
                                        class="crm-my-work-title"
                                    >{{ $task->title }}</a>

                                    @if ($subject !== null || $meter !== null)
                                        <div class="crm-my-work-meta">
                                            @if ($subject !== null)
                                                <span class="crm-my-work-subject">{{ $subject }}</span>
                                            @endif

                                            {{ $meter }}
                                        </div>
                                    @endif
                                </div>

                                <div class="crm-my-work-props">
                                    @if ($status->isVisible())
                                        {{ $status }}
                                    @else
                                        {{ TaskVisuals::statusPill($task->status) }}
                                    @endif

                                    {{ TaskVisuals::priorityFlag($task->priority) }}

                                    <span class="crm-my-work-due">
                                        @if ($reschedule->isVisible())
                                            {{ $reschedule }}
                                        @elseif ($due !== null)
                                            {{ $due }}
                                        @else
                                            <span class="crm-my-work-no-date">{{ __('tasks.due.none') }}</span>
                                        @endif
                                    </span>

                                    @if ($forOthers)
                                        {{-- Who it is for (D-28: everyone holding it — avatars, the first name, «+N»). --}}
                                        @php
                                            $holderNames = TaskVisuals::assigneeNames($task);
                                            $assigneeLabel = $holderNames !== ''
                                                ? __('my_work.fields.assigned_to', ['name' => $holderNames])
                                                : __('my_work.fields.unassigned');
                                        @endphp
                                        <span
                                            class="crm-my-work-assignee"
                                            role="img"
                                            aria-label="{{ $assigneeLabel }}"
                                            title="{{ $assigneeLabel }}"
                                        >
                                            @if ($holderNames !== '')
                                                <x-task.assignees :task="$task" />
                                            @else
                                                {{ TaskVisuals::avatar(null) }}
                                                <span class="crm-assignees__name" aria-hidden="true">{{ __('my_work.fields.unassigned') }}</span>
                                            @endif
                                        </span>
                                    @else
                                        {{-- The reader's own task: who handed it out, and the colleagues sharing it (D-28). --}}
                                        @if ($assigner !== null)
                                            <span
                                                class="crm-my-work-assigner"
                                                role="img"
                                                aria-label="{{ __('my_work.fields.handed_out_by', ['name' => $assigner->name]) }}"
                                                title="{{ __('my_work.fields.handed_out_by', ['name' => $assigner->name]) }}"
                                            >{{ InitialsAvatar::forName($assigner->name) }}</span>
                                        @endif

                                        <x-task.co-holders :task="$task" :holder="$reader" />
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($section->more() > 0 && $listUrl !== null)
                        <a
                            href="{{ TasksDashboard::listUrlFor($bucket, $shownView) }}"
                            wire:navigate
                            class="crm-my-work-more"
                        >{{ trans_choice('my_work.actions.show_more', $section->more(), ['count' => $section->more()]) }}</a>
                    @endif
                @endif
            </details>
            </section>
        @endforeach

        {{ $this->content }}
    </div>
</x-filament-panels::page>
