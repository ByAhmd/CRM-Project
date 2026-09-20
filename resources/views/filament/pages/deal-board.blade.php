@php
    use App\Filament\Pages\DealBoard;
    use App\Filament\Resources\Deals\DealResource;
    use App\Filament\Resources\Deals\Schemas\DealInfolist;
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <div class="w-full max-w-xs">
            <label for="deal-board-pipeline" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">
                {{ __('deal_board.fields.pipeline') }}
            </label>

            <x-filament::input.wrapper>
                <x-filament::input.select id="deal-board-pipeline" wire:model.live="pipelineId">
                    @foreach ($pipelines as $pipeline)
                        <option value="{{ $pipeline->getKey() }}">{{ $pipeline->display_name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        {{--
            snap-x (proximity) + snap-start: a phone swipe settles on a whole
            column (w-72 = 288px against a 375px viewport) without hijacking
            desktop scrolling or the drag auto-scroll between columns.
        --}}
        <div class="flex snap-x items-start gap-4 overflow-x-auto pb-4">
            @foreach ($columns as $column)
                @php
                    $stage = $column['stage'];
                @endphp

                <div
                    wire:key="stage-{{ $stage->getKey() }}"
                    class="flex w-72 min-w-72 shrink-0 snap-start flex-col rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                >
                    <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                        <div class="flex min-w-0 items-center gap-2">
                            <x-filament::badge :color="$stage->color->value">
                                {{ $stage->display_name }}
                            </x-filament::badge>

                            <span class="text-xs text-gray-500 dark:text-gray-400" title="{{ __('deal_board.columns.count') }}">
                                {{ $column['count'] }}
                            </span>
                        </div>

                        <span class="truncate text-xs font-medium text-gray-700 dark:text-gray-200" title="{{ __('deal_board.columns.total') }}" dir="ltr">
                            {{ $column['total'] }}
                        </span>
                    </div>

                    @if ($stage->isClosed())
                        <p class="px-3 pt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('deal_board.columns.recent_closed', ['days' => DealBoard::CLOSED_WINDOW_DAYS]) }}
                        </p>
                    @endif

                    <div
                        x-sortable
                        x-sortable-group="deals"
                        data-stage-id="{{ $stage->getKey() }}"
                        x-on:end.stop="if ($event.from !== $event.to) { $wire.moveDeal(parseInt($event.item.getAttribute('x-sortable-item')), parseInt($event.to.dataset.stageId)) }"
                        class="flex min-h-24 flex-col gap-2 p-2"
                    >
                        @forelse ($column['deals'] as $deal)
                            <div
                                x-sortable-item="{{ $deal->getKey() }}"
                                x-sortable-handle
                                wire:key="deal-{{ $deal->getKey() }}"
                                class="flex cursor-grab flex-col gap-1 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm dark:border-white/10 dark:bg-gray-800"
                            >
                                <a
                                    href="{{ DealResource::getUrl('view', ['record' => $deal]) }}"
                                    class="font-semibold text-gray-950 hover:underline dark:text-white"
                                    title="{{ __('deal_board.cards.open') }}"
                                >
                                    {{ $deal->title }}
                                </a>

                                <span class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $deal->account?->name ?? __('common.placeholders.empty') }}
                                </span>

                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium text-gray-900 dark:text-gray-100" dir="ltr">
                                        {{ DealBoard::money((float) $deal->amount, $deal->currency) }}
                                    </span>

                                    <x-filament::badge :color="$deal->forecast_category->getColor()" size="sm">
                                        {{ $deal->forecast_category->getLabel() }}
                                    </x-filament::badge>
                                </div>

                                <div class="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="truncate">
                                        {{ $deal->owner?->name ?? __('assignment.placeholders.unassigned') }}
                                    </span>

                                    @if ($deal->expected_close_date !== null)
                                        <span @class(['font-medium text-danger-600 dark:text-danger-400' => DealInfolist::isOverdue($deal)]) dir="ltr">
                                            {{ $deal->expected_close_date->format('Y-m-d') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="px-1 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                {{ __('deal_board.columns.empty') }}
                            </p>
                        @endforelse

                        @if ($column['count'] > $column['limit'])
                            <x-filament::button color="gray" size="sm" wire:click="loadMore({{ $stage->getKey() }})">
                                {{ __('deal_board.columns.load_more') }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
