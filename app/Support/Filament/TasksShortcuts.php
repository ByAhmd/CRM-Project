<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Filament\Pages\TasksDashboard;
use App\Models\Task;
use App\Models\User;

/**
 * The keyboard of the Tasks workspace (decision D-27): N opens quick add
 * (for a reader who may create tasks), / focuses the search, ? shows the
 * shortcut sheet — single keys, ignored while typing in a field or an editor
 * and with any modifier held, and switchable off on the sheet (WCAG 2.1.4) —
 * plus Ctrl+K / ⌘K for the search, whose hint the search field shows on the
 * platforms that have that key.
 */
final class TasksShortcuts
{
    /** The page quick add opens on when the current page carries no quick-add button; null when the reader may not add tasks. */
    public static function quickAddUrl(?User $reader): ?string
    {
        if (! $reader instanceof User || ! TasksDashboard::canAccess() || ! $reader->can('create', Task::class)) {
            return null;
        }

        return TasksDashboard::getUrl(['action' => 'quickAdd']);
    }

    /**
     * The key hint the search field shows: ⌘K on a Mac, Ctrl+K on Windows,
     * Linux and ChromeOS, nothing on a phone or a tablet (no such key) or an
     * agent that does not say.
     */
    public static function searchHint(?string $userAgent): ?string
    {
        $agent = (string) $userAgent;

        if ($agent === '' || preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $agent) === 1) {
            return null;
        }

        if (str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X')) {
            return __('tasks.shortcuts.search_hint_mac');
        }

        if (preg_match('/Windows|Linux|CrOS|X11/', $agent) === 1) {
            return __('tasks.shortcuts.search_hint');
        }

        return null;
    }
}
