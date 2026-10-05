{{-- The closing call to action: the same destination as the top bar's. --}}
<section class="crm-landing-section" aria-labelledby="crm-landing-cta-title">
    <div class="crm-landing-container">
        <div class="crm-landing-cta">
            <h2 id="crm-landing-cta-title" class="crm-landing-cta-title">{{ __('landing.cta.heading') }}</h2>
            <p class="crm-landing-cta-body">{{ $signedIn ? __('landing.cta.body_signed_in') : __('landing.cta.body') }}</p>

            <a href="{{ $primaryUrl }}" class="crm-landing-btn crm-landing-btn-primary" data-landing-cta="closing">
                {{ $signedIn ? __('landing.hero.cta_workspaces') : __('landing.hero.cta_sign_in') }}
            </a>
        </div>
    </div>
</section>
