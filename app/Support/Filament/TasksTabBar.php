<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Filament\Pages\TasksBoard;
use App\Filament\Pages\TasksDashboard;
use App\Filament\Pages\TasksInbox;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\InboxService;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Request;

/**
 * The Tasks workspace's bottom tab bar on phones (decision D-27): «عملي»,
 * «المهام», «+» (quick add), «اللوحة» and «الوارد», under the thumb where the
 * sidebar sits behind the menu button. Each tab is offered only to a reader
 * who may open its page — the page's own canAccess() / the resource's
 * canViewAny(), and for «+» the TaskPolicy `create` answer — so the bar never
 * links to a 403; with nothing to offer it is not drawn at all. The current
 * page's tab carries aria-current. Every tab, «الوارد» included (TasksInbox,
 * the workspace's own copy of the Inbox), stays inside the Tasks workspace and
 * swaps the page in place (A-26), so the bar and the sidebar never go away.
 */
final class TasksTabBar
{
    /**
     * @return list<TasksTabBarItem>
     */
    public static function items(?User $reader, Request $request): array
    {
        if (! $reader instanceof User) {
            return [];
        }

        $navigate = true;
        $items = [];
        $home = TasksDashboard::canAccess();

        if ($home) {
            $items[] = new TasksTabBarItem(
                key: 'my_work',
                label: __('tasks.tab_bar.my_work'),
                icon: Heroicon::OutlinedHome,
                url: TasksDashboard::getUrl(),
                current: $request->routeIs(TasksDashboard::getRouteName()),
                navigate: $navigate,
            );
        }

        if (TaskResource::canViewAny()) {
            $items[] = new TasksTabBarItem(
                key: 'tasks',
                label: __('tasks.tab_bar.tasks'),
                icon: Heroicon::OutlinedClipboardDocumentList,
                url: TaskResource::getUrl('index'),
                current: $request->routeIs(TaskResource::getRouteBaseName().'.*'),
                navigate: $navigate,
            );
        }

        if ($home && $reader->can('create', Task::class)) {
            $items[] = new TasksTabBarItem(
                key: 'add',
                label: __('tasks.tab_bar.add'),
                icon: Heroicon::Plus,
                url: TasksDashboard::getUrl(['action' => 'quickAdd']),
                primary: true,
                // No wire:navigate: Livewire would start the page swap on mouseup, before the
                // shortcuts script can open quick add in place (tasks-shortcuts.blade.php).
                navigate: false,
            );
        }

        if (TasksBoard::canAccess()) {
            $items[] = new TasksTabBarItem(
                key: 'board',
                label: __('tasks.tab_bar.board'),
                icon: Heroicon::OutlinedSquares2x2,
                url: TasksBoard::getUrl(),
                current: $request->routeIs(TasksBoard::getRouteName()),
                navigate: $navigate,
            );
        }

        if (TasksInbox::canAccess()) {
            $unread = app(InboxService::class)->cachedUnreadCount($reader, $request);

            $items[] = new TasksTabBarItem(
                key: 'inbox',
                label: __('tasks.tab_bar.inbox'),
                icon: Heroicon::OutlinedInbox,
                url: TasksInbox::getUrl(),
                current: $request->routeIs(TasksInbox::getRouteName()),
                navigate: $navigate,
                badge: $unread > 0 ? $unread : null,
            );
        }

        return $items;
    }
}
