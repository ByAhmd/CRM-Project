@php
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\ComponentAttributeBag;
    use Filament\Support\View\Components\BadgeComponent;
@endphp

{{--
    The Sales «المشاريع» page (D-30): one card per active project in switcher
    order. The colour dot borrows the badge colour classes, so it paints from
    the panel's own palette in both themes; the counts are the viewer's reach
    (RecordVisibilityResolver), which the note under the grid says. «دخول» is
    a page action carrying the project id as its argument.
--}}
<x-filament-panels::page>
    @if ($cards === [])
        <x-filament::empty-state
            :heading="__('projects.empty.heading')"
            :description="__('projects.empty.description')"
            :icon="Heroicon::OutlinedRectangleStack"
        />
    @else
        <ul class="crm-projects" role="list">
            @foreach ($cards as $card)
                @php
                    $project = $card['project'];
                    $counts = $card['counts'];
                    $headingId = 'crm-project-'.$project->getKey();
                    $dot = (new ComponentAttributeBag)
                        ->color(BadgeComponent::class, $project->color->value)
                        ->class(['fi-badge', 'crm-project-dot']);
                @endphp

                <li class="crm-projects-item">
                    <article
                        @class(['crm-project-card', 'crm-project-card-current' => $card['current']])
                        data-project="{{ $project->getKey() }}"
                        aria-labelledby="{{ $headingId }}"
                        @if ($card['current']) aria-current="true" @endif
                    >
                        <header class="crm-project-card-head">
                            <span {{ $dot }} aria-hidden="true"></span>

                            <h2 class="crm-project-card-title" id="{{ $headingId }}">{{ $project->display_name }}</h2>

                            @if ($card['current'])
                                <x-filament::badge color="primary" size="sm" :icon="Heroicon::Check" class="crm-project-card-current-badge">
                                    {{ __('projects.pages.projects.current') }}
                                </x-filament::badge>
                            @endif
                        </header>

                        <dl class="crm-project-card-counts">
                            <div class="crm-project-card-count">
                                <dt>{{ __('projects.fields.leads_count') }}</dt>
                                <dd>{{ $counts['leads_count'] }}</dd>
                            </div>

                            <div class="crm-project-card-count">
                                <dt>{{ __('projects.fields.contacts_count') }}</dt>
                                <dd>{{ $counts['contacts_count'] }}</dd>
                            </div>

                            <div class="crm-project-card-count">
                                <dt>{{ __('projects.fields.accounts_count') }}</dt>
                                <dd>{{ $counts['accounts_count'] }}</dd>
                            </div>

                            <div class="crm-project-card-count">
                                <dt>{{ __('projects.fields.deals_count') }}</dt>
                                <dd>{{ $counts['deals_count'] }}</dd>
                            </div>
                        </dl>

                        <p class="crm-project-card-amount">
                            <span class="crm-project-card-amount-label">
                                {{ __('projects.fields.open_deals_amount') }}
                                <span class="crm-project-card-amount-count">({{ __('projects.fields.open_deals_count') }}: {{ $counts['open_deals_count'] }})</span>
                            </span>
                            <span class="crm-project-card-amount-value" dir="ltr">{{ $card['open_deals_amount'] }}</span>
                        </p>

                        <footer class="crm-project-card-footer">
                            {{ ($this->enterAction)(['project' => $project->getKey()]) }}
                        </footer>
                    </article>
                </li>
            @endforeach
        </ul>

        <p class="crm-projects-scope">{{ __('projects.pages.projects.counts_scope') }}</p>
    @endif
</x-filament-panels::page>
