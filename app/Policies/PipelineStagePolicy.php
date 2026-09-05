<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ManagesSettings;

/**
 * Pipeline stages are configuration (decisions D-8, A-4): settings.manage
 * covers everything, including reordering inside the pipeline. The stage-set
 * invariants are business rules, enforced by PipelineService, not access
 * rules — so nothing is overridden here.
 */
final class PipelineStagePolicy
{
    use ManagesSettings;
}
