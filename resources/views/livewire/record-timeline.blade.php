{{--
    The record timeline (module row 12). Pure Filament components plus the
    utilities the theme already generates; the rail, the kind dot and the
    body clamp use logical properties and the --crm-* / Filament colour
    tokens inline, so the layout mirrors under RTL and follows the theme.
--}}
<div class="flex flex-col gap-4">
    @forelse ($groups as $day => $entries)
        <section wire:key="timeline-day-{{ $day }}" class="flex flex-col gap-2">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <span dir="ltr">{{ $day }}</span>
            </h4>

            <ol
                class="flex flex-col"
                style="border-inline-start: 1px solid var(--crm-border); padding-inline-start: 1rem; margin-inline-start: 1rem;"
            >
                @foreach ($entries as $entry)
                    <li wire:key="timeline-{{ $entry->key() }}" class="flex items-start gap-3 py-3">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                            style="margin-inline-start: -2rem;"
                            title="{{ $entry->kind->getLabel() }}"
                        >
                            <x-filament::icon :icon="$entry->kind->getIcon()" class="size-5 text-gray-500 dark:text-gray-400" />
                        </span>

                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-3 shrink-0 rounded-full ring-2 ring-white dark:ring-gray-900"
                                    style="background-color: var(--{{ $entry->kind->getColor() }}-500);"
                                    aria-hidden="true"
                                ></span>

                                @if ($entry->url !== null)
                                    <a href="{{ $entry->url }}" class="min-w-0 break-words text-sm font-medium text-gray-950 hover:underline dark:text-white">
                                        {{ $entry->title }}
                                    </a>
                                @else
                                    <span class="min-w-0 break-words text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $entry->title }}
                                    </span>
                                @endif

                                @if ($entry->badge !== null)
                                    <x-filament::badge :color="$entry->kind->getColor()" size="sm">
                                        {{ $entry->badge }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            @if ($entry->body !== null)
                                <p
                                    class="whitespace-pre-line break-words text-sm text-gray-700 dark:text-gray-200"
                                    style="display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 6; overflow: hidden;"
                                >{{ $entry->body }}</p>
                            @endif

                            @if ($entry->meta !== [] || $entry->links !== [])
                                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    @foreach ($entry->meta as $label => $value)
                                        <span class="break-words">{{ __('timeline.formats.meta', ['label' => $label, 'value' => $value]) }}</span>
                                    @endforeach

                                    @foreach ($entry->links as $label => $href)
                                        <x-filament::link :href="$href" size="xs">{{ $label }}</x-filament::link>
                                    @endforeach
                                </div>
                            @endif

                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                @if ($entry->actor !== null)
                                    <span class="truncate">{{ __('timeline.formats.meta', ['label' => __('timeline.fields.by'), 'value' => $entry->actor]) }}</span>
                                @endif

                                <time
                                    datetime="{{ $entry->occurredAt->toIso8601String() }}"
                                    title="{{ $entry->occurredAt->copy()->timezone($timezone)->format('Y-m-d H:i') }}"
                                    class="shrink-0"
                                >
                                    {{ $entry->occurredAt->copy()->locale(app()->getLocale())->diffForHumans() }}
                                </time>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @empty
        <div class="flex flex-col items-center gap-2 py-4 text-center">
            <x-filament::icon icon="heroicon-o-clock" class="size-8 text-gray-400 dark:text-gray-500" />

            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('timeline.empty.heading') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('timeline.empty.description') }}</p>
        </div>
    @endforelse

    @if ($hasMore)
        <div class="flex items-center justify-center">
            <x-filament::button color="gray" size="sm" wire:click="loadMore" wire:loading.attr="disabled">
                {{ __('timeline.actions.load_more') }}
            </x-filament::button>
        </div>
    @endif

    @if ($capped)
        <p class="text-center text-xs text-gray-500 dark:text-gray-400">
            {{ trans_choice('timeline.hints.capped', $shown, ['count' => $shown]) }}
        </p>
    @endif
</div>
