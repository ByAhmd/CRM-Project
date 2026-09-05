<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ManagesSettings;

/**
 * Scoring rules are settings (decision D-7): settings.manage.
 */
final class LeadScoringRulePolicy
{
    use ManagesSettings;
}
