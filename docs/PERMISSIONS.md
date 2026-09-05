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
| `admin` | all | everything except `roles.manage` |
| `sales_manager` | team | full CRUD on leads/contacts/accounts/deals/activities/tasks, reassign, convert, change stage, close, merge, import, export, send email, share views, reports |
| `sales_rep` | own | create/update own leads/contacts/accounts/deals, change status/stage, convert, close, log activities, tasks, notes, attachments, send email, export **own scope only**; no reassign, no import, no delete of commercial records |
| `support` | all (read) | read everything, log activities/notes/tasks, download attachments; no commercial writes |
| `read_only` | all (read) | read everything, download attachments, reports |

## Guards above the permissions

- Nobody may delete or disable their own account.
- The last active `super_admin` cannot be demoted, disabled or deleted (`RoleService::isLastActiveSuperAdmin`).
- Seeded roles keep their machine key; `super_admin` cannot be edited at all.
- Permanent deletion is never granted (`forceDelete` = false everywhere, D-13).
- The audit ledger is append-only (`ActivityLogAppendOnlyObserver`) and readable only with `audit.view`.

## Adding a permission

1. Add the case to `App\Enums\Permission` (`{group}.{verb}`), and the verb/group labels to `lang/{ar,en}/permissions.php`.
2. Grant it in `RolePermissionMatrix` for the roles that should hold it by default.
3. Check it in the policy (`$this->verb($user, 'verb', $record)` for owned entities).
4. Run `php artisan db:seed` — existing editable roles keep their current sets; super admins grant the new key from the Roles screen.

`Tests\Feature\Access\RoleSeedingTest` and `PermissionMatrixTest` fail if the catalogue, the seeder and the matrix disagree.
