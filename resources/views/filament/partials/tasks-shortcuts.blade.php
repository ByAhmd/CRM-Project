@php
    use App\Enums\WorkspacePanel;
    use App\Models\User;
    use App\Support\Filament\TasksShortcuts;
    use Filament\Support\Icons\Heroicon;

    $user = filament()->auth()->user();
    // Registered by the Tasks panel only; the panel check keeps it there even
    // where render hooks outlive a request (a worker serving several panels).
    $inTasks = filament()->getId() === WorkspacePanel::Tasks->id();
    $quickAddUrl = TasksShortcuts::quickAddUrl($user instanceof User ? $user : null);
    $searchHint = TasksShortcuts::searchHint(request()->userAgent()) ?? __('tasks.shortcuts.search_hint');
@endphp

{{--
    The keyboard of the Tasks workspace (D-27): N opens quick add (only for
    a reader who may add tasks), / focuses the search, ? opens this sheet.
    The listener matches physical keys (event.code), so the shortcuts work on
    the Arabic layout too, and ignores every key typed into a field, a
    select or an editor, every key held with Ctrl, Alt or Cmd, IME
    composition, and any key pressed while a dialog is open. Single-key
    shortcuts can be switched off on the sheet (WCAG 2.1.4), per device.
    The listener binds once (data-navigate-once) and reads its per-page
    settings from #crm-shortcuts at each key press, so it follows SPA
    navigation (A-26). No motion.
--}}
@if ($inTasks && $user instanceof User)
    <div id="crm-shortcuts" hidden @if ($quickAddUrl !== null) data-quick-add-url="{{ $quickAddUrl }}" @endif></div>

    <dialog id="crm-shortcuts-sheet" class="crm-shortcuts-sheet" aria-labelledby="crm-shortcuts-title">
        <div class="crm-shortcuts-head">
            <h2 id="crm-shortcuts-title" class="crm-shortcuts-title">{{ __('tasks.shortcuts.title') }}</h2>

            <form method="dialog">
                <button type="submit" class="crm-shortcuts-close" aria-label="{{ __('tasks.shortcuts.close') }}">
                    <x-filament::icon :icon="Heroicon::OutlinedXMark" class="crm-shortcuts-close-icon" />
                </button>
            </form>
        </div>

        <dl class="crm-shortcuts-list">
            @if ($quickAddUrl !== null)
                <div class="crm-shortcuts-row">
                    <dt><kbd class="crm-kbd" dir="ltr">N</kbd></dt>
                    <dd>{{ __('tasks.shortcuts.quick_add') }}</dd>
                </div>
            @endif

            <div class="crm-shortcuts-row">
                <dt><kbd class="crm-kbd" dir="ltr">/</kbd></dt>
                <dd>{{ __('tasks.shortcuts.search') }}</dd>
            </div>

            <div class="crm-shortcuts-row">
                <dt><kbd class="crm-kbd" dir="ltr">{{ $searchHint }}</kbd></dt>
                <dd>{{ __('tasks.shortcuts.search_anywhere') }}</dd>
            </div>

            <div class="crm-shortcuts-row">
                <dt><kbd class="crm-kbd" dir="ltr">?</kbd></dt>
                <dd>{{ __('tasks.shortcuts.sheet') }}</dd>
            </div>
        </dl>

        <label class="crm-shortcuts-toggle">
            <input type="checkbox" checked data-crm-shortcuts-toggle />
            <span>{{ __('tasks.shortcuts.single_keys') }}</span>
            {{-- Each key its own isolated, left-to-right element, so the list reads right in Arabic too. --}}
            <span class="crm-shortcuts-toggle-keys"><kbd class="crm-kbd" dir="ltr">N</kbd> <kbd class="crm-kbd" dir="ltr">/</kbd> <kbd class="crm-kbd" dir="ltr">?</kbd></span>
        </label>

        <p class="crm-shortcuts-help">{{ __('tasks.shortcuts.single_keys_help') }}</p>
    </dialog>

    <script data-navigate-once>
        (() => {
            if (window.crmTasksShortcuts) {
                return;
            }

            window.crmTasksShortcuts = true;

            const store = 'crm.shortcuts.single';

            // Where storage is refused (private browsing), the choice lasts until the page reloads.
            let remembered = 'on';

            const singleKeysOn = () => {
                try {
                    return (window.localStorage.getItem(store) ?? remembered) !== 'off';
                } catch (error) {
                    return remembered !== 'off';
                }
            };

            const isTyping = (element) => {
                if (! (element instanceof Element)) {
                    return false;
                }

                if (element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)) {
                    return true;
                }

                return element.closest('[contenteditable=""], [contenteditable="true"], .ProseMirror, trix-editor') !== null;
            };

            const dialogOpen = () => document.querySelector('.fi-modal.fi-modal-open, dialog[open]') !== null;

            const openSheet = () => {
                const sheet = document.getElementById('crm-shortcuts-sheet');

                if (! sheet || sheet.open) {
                    return false;
                }

                const toggle = sheet.querySelector('[data-crm-shortcuts-toggle]');

                if (toggle) {
                    toggle.checked = singleKeysOn();
                }

                sheet.showModal();

                return true;
            };

            const quickAdd = (url) => {
                const here = document.querySelector('[data-crm-shortcut="quick-add"]');

                if (here) {
                    here.click();
                } else if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                    window.Livewire.navigate(url);
                } else {
                    window.location.assign(url);
                }
            };

            window.crmOpenShortcutSheet = openSheet;

            document.addEventListener('keydown', (event) => {
                if (event.defaultPrevented || event.isComposing || event.ctrlKey || event.metaKey || event.altKey) {
                    return;
                }

                if (isTyping(event.target) || isTyping(document.activeElement) || dialogOpen() || ! singleKeysOn()) {
                    return;
                }

                const settings = document.getElementById('crm-shortcuts');

                if (! settings) {
                    return;
                }

                if (event.code === 'KeyN' && ! event.shiftKey) {
                    const url = settings.dataset.quickAddUrl;

                    if (url) {
                        event.preventDefault();
                        quickAdd(url);
                    }

                    return;
                }

                if (event.code === 'Slash' && ! event.shiftKey) {
                    const field = document.querySelector('.fi-global-search-field input');

                    if (field) {
                        event.preventDefault();
                        field.focus();
                    }

                    return;
                }

                if ((event.code === 'Slash' && event.shiftKey) || event.key === '?') {
                    if (openSheet()) {
                        event.preventDefault();
                    }
                }
            });

            // The phone bar's «+» (no wire:navigate, so nothing has started a page swap yet):
            // quick add opens in place where the page offers it, otherwise My Work opens with it.
            document.addEventListener('click', (event) => {
                const link = event.target instanceof Element ? event.target.closest('[data-crm-quick-add-link]') : null;

                if (! link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                    return;
                }

                event.preventDefault();
                quickAdd(link.getAttribute('href'));
            });

            document.addEventListener('change', (event) => {
                if (event.target instanceof HTMLInputElement && event.target.matches('[data-crm-shortcuts-toggle]')) {
                    remembered = event.target.checked ? 'on' : 'off';

                    try {
                        window.localStorage.setItem(store, remembered);
                    } catch (error) {
                        // Storage refused: `remembered` keeps the choice for this page's life.
                    }
                }
            });
        })();
    </script>
@endif
