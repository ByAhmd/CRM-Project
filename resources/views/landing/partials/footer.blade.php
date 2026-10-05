{{-- The footer: brand and tagline, the page's links, the copyright line. --}}
<footer class="crm-landing-footer">
    <div class="crm-landing-container crm-landing-footer-inner">
        <div class="crm-landing-footer-brand">
            <span class="crm-landing-brand">
                <img src="{{ asset('favicon.svg') }}" alt="" class="crm-landing-brand-mark" width="28" height="28" />
                <span>{{ __('app.name') }}</span>
            </span>
            <p class="crm-landing-footer-tagline">{{ __('landing.footer.tagline') }}</p>
        </div>

        <nav class="crm-landing-footer-links" aria-label="{{ __('landing.footer.nav') }}">
            <a href="#features" class="crm-landing-link">{{ __('landing.nav.features') }}</a>
            <a href="#workspaces" class="crm-landing-link">{{ __('landing.nav.workspaces') }}</a>
            <a href="#faq" class="crm-landing-link">{{ __('landing.nav.faq') }}</a>
            <a href="{{ $primaryUrl }}" class="crm-landing-link">{{ $signedIn ? __('landing.nav.my_workspaces') : __('landing.nav.sign_in') }}</a>
        </nav>

        <p class="crm-landing-copyright">{{ __('landing.footer.copyright', ['year' => now()->year, 'name' => __('app.name')]) }}</p>
    </div>
</footer>
