<?php

declare(strict_types=1);

namespace App\Services\System;

use RuntimeException;

/**
 * Another backup run holds the backup lock (decision D-16): the scheduled run
 * and a *Back up now* never write at the same time. Not a failure — nothing is
 * reported or notified; the run already under way produces the set.
 */
final class BackupInProgressException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Another backup run is in progress; this one did not start.');
    }
}
