<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\WorkspacePanel;
use App\Providers\Filament\Concerns\ConfiguresCrmPanel;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The Administration workspace at /admin (decision D-23, superseding A-2's
 * single panel): users, roles, teams, general settings, every lookup list,
 * pipelines, custom fields, lead-scoring rules, the audit log and backups;
 * its home links to the administration screens the user may open. No global
 * search — nothing here is a searchable record.
 *
 * Sign-in, password reset and the profile moved to the home panel (/app);
 * their former /admin addresses answer permanent redirects (routes/web.php).
 */
final class AdminPanelProvider extends PanelProvider
{
    use ConfiguresCrmPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureWorkspacePanel($panel, WorkspacePanel::Admin)
            ->globalSearch(false);
    }
}
