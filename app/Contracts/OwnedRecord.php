<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A record with an owner whose visibility is resolved per D-4.
 *
 * Implemented by every model RecordVisibilityResolver scopes (leads, contacts,
 * accounts, deals, activities, tasks). The permission group is the prefix of
 * the entity's Permission keys (`lead`, `deal`, …).
 */
interface OwnedRecord
{
    public static function permissionGroup(): string;

    public static function ownerColumn(): string;
}
