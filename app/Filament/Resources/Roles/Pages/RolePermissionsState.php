<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Enums\Permission;

/**
 * Converts between the flat permission list RoleService speaks and the
 * per-group checkbox lists the role form renders.
 */
final class RolePermissionsState
{
    /**
     * @param  list<string>  $permissions
     * @return array<string, list<string>>
     */
    public static function group(array $permissions): array
    {
        $grouped = [];

        foreach (Permission::grouped() as $group => $cases) {
            $grouped[$group] = [];
        }

        foreach ($permissions as $permission) {
            $case = Permission::tryFrom($permission);

            if ($case !== null) {
                $grouped[$case->group()][] = $case->value;
            }
        }

        return $grouped;
    }

    /**
     * @param  mixed  $state  array<string, list<string>> from the form
     * @return list<string>
     */
    public static function flatten(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $flat = [];

        foreach ($state as $list) {
            foreach ((array) $list as $permission) {
                if (is_string($permission) && Permission::tryFrom($permission) !== null) {
                    $flat[] = $permission;
                }
            }
        }

        return array_values(array_unique($flat));
    }
}
