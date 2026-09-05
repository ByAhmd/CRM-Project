<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ManagesSettings;

/**
 * Tags are configuration (decision A-4): settings.manage covers every ability
 * and permanent deletion is never granted (D-13). Tags are not soft-deleted,
 * so a delete is the only removal path and it detaches the tag everywhere.
 */
final class TagPolicy
{
    use ManagesSettings;
}
