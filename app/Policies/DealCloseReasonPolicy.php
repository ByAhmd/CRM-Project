<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ManagesSettings;

/**
 * Deal close reasons are configurable lookups (decision A-4): one permission,
 * settings.manage, covers viewing, editing and reordering. Permanent deletion
 * is never offered (D-13).
 */
final class DealCloseReasonPolicy
{
    use ManagesSettings;
}
