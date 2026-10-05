<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\Filament\OwningPanel;
use Filament\Panel;

/**
 * A resource builds its route names — and so every URL to it — against the
 * panel that registers it (decision D-23), whatever panel the request, job
 * or command happens to run in.
 *
 * Filament resolves a missing panel to the current (or default) one, which
 * in a notification sent from a sales page, a queued job, a scheduled
 * command or a mail points at a panel that does not serve the resource and
 * throws RouteNotFoundException. An explicit panel (route registration
 * always passes one) is kept as given.
 */
trait BuildsUrlsInOwningPanel
{
    public static function getRouteBaseName(?Panel $panel = null): string
    {
        return parent::getRouteBaseName($panel ?? OwningPanel::of(static::class));
    }
}
