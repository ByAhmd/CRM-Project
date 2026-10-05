@php
    use App\Enums\WorkspacePanel;
    use App\Filament\Pages\Workspaces;
    use App\Models\User;
    use App\Services\Access\PanelAccess;
    use App\Support\Filament\PanelUrl;
    use Filament\Support\Icons\Heroicon;

    $user = filament()->auth()->user();
    $current = WorkspacePanel::tryFromId((string) filament()->getId());
    $onScreen = filament()->getId() === WorkspacePanel::HOME && request()->routeIs(Workspaces::getRouteName());
    $workspaces = $user instanceof User ? app(PanelAccess::class)->enterableWorkspaces($user) : [];
    $currentLabel = $current?->getLabel() ?? __('workspaces.pages.screen.title');
@endphp

{{--
    The workspace switcher in every top bar (D-23): the workspaces this user
    may enter - never a locked one, it would only answer 403 - with the
    current one marked, then the workspace screen. Links into another panel
    are full page loads (spaUrlExceptions in ConfiguresCrmPanel).
--}}
@if ($user instanceof User)
    <x-filament::dropdown placement="bottom-end" teleport class="crm-workspace-switcher">
        <x-slot name="trigger">
            <button
                type="button"
                class="crm-workspace-switcher-trigger"
                aria-label="{{ __('workspaces.helpers.switcher', ['workspace' => $currentLabel]) }}"
                aria-haspopup="menu"
            >
                <x-filament::icon :icon="$current?->getIcon() ?? Heroicon::OutlinedSquares2x2" class="crm-workspace-switcher-icon" />
                <span class="crm-workspace-switcher-label">{{ $currentLabel }}</span>
                <x-filament::icon :icon="Heroicon::ChevronDown" class="crm-workspace-switcher-chevron" />
            </button>
        </x-slot>

        @if ($workspaces !== [])
            <x-filament::dropdown.list>
                @foreach ($workspaces as $workspace)
                    <x-filament::dropdown.list.item
                        tag="a"
                        :href="PanelUrl::panelHome($workspace)"
                        :icon="$workspace->getIcon()"
                        :color="$workspace === $current ? 'primary' : 'gray'"
                        :aria-current="$workspace === $current ? 'page' : null"
                        class="crm-workspace-switcher-item"
                        :data-workspace="$workspace->id()"
                    >
                        {{ $workspace->getLabel() }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        @endif

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                tag="a"
                :href="PanelUrl::workspaceScreen()"
                :icon="Heroicon::OutlinedSquares2x2"
                :color="$onScreen ? 'primary' : 'gray'"
                :aria-current="$onScreen ? 'page' : null"
                class="crm-workspace-switcher-item crm-workspace-switcher-screen"
            >
                {{ __('workspaces.pages.screen.title') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
