<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\CrmRole;
use App\Enums\Permission;

/**
 * Default permissions per seeded role (decisions D-3, D-4, D-13, A-12).
 *
 * This is NOT the runtime authority. spatie's tables are (D-3): super admins
 * edit roles in the panel and the policies read those tables. This class is
 * what RolesAndPermissionsSeeder writes on a fresh install and what the drift
 * test pins, so a deployment always starts from a reviewed baseline.
 *
 * Visibility defaults (D-4): sales_rep sees own records, sales_manager the
 * team's, admin / support / read_only everything. Sales reps may not reassign
 * (no *.assign) but may export within their own scope (D-13).
 */
final class RolePermissionMatrix
{
    /**
     * @return array<string, list<Permission>>
     */
    public static function defaults(): array
    {
        return [
            CrmRole::SuperAdmin->value => Permission::cases(),
            CrmRole::Admin->value => self::admin(),
            CrmRole::SalesManager->value => self::salesManager(),
            CrmRole::SalesRep->value => self::salesRep(),
            CrmRole::Support->value => self::support(),
            CrmRole::ReadOnly->value => self::readOnly(),
        ];
    }

    /**
     * @return list<Permission>
     */
    public static function for(CrmRole $role): array
    {
        return self::defaults()[$role->value];
    }

    /**
     * Everything except role administration, which is reserved to super admins.
     *
     * @return list<Permission>
     */
    private static function admin(): array
    {
        return array_filter(
            Permission::cases(),
            static fn (Permission $permission): bool => $permission !== Permission::RolesManage,
        );
    }

    /**
     * @return list<Permission>
     */
    private static function salesManager(): array
    {
        return [
            Permission::LeadViewAny, Permission::LeadViewTeam, Permission::LeadCreate, Permission::LeadUpdate,
            Permission::LeadDelete, Permission::LeadRestore, Permission::LeadAssign, Permission::LeadChangeStatus,
            Permission::LeadConvert, Permission::LeadExport, Permission::LeadImport,

            Permission::ContactViewAny, Permission::ContactViewTeam, Permission::ContactCreate, Permission::ContactUpdate,
            Permission::ContactDelete, Permission::ContactRestore, Permission::ContactAssign, Permission::ContactMerge,
            Permission::ContactExport, Permission::ContactImport,

            Permission::AccountViewAny, Permission::AccountViewTeam, Permission::AccountCreate, Permission::AccountUpdate,
            Permission::AccountDelete, Permission::AccountRestore, Permission::AccountAssign, Permission::AccountMerge,
            Permission::AccountExport, Permission::AccountImport,

            Permission::DealViewAny, Permission::DealViewTeam, Permission::DealCreate, Permission::DealUpdate,
            Permission::DealDelete, Permission::DealRestore, Permission::DealAssign, Permission::DealChangeStage,
            Permission::DealClose, Permission::DealExport, Permission::DealImport,

            Permission::ActivityViewAny, Permission::ActivityViewTeam, Permission::ActivityCreate,
            Permission::ActivityDelete, Permission::ActivityExport,

            Permission::TaskViewAny, Permission::TaskViewTeam, Permission::TaskCreate, Permission::TaskUpdate,
            Permission::TaskDelete, Permission::TaskAssign, Permission::TaskExport,

            Permission::NoteCreate, Permission::NoteUpdate, Permission::NoteDelete,
            Permission::AttachmentCreate, Permission::AttachmentDownload, Permission::AttachmentDelete,

            Permission::EmailSend, Permission::EmailTemplateViewAny,
            Permission::ProductViewAny,
            Permission::SavedViewShare, Permission::ReportsView, Permission::ImportsView, Permission::ExportsView,
        ];
    }

    /**
     * Own records only; no reassignment (D-4); export within own scope (D-13).
     *
     * @return list<Permission>
     */
    private static function salesRep(): array
    {
        return [
            Permission::LeadViewAny, Permission::LeadCreate, Permission::LeadUpdate, Permission::LeadChangeStatus,
            Permission::LeadConvert, Permission::LeadExport,

            Permission::ContactViewAny, Permission::ContactCreate, Permission::ContactUpdate, Permission::ContactExport,

            Permission::AccountViewAny, Permission::AccountCreate, Permission::AccountUpdate, Permission::AccountExport,

            Permission::DealViewAny, Permission::DealCreate, Permission::DealUpdate, Permission::DealChangeStage,
            Permission::DealClose, Permission::DealExport,

            Permission::ActivityViewAny, Permission::ActivityCreate, Permission::ActivityExport,

            Permission::TaskViewAny, Permission::TaskCreate, Permission::TaskUpdate, Permission::TaskDelete,
            Permission::TaskExport,

            Permission::NoteCreate, Permission::NoteUpdate, Permission::NoteDelete,
            Permission::AttachmentCreate, Permission::AttachmentDownload, Permission::AttachmentDelete,

            Permission::EmailSend, Permission::EmailTemplateViewAny,
            Permission::ProductViewAny,
            Permission::ReportsView,
        ];
    }

    /**
     * Reads everything and may log interactions (activities, tasks, notes),
     * but never changes commercial records.
     *
     * @return list<Permission>
     */
    private static function support(): array
    {
        return [
            Permission::LeadViewAny, Permission::LeadViewTeam, Permission::LeadViewAll,
            Permission::ContactViewAny, Permission::ContactViewTeam, Permission::ContactViewAll,
            Permission::AccountViewAny, Permission::AccountViewTeam, Permission::AccountViewAll,
            Permission::DealViewAny, Permission::DealViewTeam, Permission::DealViewAll,

            Permission::ActivityViewAny, Permission::ActivityViewTeam, Permission::ActivityViewAll,
            Permission::ActivityCreate,
            Permission::TaskViewAny, Permission::TaskViewTeam, Permission::TaskViewAll,
            Permission::TaskCreate, Permission::TaskUpdate,
            Permission::NoteCreate, Permission::NoteUpdate,
            Permission::AttachmentCreate, Permission::AttachmentDownload,

            Permission::EmailTemplateViewAny,
            Permission::ProductViewAny,
            Permission::ReportsView,
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function readOnly(): array
    {
        return [
            Permission::LeadViewAny, Permission::LeadViewTeam, Permission::LeadViewAll,
            Permission::ContactViewAny, Permission::ContactViewTeam, Permission::ContactViewAll,
            Permission::AccountViewAny, Permission::AccountViewTeam, Permission::AccountViewAll,
            Permission::DealViewAny, Permission::DealViewTeam, Permission::DealViewAll,
            Permission::ActivityViewAny, Permission::ActivityViewTeam, Permission::ActivityViewAll,
            Permission::TaskViewAny, Permission::TaskViewTeam, Permission::TaskViewAll,
            Permission::AttachmentDownload,
            Permission::EmailTemplateViewAny,
            Permission::ProductViewAny,
            Permission::ReportsView,
        ];
    }
}
