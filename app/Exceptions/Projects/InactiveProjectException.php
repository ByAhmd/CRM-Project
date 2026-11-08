<?php

declare(strict_types=1);

namespace App\Exceptions\Projects;

use App\Models\Project;
use DomainException;

/**
 * A user asked to work in a project that is switched off (decision D-30).
 * The switcher and the import select never offer one, so this guards the
 * service against a stale id.
 */
final class InactiveProjectException extends DomainException
{
    public static function for(Project $project): self
    {
        return new self(sprintf('Project %d is inactive and cannot be made current.', (int) $project->getKey()));
    }
}
