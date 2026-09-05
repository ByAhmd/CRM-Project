<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ManagesSettings;

/**
 * Competitors are configuration (decision A-4): one permission, settings.manage.
 * Permanent deletion is never offered (D-13).
 */
final class CompetitorPolicy
{
    use ManagesSettings;
}
