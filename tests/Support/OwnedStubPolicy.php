<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Policies\Concerns\ChecksPermissions;

/**
 * The shared ChecksPermissions trait with nothing layered on top, so its
 * permission-plus-scope rules are tested in isolation from the verb
 * overrides and state guards of any real entity policy.
 */
final class OwnedStubPolicy
{
    use ChecksPermissions;

    protected function permissionGroup(): string
    {
        return OwnedStub::permissionGroup();
    }
}
