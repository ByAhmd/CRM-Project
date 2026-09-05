<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Policies\Concerns\ChecksPermissions;

/**
 * Exercises the shared policy trait against OwnedStub before the real owned
 * entities exist.
 */
final class OwnedStubPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return OwnedStub::permissionGroup();
    }
}
