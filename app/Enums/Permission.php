<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every permission key the application checks (decision D-3).
 *
 * The value is the row in spatie's `permissions` table. Policies and Filament
 * gates only ever reference a case of this enum, never a literal string, so a
 * typo cannot silently grant or deny access. RolesAndPermissionsSeeder creates
 * a row for every case; RoleSeedingTest asserts that nothing is missing or
 * stale and PermissionMatrixTest pins what the seeded roles answer.
 *
 * Naming: `{entity}.{verb}`. For owned entities the three view verbs are
 * cumulative levels resolved by RecordVisibilityResolver (D-4):
 *   view_any  — the user's own records
 *   view_team — records owned by members of the user's team
 *   view_all  — every record
 */
enum Permission: string
{
    // Leads
    case LeadViewAny = 'lead.view_any';
    case LeadViewTeam = 'lead.view_team';
    case LeadViewAll = 'lead.view_all';
    case LeadCreate = 'lead.create';
    case LeadUpdate = 'lead.update';
    case LeadDelete = 'lead.delete';
    case LeadRestore = 'lead.restore';
    case LeadAssign = 'lead.assign';
    case LeadChangeStatus = 'lead.change_status';
    case LeadConvert = 'lead.convert';
    case LeadExport = 'lead.export';
    case LeadImport = 'lead.import';

    // Contacts
    case ContactViewAny = 'contact.view_any';
    case ContactViewTeam = 'contact.view_team';
    case ContactViewAll = 'contact.view_all';
    case ContactCreate = 'contact.create';
    case ContactUpdate = 'contact.update';
    case ContactDelete = 'contact.delete';
    case ContactRestore = 'contact.restore';
    case ContactAssign = 'contact.assign';
    case ContactMerge = 'contact.merge';
    case ContactExport = 'contact.export';
    case ContactImport = 'contact.import';

    // Accounts
    case AccountViewAny = 'account.view_any';
    case AccountViewTeam = 'account.view_team';
    case AccountViewAll = 'account.view_all';
    case AccountCreate = 'account.create';
    case AccountUpdate = 'account.update';
    case AccountDelete = 'account.delete';
    case AccountRestore = 'account.restore';
    case AccountAssign = 'account.assign';
    case AccountMerge = 'account.merge';
    case AccountSetType = 'account.set_type';
    case AccountExport = 'account.export';
    case AccountImport = 'account.import';

    // Deals
    case DealViewAny = 'deal.view_any';
    case DealViewTeam = 'deal.view_team';
    case DealViewAll = 'deal.view_all';
    case DealCreate = 'deal.create';
    case DealUpdate = 'deal.update';
    case DealDelete = 'deal.delete';
    case DealRestore = 'deal.restore';
    case DealAssign = 'deal.assign';
    case DealChangeStage = 'deal.change_stage';
    case DealClose = 'deal.close';
    case DealExport = 'deal.export';
    case DealImport = 'deal.import';

    // Activities (immutable events)
    case ActivityViewAny = 'activity.view_any';
    case ActivityViewTeam = 'activity.view_team';
    case ActivityViewAll = 'activity.view_all';
    case ActivityCreate = 'activity.create';
    case ActivityDelete = 'activity.delete';
    case ActivityAssign = 'activity.assign';
    case ActivityExport = 'activity.export';

    // Tasks
    case TaskViewAny = 'task.view_any';
    case TaskViewTeam = 'task.view_team';
    case TaskViewAll = 'task.view_all';
    case TaskCreate = 'task.create';
    case TaskUpdate = 'task.update';
    case TaskDelete = 'task.delete';
    case TaskAssign = 'task.assign';
    case TaskExport = 'task.export';

    // Notes and attachments (follow the visibility of their subject record)
    case NoteCreate = 'note.create';
    case NoteUpdate = 'note.update';
    case NoteDelete = 'note.delete';
    case AttachmentCreate = 'attachment.create';
    case AttachmentDownload = 'attachment.download';
    case AttachmentDelete = 'attachment.delete';

    // Email (D-10)
    case EmailSend = 'email.send';
    case EmailTemplateViewAny = 'email_template.view_any';
    case EmailTemplateCreate = 'email_template.create';
    case EmailTemplateUpdate = 'email_template.update';
    case EmailTemplateDelete = 'email_template.delete';

    // Products (D-8)
    case ProductViewAny = 'product.view_any';
    case ProductCreate = 'product.create';
    case ProductUpdate = 'product.update';
    case ProductDelete = 'product.delete';

    // Cross-cutting
    case SavedViewShare = 'saved_view.share';
    case ReportsView = 'reports.view';
    case ImportsView = 'imports.view';
    case ExportsView = 'exports.view';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
    case UsersManage = 'users.manage';
    case TeamsManage = 'teams.manage';
    case RolesManage = 'roles.manage';

    /** The part before the dot: the entity or area the permission belongs to. */
    public function group(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    /** The part after the dot. */
    public function verb(): string
    {
        return explode('.', $this->value, 2)[1];
    }

    public function label(): string
    {
        return __('permissions.verbs.'.$this->verb());
    }

    public function groupLabel(): string
    {
        return __('permissions.groups.'.$this->group());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Cases grouped by entity/area, in declaration order — the shape the Roles form renders.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $case) {
            $groups[$case->group()][] = $case;
        }

        return $groups;
    }
}
