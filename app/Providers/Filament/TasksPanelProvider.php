<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\WorkspacePanel;
use App\Filament\Pages\Inbox;
use App\Livewire\TaskUndo;
use App\Models\User;
use App\Providers\Filament\Concerns\ConfiguresCrmPanel;
use App\Support\Filament\TasksShortcuts;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

/**
 * The Tasks workspace at /tasks (decision D-23): tasks, the tasks board,
 * routine tasks and the calendar; its home is «عملي» (My Work, D-27). Global
 * search covers tasks.
 *
 * Tasks-only presentation (D-27) is configured here, never in the shared
 * ConfiguresCrmPanel, so the other workspaces keep their look:
 * - the invisible TaskUndo listener behind the «تراجع» of a completion toast;
 * - its own Inbox page (TasksInbox) tops the sidebar with the unread count;
 * - the phone tab bar (below md) and the keyboard shortcuts with their sheet,
 *   reachable from the user menu too;
 * - Ctrl+K / ⌘K for the search, with the hint the platform's keyboard has.
 */
final class TasksPanelProvider extends PanelProvider
{
    use ConfiguresCrmPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureWorkspacePanel($panel, WorkspacePanel::Tasks)
            // "mod" is Cmd on a Mac and Ctrl elsewhere, one binding for both.
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchFieldSuffix(static fn (): ?string => TasksShortcuts::searchHint(request()->userAgent()))
            ->userMenuItems([
                Action::make('keyboardShortcuts')
                    ->label(static fn (): string => __('tasks.shortcuts.title'))
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->alpineClickHandler('window.crmOpenShortcutSheet && window.crmOpenShortcutSheet()'),
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                static fn (): HtmlString => new HtmlString(Livewire::mount(TaskUndo::class)),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                static fn (): View => view('filament.partials.tasks-tab-bar'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                static fn (): View => view('filament.partials.tasks-shortcuts'),
            );
    }
}
