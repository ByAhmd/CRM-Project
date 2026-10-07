{{--
    The task page's checklist (D-25, D-27): one click per item for whoever may
    report on an open task. The box flips at once (aria-checked) and the page
    action `tickChecklistItem` (ViewTask) asks the policy and TaskService; the
    re-render that follows carries the server's answer, so a refused tick
    flips back by itself. Everyone else sees the same boxes as plain marks.
--}}
@php
    $state = $getState();
    $items = is_array($state) ? ($state['items'] ?? []) : [];
    $canTick = is_array($state) && ($state['canTick'] ?? false) === true;
    $note = is_array($state) ? ($state['note'] ?? null) : null;
@endphp

<div class="crm-checklist">
    <ul class="crm-checklist__list" role="list">
        @foreach ($items as $item)
            @php
                $textId = 'crm-checklist-item-'.$item['id'];
            @endphp

            <li class="crm-checklist__item" wire:key="crm-checklist-item-{{ $item['id'] }}">
                @if ($canTick)
                    <button
                        type="button"
                        role="checkbox"
                        class="crm-checklist__box"
                        aria-checked="{{ $item['done'] ? 'true' : 'false' }}"
                        aria-labelledby="{{ $textId }}"
                        x-data
                        x-on:click="
                            if ($el.dataset.busy) { return; }
                            $el.dataset.busy = '1';
                            const next = $el.getAttribute('aria-checked') !== 'true';
                            $el.setAttribute('aria-checked', next ? 'true' : 'false');
                            $wire.mountAction('tickChecklistItem', { item: {{ (int) $item['id'] }}, done: next })
                                .finally(() => { delete $el.dataset.busy; });
                        "
                    >
                        <x-filament::icon icon="heroicon-m-check" class="crm-checklist__tick" />
                    </button>
                @else
                    <span
                        class="crm-checklist__box"
                        data-state="{{ $item['done'] ? 'done' : 'open' }}"
                        role="img"
                        aria-label="{{ $item['done'] ? __('tasks.fields.checklist_item_done') : __('tasks.fields.checklist_item_open') }}"
                    >
                        <x-filament::icon icon="heroicon-m-check" class="crm-checklist__tick" />
                    </span>
                @endif

                <span class="crm-checklist__body">
                    <span id="{{ $textId }}" class="crm-checklist__text">{{ $item['body'] }}</span>

                    @if (filled($item['ticked'] ?? null))
                        <span class="crm-checklist__meta">{{ $item['ticked'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>

    @if (filled($note))
        <p class="crm-checklist__note">{{ $note }}</p>
    @endif
</div>
