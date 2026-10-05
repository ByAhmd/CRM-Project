<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\Facades\Filament;
use Filament\Panel;

/**
 * The panel a resource, page or widget belongs to (decision D-23), as
 * WorkspacePanelRegistry places it. A class no panel lists — a vendor page,
 * an auth page — falls back to Filament's current (or default) panel, which
 * is what Filament itself would have used.
 */
final class OwningPanel
{
    public static function of(string $class): Panel
    {
        $id = WorkspacePanelRegistry::owningPanelId($class);

        return $id === null ? Filament::getCurrentOrDefaultPanel() : Filament::getPanel($id);
    }
}
