{{--
    The facts strip: every number is read from the code (LandingFacts), the
    noun counted in the reader's language. Shown number first; read label
    first, so a screen reader hears «مساحات عمل: 4».
--}}
<section class="crm-landing-facts" aria-labelledby="crm-landing-facts-title">
    <div class="crm-landing-container">
        <h2 id="crm-landing-facts-title" class="crm-landing-sr-only">{{ __('landing.facts.heading') }}</h2>

        <dl class="crm-landing-facts-list">
            @foreach ($facts as $fact)
                <div class="crm-landing-fact" data-fact="{{ $fact->key }}">
                    <dt class="crm-landing-fact-label">{{ $fact->label() }}</dt>
                    <dd class="crm-landing-fact-value crm-tabular" data-fact-value="{{ $fact->value }}">{{ $fact->value }}</dd>
                    <dd class="crm-landing-fact-note">{{ $fact->note() }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>
