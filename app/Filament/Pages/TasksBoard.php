<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationGroup;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskBoardFeed;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The shared tasks board (decision D-14): every user's open tasks, grouped by
 * assignee, visible to anyone holding `task.view_any` — every seeded role,
 * the employee (D-15) included.
 *
 * The board is the one page-scoped exception to D-4's own/team visibility:
 * the whole team sees who carries what, deliberately and read-only. All the
 * querying and shaping lives in TaskBoardFeed; this page only gates access
 * and hands the feed to the view, which links a card to its task page only
 * where the viewer's ordinary `view` policy allows and shows plain text
 * everywhere else.
 */
final class TasksBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.tasks-board';

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Activities;
    }

    public static function getNavigationLabel(): string
    {
        return __('tasks.pages.board.navigation');
    }

    public function getTitle(): string
    {
        return __('tasks.pages.board.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Task::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $feed = app(TaskBoardFeed::class);
        $board = $feed->board();

        return [
            'stats' => $feed->stats(),
            'columns' => $board['columns'],
            'truncated' => $board['truncated'],
            'timezone' => $feed->timezone(),
        ];
    }
}
