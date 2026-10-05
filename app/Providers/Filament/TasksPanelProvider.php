<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\WorkspacePanel;
use App\Providers\Filament\Concerns\ConfiguresCrmPanel;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The Tasks workspace at /tasks (decision D-23): tasks, the tasks board,
 * routine tasks and the calendar; its home shows the user's tasks for today
 * and the upcoming follow-ups. Global search covers tasks.
 */
final class TasksPanelProvider extends PanelProvider
{
    use ConfiguresCrmPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureWorkspacePanel($panel, WorkspacePanel::Tasks)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k']);
    }
}
