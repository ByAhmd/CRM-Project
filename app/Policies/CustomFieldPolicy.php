<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CustomField;
use App\Models\User;
use App\Policies\Concerns\ManagesSettings;
use App\Services\CustomFields\CustomFieldService;

/**
 * Custom field definitions are settings (decision D-9): settings.manage covers
 * viewing and editing them.
 *
 * Removal has one extra guard, the same one CustomFieldService enforces: a
 * definition that already carries values is deactivated, never deleted, so a
 * cascade can never take stored values with it (D-13). The table's action is
 * disabled for the same reason it is unauthorised — the UI is never the guard.
 *
 * Values themselves have no policy of their own: they belong to their record,
 * so whoever may update the record may write its values and whoever may view
 * it may read them. That is not left to the pages —
 * CustomFieldValueService::fill() authorises `update` on the record before it
 * validates anything, and its reader authorises `view` — so an importer, a
 * bulk action or a future endpoint cannot reach around it.
 */
final class CustomFieldPolicy
{
    use ManagesSettings;

    public function delete(User $user, ?CustomField $record = null): bool
    {
        if (! $user->can(Permission::SettingsManage->value)) {
            return false;
        }

        if ($record === null) {
            return true;
        }

        return app(CustomFieldService::class)->isDeletable($record);
    }
}
