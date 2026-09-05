<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Every event key written to the audit ledger (decision A-5).
 *
 * The `description` column of activity_log stores these stable identifiers;
 * labels live in lang/{locale}/activity.php. Each case belongs to a log name
 * (the ledger's channel) so the audit screen can filter by area.
 *
 * Modules append their own cases as they are built; the value format is
 * `{subject}.{event}` and must stay stable once written to a database.
 */
enum ActivityLogEvent: string implements HasLabel
{
    // Authentication
    case AuthLogin = 'auth.login';
    case AuthLogout = 'auth.logout';
    case AuthFailed = 'auth.failed';
    case AuthPasswordReset = 'auth.password_reset';

    // Users
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserRestored = 'user.restored';
    case UserInvited = 'user.invited';
    case UserRolesChanged = 'user.roles_changed';

    // Roles and permissions
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RoleDeleted = 'role.deleted';
    case RolePermissionsChanged = 'role.permissions_changed';

    // Teams
    case TeamCreated = 'team.created';
    case TeamUpdated = 'team.updated';
    case TeamDeleted = 'team.deleted';
    case TeamRestored = 'team.restored';

    // Leads
    case LeadCreated = 'lead.created';
    case LeadUpdated = 'lead.updated';
    case LeadDeleted = 'lead.deleted';
    case LeadRestored = 'lead.restored';
    case LeadAssigned = 'lead.assigned';
    case LeadStatusChanged = 'lead.status_changed';
    case LeadQualified = 'lead.qualified';
    case LeadConverted = 'lead.converted';

    // Accounts
    case AccountCreated = 'account.created';
    case AccountUpdated = 'account.updated';
    case AccountDeleted = 'account.deleted';
    case AccountRestored = 'account.restored';
    case AccountAssigned = 'account.assigned';
    case AccountMerged = 'account.merged';
    case AccountBecameCustomer = 'account.became_customer';

    // Contacts
    case ContactCreated = 'contact.created';
    case ContactUpdated = 'contact.updated';
    case ContactDeleted = 'contact.deleted';
    case ContactRestored = 'contact.restored';
    case ContactAssigned = 'contact.assigned';
    case ContactMerged = 'contact.merged';

    // Deals
    case DealCreated = 'deal.created';
    case DealUpdated = 'deal.updated';
    case DealDeleted = 'deal.deleted';
    case DealRestored = 'deal.restored';
    case DealAssigned = 'deal.assigned';
    case DealStageChanged = 'deal.stage_changed';
    case DealWon = 'deal.won';
    case DealLost = 'deal.lost';
    case DealReopened = 'deal.reopened';

    // Settings and configurable lookups (statuses, sources, pipelines, stages, types, reasons, tags, products…)
    case SettingsUpdated = 'settings.updated';
    case LookupCreated = 'settings.lookup_created';
    case LookupUpdated = 'settings.lookup_updated';
    case LookupDeleted = 'settings.lookup_deleted';
    case LookupRestored = 'settings.lookup_restored';

    public function getLabel(): string
    {
        // Values contain a dot, so they are looked up as literal keys rather than
        // through the translator's dot-path resolution.
        $labels = __('activity.events');

        return is_array($labels) ? (string) ($labels[$this->value] ?? $this->value) : $this->value;
    }

    /** The ledger channel (`log_name`) the event is written to. */
    public function logName(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public static function tryFromDescription(?string $description): ?self
    {
        if ($description === null || $description === '') {
            return null;
        }

        return self::tryFrom($description);
    }

    /**
     * @return list<string>
     */
    public static function logNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (self $case): string => $case->logName(),
            self::cases(),
        )));
    }
}
