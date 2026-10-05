{{--
    The top bar: the brand (the application name), the section links, the
    language and theme switches, and the call to action — sign in for a
    guest, the workspace screen for a signed-in visitor. On a phone the
    section links take a second row, so nothing is hidden behind a menu.
--}}
<header class="crm-landing-nav">
    <div class="crm-landing-container crm-landing-nav-inner">
        <a href="{{ route('landing') }}" class="crm-landing-brand">
            <img src="{{ asset('favicon.svg') }}" alt="" class="crm-landing-brand-mark" width="32" height="32" />
            <span class="crm-landing-brand-name">{{ __('app.name') }}</span>
        </a>

        <nav class="crm-landing-links" aria-label="{{ __('landing.nav.sections') }}">
            <a href="#features" class="crm-landing-link">{{ __('landing.nav.features') }}</a>
            <a href="#workspaces" class="crm-landing-link">{{ __('landing.nav.workspaces') }}</a>
            <a href="#faq" class="crm-landing-link">{{ __('landing.nav.faq') }}</a>
        </nav>

        <div class="crm-landing-tools">
            @include('landing.partials.language-switch')
            @include('landing.partials.theme-toggle')

            <a href="{{ $primaryUrl }}" class="crm-landing-btn crm-landing-btn-primary crm-landing-btn-compact" data-landing-cta="nav">
                {{ $signedIn ? __('landing.nav.my_workspaces') : __('landing.nav.sign_in') }}
            </a>
        </div>
    </div>
</header>
