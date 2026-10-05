<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\WorkspacePanel;
use App\Providers\Filament\Concerns\ConfiguresCrmPanel;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * The Sales workspace at /sales (decision D-23): leads, deals, the deal
 * board, contacts, accounts, products, activities, email templates and the
 * import and export history; its home is the sales dashboard. Global search
 * covers leads, contacts, accounts and deals.
 */
final class SalesPanelProvider extends PanelProvider
{
    use ConfiguresCrmPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureWorkspacePanel($panel, WorkspacePanel::Sales)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k']);
    }
}
