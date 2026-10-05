@php
    use App\Enums\WorkspacePanel;
    use Filament\Support\Icons\Heroicon;

    // Four groups of shipped capabilities; the sales, tasks and reports
    // groups wear their workspace's icon. Each point carries its claim key
    // (data-claim), which LandingClaimsTest pins to the code behind it.
    $groups = [
        'sales' => WorkspacePanel::Sales->getIcon(),
        'tasks' => WorkspacePanel::Tasks->getIcon(),
        'reports' => WorkspacePanel::Reports->getIcon(),
        'security' => Heroicon::OutlinedShieldCheck,
    ];
@endphp

<section id="features" class="crm-landing-section" aria-labelledby="crm-landing-features-title">
    <div class="crm-landing-container">
        <header class="crm-landing-section-head">
            <h2 id="crm-landing-features-title" class="crm-landing-section-title">{{ __('landing.capabilities.heading') }}</h2>
            <p class="crm-landing-section-lead">{{ __('landing.capabilities.subheading') }}</p>
        </header>

        <ul class="crm-landing-capabilities" role="list">
            @foreach ($groups as $group => $icon)
                <li class="crm-landing-capability" data-capability="{{ $group }}">
                    <span class="crm-landing-capability-icon" aria-hidden="true">
                        <x-filament::icon :icon="$icon" class="crm-landing-capability-glyph" />
                    </span>

                    <h3 class="crm-landing-capability-title">{{ __('landing.capabilities.'.$group.'.title') }}</h3>
                    <p class="crm-landing-capability-body">{{ __('landing.capabilities.'.$group.'.body') }}</p>

                    <ul class="crm-landing-points" role="list">
                        @foreach (__('landing.capabilities.'.$group.'.points') as $point => $text)
                            <li class="crm-landing-point" data-claim="{{ $group }}.{{ $point }}">
                                <x-filament::icon :icon="Heroicon::CheckCircle" class="crm-landing-point-icon" />
                                <span>{{ $text }}</span>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    </div>
</section>
