@php
    use Filament\Support\Icons\Heroicon;
@endphp

{{--
    A short FAQ on native <details>: keyboard-operable and readable without
    JavaScript; the first answer starts open.
--}}
<section id="faq" class="crm-landing-section" aria-labelledby="crm-landing-faq-title">
    <div class="crm-landing-container crm-landing-faq">
        <header class="crm-landing-section-head">
            <h2 id="crm-landing-faq-title" class="crm-landing-section-title">{{ __('landing.faq.heading') }}</h2>
        </header>

        <div class="crm-landing-faq-list">
            @foreach (__('landing.faq.items') as $key => $item)
                <details class="crm-landing-faq-item" data-faq="{{ $key }}" @if ($loop->first) open @endif>
                    <summary class="crm-landing-faq-question">
                        <span>{{ $item['q'] }}</span>
                        <x-filament::icon :icon="Heroicon::ChevronDown" class="crm-landing-faq-chevron" />
                    </summary>

                    <p class="crm-landing-faq-answer">{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
