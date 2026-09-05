<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ManagesSettings;

/**
 * Lead sources are configurable lookups (decision A-4): settings.manage
 * covers every operation; permanent deletion is never granted (D-13).
 */
final class LeadSourcePolicy
{
    use ManagesSettings;
}
