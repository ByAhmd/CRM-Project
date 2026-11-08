@php
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\ComponentAttributeBag;
    use Filament\Support\View\Components\BadgeComponent;

    $dotFor = static fn (string $color): ComponentAttributeBag => (new ComponentAttributeBag)
        ->color(BadgeComponent::class, $color)
        ->class(['fi-badge', 'crm-project-dot']);
@endphp

{{--
    The project switcher of the Sales top bar (D-30), drawn like the workspace
    switcher beside it (D-23): the current project's colour dot and name, then
    the active projects as buttons - the current one marked for the eye
    (primary, a check) and for a screen reader (aria-current, the hidden
    "current project" text). Choosing one calls switchTo() with the page the
    browser is on right now - the top bar persists across SPA navigation, so
    the address must come from the click, not from the render - and reloads it.
--}}
<div class="crm-project-switcher">
    @if ($current !== null)
        <x-filament::dropdown placement="bottom-end" teleport>
            <x-slot name="trigger">
                <button
                    type="button"
                    class="crm-workspace-switcher-trigger crm-project-switcher-trigger"
                    aria-label="{{ __('projects.switcher.aria', ['project' => $current->display_name]) }}"
                    aria-haspopup="menu"
                    title="{{ __('projects.actions.switch') }}"
                    data-current-project="{{ $current->getKey() }}"
                >
                    <x-filament::icon :icon="Heroicon::OutlinedRectangleStack" class="crm-workspace-switcher-icon" />
                    <span {{ $dotFor($current->color->value) }} aria-hidden="true"></span>
                    <span class="crm-workspace-switcher-label">{{ $current->display_name }}</span>
                    <x-filament::icon :icon="Heroicon::ChevronDown" class="crm-workspace-switcher-chevron" />
                </button>
            </x-slot>

            <x-filament::dropdown.header :icon="Heroicon::OutlinedRectangleStack">
                {{ __('projects.switcher.label') }}
            </x-filament::dropdown.header>

            <x-filament::dropdown.list>
                @foreach ($projects as $project)
                    @php
                        $isCurrent = $project->is($current);
                    @endphp

                    <x-filament::dropdown.list.item
                        tag="button"
                        {{-- Alpine, not wire:click: a wire:click expression resolves bare names against $wire, so `document` would be a stub there. --}}
                        x-on:click="$wire.switchTo({{ $project->getKey() }}, document.location.href)"
                        wire:key="crm-project-switcher-{{ $project->getKey() }}"
                        :color="$isCurrent ? 'primary' : 'gray'"
                        :icon="$isCurrent ? Heroicon::Check : null"
                        :aria-current="$isCurrent ? 'true' : null"
                        class="crm-project-switcher-item"
                        :data-project="$project->getKey()"
                    >
                        <span class="crm-project-switcher-name">
                            <span {{ $dotFor($project->color->value) }} aria-hidden="true"></span>
                            {{ $project->display_name }}
                        </span>

                        @if ($isCurrent)
                            <span class="fi-sr-only">{{ __('projects.switcher.current') }}</span>
                        @endif
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
</div>
