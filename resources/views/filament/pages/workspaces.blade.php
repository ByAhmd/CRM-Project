@php
    use Filament\Support\Icons\Heroicon;
@endphp

{{--
    The workspace screen (D-23): one card per workspace, in WorkspacePanel
    order. An enterable card is one link to the workspace's home - a plain
    href, so crossing into another panel is always a full page load. A locked
    card is not a link at all: no href, aria-disabled, the lock icon and the
    "not available for your account" line, drawn at full opacity with its own
    AA-contrast tokens (theme.css, "Workspace screen").
--}}
<x-filament-panels::page>
    <ul class="crm-workspaces" role="list">
        @foreach ($this->getCards() as $card)
            @php
                $workspace = $card->workspace;
                $headingId = 'crm-workspace-'.$workspace->id();
            @endphp

            <li class="crm-workspaces-item">
                @if ($card->isEnterable())
                    <a
                        href="{{ $card->url }}"
                        class="crm-workspace-card"
                        data-workspace="{{ $workspace->id() }}"
                        aria-labelledby="{{ $headingId }}"
                    >
                        <span class="crm-workspace-card-icon" aria-hidden="true">
                            <x-filament::icon :icon="$workspace->getIcon()" class="crm-workspace-card-glyph" />
                        </span>

                        <span class="crm-workspace-card-body">
                            <span class="crm-workspace-card-title" id="{{ $headingId }}">{{ $workspace->getLabel() }}</span>
                            <span class="crm-workspace-card-description">{{ $workspace->getDescription() }}</span>
                        </span>

                        <span class="crm-workspace-card-footer">
                            <span class="crm-workspace-card-enter">{{ __('workspaces.actions.enter') }}</span>
                        </span>
                    </a>
                @else
                    <div
                        class="crm-workspace-card crm-workspace-card-locked"
                        data-workspace="{{ $workspace->id() }}"
                        aria-disabled="true"
                        aria-labelledby="{{ $headingId }}"
                        aria-describedby="{{ $headingId }}-locked"
                    >
                        <span class="crm-workspace-card-icon" aria-hidden="true">
                            <x-filament::icon :icon="$workspace->getIcon()" class="crm-workspace-card-glyph" />
                        </span>

                        <span class="crm-workspace-card-body">
                            <span class="crm-workspace-card-title" id="{{ $headingId }}">{{ $workspace->getLabel() }}</span>
                            <span class="crm-workspace-card-description">{{ $workspace->getDescription() }}</span>
                        </span>

                        <span class="crm-workspace-card-footer">
                            <span class="crm-workspace-card-locked-state" id="{{ $headingId }}-locked">
                                <x-filament::icon :icon="Heroicon::OutlinedLockClosed" class="crm-workspace-card-lock" />
                                {{ __('workspaces.helpers.locked') }}
                            </span>
                        </span>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</x-filament-panels::page>
