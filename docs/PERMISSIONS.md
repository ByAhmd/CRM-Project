# Permission model

Decisions D-3, D-4, D-13 and A-12 as implemented in the foundation.

## Where authority lives

| Layer | Class | Answers |
|---|---|---|
| Permission catalogue | `App\Enums\Permission` | Every key the code checks (`lead.view_any`, `deal.change_stage`, `settings.manage`, …). The seeder creates a row per case and prunes stale rows. |
| Default roles | `App\Support\Access\RolePermissionMatrix` + `Database\Seeders\RolesAndPermissionsSeeder` | What a fresh install grants each of the six seeded roles. Editable roles are **not** reset by re-seeding; `super_admin` is always re-granted everything. |
| Runtime authority | spatie `roles` / `permissions` tables, edited through the **Roles** resource (`roles.manage`, super admins only) | What a role may do right now. Every change is written to the audit ledger by `App\Services\Access\RoleService`. |
| Record scope | `App\Services\Access\RecordVisibilityResolver` | Which records of an owned entity a user reaches: own (`*.view_any`), team (`*.view_team`), all (`*.view_all`). |
| Enforcement | `App\Policies\*` (owned entities use `Policies\Concerns\ChecksPermissions`), Filament `can*` methods, `->authorize()` on actions, `->authorizeIndividualRecords()` on bulk actions, `ScopesQueriesToVisibleRecords` on resource queries | Both the permission and the scope must pass. |

## Seeded roles

| Role | Visibility | May do |
|---|---|---|
| `super_admin` | all | everything, including role administration; locked (cannot be renamed, deleted or stripped) |
| `admin` | all | everything except `roles.manage` (including `account.set_type` and `activity.assign`); `users.manage` never reaches a super admin account |
| `sales_manager` | team | create, update, delete and restore leads/contacts/accounts/deals; create, update, delete and restore the team's tasks (there is no `task.restore` key: `TaskPolicy::restore()` is granted with `task.delete`, so the restore actions on the task pages and the table's restore bulk action are open to this role); log and delete activities (activities are immutable: no update); reassign all six (incl. `activity.assign`), change lead status, convert, change stage, close, merge contacts and accounts, import and export the four commercial entities, export activities and tasks; notes, attachments (upload, download, delete); send email, read email templates and products, share views, reports; holds `imports.view` / `exports.view`, which at team level opens only its own runs (see below); no settings, users, teams, roles or audit; may not set the account type by hand |
| `sales_rep` | own | create/update own leads/contacts/accounts/deals, change status/stage, convert, close, log activities (no delete), create, update, delete and restore own tasks (restore is granted with `task.delete`, `TaskPolicy::restore()`), create/update/delete notes, attachments (upload, download, delete), send email, read email templates and products, reports, export the four commercial entities plus activities and tasks **own scope only**; no reassign, no import, no merge, no delete or restore of commercial records (leads, contacts, accounts, deals), no import/export history of others; the account type follows the deals (no `account.set_type`) |
| `support` | all (read) | read leads, contacts, accounts, deals, activities and tasks at all level, email templates and products; log activities; create and update notes and tasks; upload (`attachment.create`) and download attachments; export leads/contacts/accounts/deals within scope (D-13); reports; no commercial writes, no deletes, no reassign, no import |
| `read_only` | all (read) | read leads, contacts, accounts, deals, activities and tasks at all level, email templates and products; download attachments; export leads/contacts/accounts/deals within scope (D-13); reports; writes nothing |

## Guards above the permissions

- Nobody may delete, disable or set back to pending their own account (`RoleService::assertStatusChange`).
- The last active `super_admin` cannot be demoted, disabled, set to pending or deleted (`RoleService::isLastActiveSuperAdmin`).
- Only a `roles.manage` holder (a super admin) grants, removes or edits `super_admin`. `users.manage` never reaches a super admin account: update, status, invite, delete and restore are all refused (`UserPolicy`, `RoleService::syncUserRoles` / `assertStatusChange`, A-12).
- Pending is reached only through an invitation; the status field offers Active and Disabled, and Pending only while the account still is pending.
- Import/export history: a user always sees and downloads their own runs. `exports.view` / `imports.view` open another user's run only for entities the holder reaches at all level (`{entity}.view_all`). The rule is enforced on the history screens and on Filament's `/filament/exports/{export}/download` and `/filament/imports/{import}/failed-rows/download` routes (policies registered for Filament's base models too).
- An account that cannot sign in (disabled, pending) reaches no import/export run or file, even with a live session, and receives no notification on any channel.
- Event notifications go only to recipients who can open the record (`NotificationRecipients`). A team's manager is chosen among the team's active members.
- A lifecycle type is set by hand only with `account.set_type` (D-6); otherwise a prospect becomes a customer on its first won deal.
- A change of owner through any form, assign action or importer goes through `RecordAssignmentService` (`{entity}.assigned` audit event and a notification to the new owner); only active users are assignable. An import row that names a new owner for an existing record needs that record's `assign` verb, and the reassignment is written in the row's savepoint (`ResolvesImportLookups`). A new record created for another owner is a creation, not a reassignment.
- Soft-deleted records are frozen: every write verb refuses a trashed record; restore is the only action left.
- Seeded roles keep their machine key; `super_admin` cannot be edited at all.
- Permanent deletion is never granted (`forceDelete` = false everywhere, D-13).
- The audit ledger is append-only (`ActivityLogAppendOnlyObserver`) and readable only with `audit.view`.

## Adding a permission

1. Add the case to `App\Enums\Permission` (`{group}.{verb}`), and the verb/group labels to `lang/{ar,en}/permissions.php`.
2. Grant it in `RolePermissionMatrix` for the roles that should hold it by default.
3. Check it in the policy (`$this->verb($user, 'verb', $record)` for owned entities).
4. Run `php artisan db:seed` — existing editable roles keep their current sets; super admins grant the new key from the Roles screen.

`Tests\Feature\Access\RoleSeedingTest` and `PermissionMatrixTest` fail if the catalogue, the seeder and the matrix disagree.
