{{-- The task page's composer (D-27): App\Livewire\Tasks\TaskComposer. Ctrl+Enter (⌘+Enter) posts. --}}
<div
    class="crm-composer"
    @if ($mayPost)
        x-data
        x-on:keydown.ctrl.enter.prevent="$wire.mountAction('post')"
        x-on:keydown.meta.enter.prevent="$wire.mountAction('post')"
    @endif
>
    @if ($mayPost)
        {{ $this->form }}

        <div class="crm-composer__footer">
            <p class="crm-composer__hint">{{ __('tasks.page_ux.composer.draft_kept') }}</p>

            {{ $this->postAction }}
        </div>

        <x-filament-actions::modals />
    @endif
</div>
