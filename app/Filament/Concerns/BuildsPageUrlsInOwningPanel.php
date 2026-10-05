<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\Filament\OwningPanel;
use Filament\Panel;

/**
 * A page builds its route name — and so every URL to it — against the panel
 * that registers it (decision D-23), whatever panel the request, job or
 * command happens to run in; the page-level twin of BuildsUrlsInOwningPanel.
 * An explicit panel (route registration always passes one) is kept as given.
 */
trait BuildsPageUrlsInOwningPanel
{
    public static function getRouteName(?Panel $panel = null): string
    {
        return parent::getRouteName($panel ?? OwningPanel::of(static::class));
    }
}
