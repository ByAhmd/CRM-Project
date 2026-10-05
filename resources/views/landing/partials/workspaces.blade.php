{{--
    The four workspaces (D-23), named and described by WorkspacePanel itself,
    drawn with the workspace screen's own card styles. Information only:
    which ones a user may enter is decided after sign-in.
--}}
<section id="workspaces" class="crm-landing-section crm-landing-section-band" aria-labelledby="crm-landing-workspaces-title">
    <div class="crm-landing-container">
        <header class="crm-landing-section-head">
            <h2 id="crm-landing-workspaces-title" class="crm-landing-section-title">{{ __('landing.workspaces.heading') }}</h2>
            <p class="crm-landing-section-lead">{{ __('landing.workspaces.subheading') }}</p>
        </header>

        <ul class="crm-workspaces" role="list">
            @foreach ($workspaces as $workspace)
                <li class="crm-workspaces-item">
                    <div class="crm-workspace-card" data-workspace="{{ $workspace->id() }}">
                        <span class="crm-workspace-card-icon" aria-hidden="true">
                            <x-filament::icon :icon="$workspace->getIcon()" class="crm-workspace-card-glyph" />
                        </span>

                        <div class="crm-workspace-card-body">
                            <h3 class="crm-workspace-card-title">{{ $workspace->getLabel() }}</h3>
                            <p class="crm-workspace-card-description">{{ $workspace->getDescription() }}</p>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>
