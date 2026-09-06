@php
    use App\Enums\ActivityKind;
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-500 dark:text-gray-400" aria-label="{{ __('calendar.legend.heading') }}">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('calendar.legend.tasks') }}</span>

                @foreach (TaskPriority::cases() as $priority)
                    <x-filament::badge :color="$priority->getColor()" size="sm">
                        {{ $priority->getLabel() }}
                    </x-filament::badge>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-filament::badge :color="ActivityKind::Meeting->getColor()" :icon="ActivityKind::Meeting->getIcon()" size="sm">
                    {{ __('calendar.legend.meetings') }}
                </x-filament::badge>

                <x-filament::badge :color="ActivityKind::Call->getColor()" :icon="ActivityKind::Call->getIcon()" size="sm">
                    {{ __('calendar.legend.calls') }}
                </x-filament::badge>

                <x-filament::badge :color="TaskStatus::Completed->getColor()" size="sm" class="crm-calendar-event-muted">
                    {{ __('calendar.legend.completed') }}
                </x-filament::badge>
            </div>
        </div>

        {{--
            The module registers the `crmCalendar` Alpine component before
            Alpine starts (Livewire starts it on DOMContentLoaded, after the
            deferred module has run). Livewire leaves the element alone
            (wire:ignore) — FullCalendar owns its DOM.
        --}}
        @vite('resources/js/calendar.js')

        <div
            wire:ignore
            x-data="crmCalendar(@js($this->config()))"
            class="fi-crm-calendar rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <div x-ref="calendar"></div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('calendar.empty.description') }}
        </p>
    </div>
</x-filament-panels::page>
