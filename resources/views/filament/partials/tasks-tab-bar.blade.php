@php
    use App\Enums\WorkspacePanel;
    use App\Models\User;
    use App\Support\Filament\TasksTabBar;

    $user = filament()->auth()->user();
    // Registered by the Tasks panel only; the check keeps it there even where
    // render hooks outlive a request (a worker serving several panels).
    $items = filament()->getId() === WorkspacePanel::Tasks->id()
        ? TasksTabBar::items($user instanceof User ? $user : null, request())
        : [];
@endphp

{{--
    The Tasks phone bar (D-27): fixed at the bottom below the md breakpoint
    (theme.css hides it from md up), each tab at least 44px tall, in the
    Tasks workspace, its own Inbox page included. Only the tabs the reader
    may open are drawn (TasksTabBar); the current one carries aria-current.
    The page leaves room under its last row for the bar (theme.css).
--}}
@if ($items !== [])
    <nav class="crm-tab-bar" aria-label="{{ __('tasks.tab_bar.label') }}">
        <ul class="crm-tab-bar-list" role="list">
            @foreach ($items as $item)
                <li class="crm-tab-bar-item">
                    <a
                        href="{{ $item->url }}"
                        @if ($item->navigate) wire:navigate @endif
                        @class([
                            'crm-tab-bar-link',
                            'crm-tab-bar-link--add' => $item->primary,
                        ])
                        @if ($item->current) aria-current="page" @endif
                        @if ($item->primary) data-crm-quick-add-link @endif
                        data-tab="{{ $item->key }}"
                    >
                        <span class="crm-tab-bar-icon-ctn">
                            <x-filament::icon :icon="$item->icon" class="crm-tab-bar-icon" />

                            @if ($item->badge !== null)
                                <span class="crm-tab-bar-badge" dir="ltr" aria-hidden="true">{{ $item->badge > 99 ? '99+' : $item->badge }}</span>
                                <span class="sr-only">{{ trans_choice('tasks.tab_bar.unread', $item->badge, ['count' => $item->badge]) }}</span>
                            @endif
                        </span>

                        <span class="crm-tab-bar-label">{{ $item->label }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
