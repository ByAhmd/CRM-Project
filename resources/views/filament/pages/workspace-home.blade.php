@php
    use Filament\Support\Icons\Heroicon;

    use function Filament\Support\generate_href_html;

    $sections = $this->getSections();
@endphp

{{--
    The home page of a workspace that is a list of screens (D-23: Reports,
    Administration): the screens the viewer may open, each already checked
    against its own gate by the page, grouped as the sidebar groups them.
--}}
<x-filament-panels::page>
    @if ($sections === [])
        <x-filament::empty-state
            :heading="$this->getEmptyHeading()"
            :description="$this->getEmptyDescription()"
            :icon="Heroicon::OutlinedSquares2x2"
        />
    @else
        @foreach ($sections as $index => $section)
            <section
                class="crm-workspace-home-section"
                @if ($section->heading !== null) aria-labelledby="crm-workspace-home-{{ $index }}" @endif
            >
                @if ($section->heading !== null)
                    <h2 class="crm-workspace-home-heading" id="crm-workspace-home-{{ $index }}">{{ $section->heading }}</h2>
                @endif

                <ul class="crm-workspace-links" role="list">
                    @foreach ($section->links as $link)
                        <li>
                            <a {{ generate_href_html($link->url) }} class="crm-workspace-link">
                                @if ($link->icon !== null)
                                    <span class="crm-workspace-link-icon" aria-hidden="true">
                                        <x-filament::icon :icon="$link->icon" class="crm-workspace-link-glyph" />
                                    </span>
                                @endif

                                <span class="crm-workspace-link-body">
                                    <span class="crm-workspace-link-title">{{ $link->label }}</span>

                                    @if ($link->description !== null)
                                        <span class="crm-workspace-link-description">{{ $link->description }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</x-filament-panels::page>
