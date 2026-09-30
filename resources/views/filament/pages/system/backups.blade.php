<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        @if ($failure !== null)
            <div
                role="alert"
                class="rounded-xl bg-danger-50 px-4 py-3 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/30"
            >
                <p class="font-semibold">
                    {{ __('backups.pages.last_failure', ['time' => $failureTime, 'reason' => __('backups.reasons.'.$failure->reason)]) }}
                </p>

                {{-- The exact cause in the reader's locale; a tool's own error output inside it stays as written. --}}
                <p class="mt-1 break-all text-xs" dir="auto">
                    {{ $failure->localisedDetail() }}
                </p>
            </div>
        @endif

        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ trans_choice('backups.helpers.schedule', $keep, ['count' => $keep]) }}
        </p>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
