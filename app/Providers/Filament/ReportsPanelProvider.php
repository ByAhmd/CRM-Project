<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\WorkspacePanel;
use App\Providers\Filament\Concerns\ConfiguresCrmPanel;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The Reports workspace at /reports (decision D-23): the nine report pages
 * at /reports/<report>; its home lists the reports the user may open. No
 * global search — nothing here is a searchable record.
 */
final class ReportsPanelProvider extends PanelProvider
{
    use ConfiguresCrmPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureWorkspacePanel($panel, WorkspacePanel::Reports)
            ->globalSearch(false);
    }
}
