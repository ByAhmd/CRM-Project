{{-- The thread filter of the task page (D-27): all · comments · progress updates, read in PHP from the loaded thread. --}}
<div class="crm-thread-filter" role="group" aria-label="{{ __('tasks.page_ux.filters.label') }}">
    @foreach ($filters as $value => $label)
        <button
            type="button"
            class="crm-thread-filter__button"
            aria-pressed="{{ $current === $value ? 'true' : 'false' }}"
            wire:click="setThreadFilter(@js($value))"
        >
            {{ $label }}
        </button>
    @endforeach
</div>
