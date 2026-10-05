<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration for decision D-23 (2026-10-05): the four workspace keys —
 * `workspace.sales`, `workspace.tasks`, `workspace.reports`,
 * `workspace.admin` — decide which workspaces a role may enter, and every
 * role that exists before the release must already hold the right ones, or
 * its users would sign in to a workspace screen of locked cards.
 *
 * RolesAndPermissionsSeeder creates the permission rows and gives a role its
 * matrix defaults only when it creates the role (D-3: runtime edits survive a
 * redeploy), and the deploy runs `migrate` before `db:seed`, so this
 * migration creates the four rows itself and grants them to the roles that
 * are already there:
 *
 * - The seven seeded roles, by key, get the D-23 defaults: super_admin and
 *   admin all four; sales_manager, sales_rep, support and read_only Sales,
 *   Tasks and Reports; employee Tasks.
 * - Every other (custom) role gets a workspace when it holds any permission
 *   one of the workspace's pages needs to open or to act:
 *     Sales   — any `lead.*`, `contact.*`, `account.*`, `deal.*`,
 *               `activity.*`, `product.*`, `email_template.*` or `note.*`
 *               key (notes live on lead, contact, account and deal pages),
 *               `email.send`, `imports.view`, `exports.view` or
 *               `task.export` (the import and export history is a Sales
 *               screen and opens with any import or export key);
 *     Tasks   — any `task.*` key (tasks, the board and the calendar open
 *               with `task.view_any`, routine tasks with `task.assign`;
 *               `task.export` acts on the task list, so it reaches both
 *               Tasks and the Sales export history);
 *     Reports — `reports.view`;
 *     Admin   — an administration key only: `users.manage`,
 *               `roles.manage`, `teams.manage`, `settings.manage` (general
 *               settings, every lookup list, pipelines, custom fields,
 *               lead-scoring rules) or `audit.view`; backups open with
 *               `roles.manage`.
 *   Attachment keys grant nothing on their own: a file is reached through a
 *   record whose own key already decides the workspace.
 *
 * Grants are added, never removed, so running it twice changes nothing. The
 * keys are written out here rather than read from the Permission enum, so
 * the migration keeps meaning what it meant on the day it shipped. A fresh
 * install runs it before anything is seeded: it creates the four rows, finds
 * no role, and the seeder then grants the defaults. down() removes the four
 * keys and every grant of them, roles and users alike.
 */
return new class extends Migration
{
    private const array WORKSPACE_KEYS = ['workspace.sales', 'workspace.tasks', 'workspace.reports', 'workspace.admin'];

    /** @var array<string, list<string>> */
    private const array SEEDED_DEFAULTS = [
        'super_admin' => ['workspace.sales', 'workspace.tasks', 'workspace.reports', 'workspace.admin'],
        'admin' => ['workspace.sales', 'workspace.tasks', 'workspace.reports', 'workspace.admin'],
        'sales_manager' => ['workspace.sales', 'workspace.tasks', 'workspace.reports'],
        'sales_rep' => ['workspace.sales', 'workspace.tasks', 'workspace.reports'],
        'support' => ['workspace.sales', 'workspace.tasks', 'workspace.reports'],
        'read_only' => ['workspace.sales', 'workspace.tasks', 'workspace.reports'],
        'employee' => ['workspace.tasks'],
    ];

    /** Permission groups (the part before the dot) whose every key opens or acts on a Sales page. */
    private const array SALES_GROUPS = ['lead', 'contact', 'account', 'deal', 'activity', 'product', 'email_template', 'note'];

    private const array SALES_KEYS = ['email.send', 'imports.view', 'exports.view', 'task.export'];

    private const array TASK_GROUPS = ['task'];

    private const array REPORT_KEYS = ['reports.view'];

    private const array ADMIN_KEYS = ['users.manage', 'roles.manage', 'teams.manage', 'settings.manage', 'audit.view'];

    public function up(): void
    {
        $permissionIds = $this->ensureWorkspacePermissions();

        $roles = DB::table($this->table('roles'))
            ->where('guard_name', 'web')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($roles as $role) {
            $granted = self::SEEDED_DEFAULTS[(string) $role->name] ?? $this->derive($this->heldKeys($role->id));

            foreach ($granted as $key) {
                DB::table($this->table('role_has_permissions'))->insertOrIgnore([
                    'permission_id' => $permissionIds[$key],
                    'role_id' => $role->id,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table($this->table('permissions'))
            ->where('guard_name', 'web')
            ->whereIn('name', self::WORKSPACE_KEYS)
            ->pluck('id');

        DB::table($this->table('role_has_permissions'))->whereIn('permission_id', $ids)->delete();
        DB::table($this->table('model_has_permissions'))->whereIn('permission_id', $ids)->delete();
        DB::table($this->table('permissions'))->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Creates whichever of the four rows is missing and returns every id by key.
     *
     * @return array<string, int|string>
     */
    private function ensureWorkspacePermissions(): array
    {
        $ids = [];

        foreach (self::WORKSPACE_KEYS as $key) {
            /** @var int|string|null $id */
            $id = DB::table($this->table('permissions'))
                ->where('name', $key)
                ->where('guard_name', 'web')
                ->value('id');

            if ($id === null) {
                $id = DB::table($this->table('permissions'))->insertGetId([
                    'name' => $key,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$key] = $id;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function heldKeys(int|string $roleId): array
    {
        /** @var list<string> $keys */
        $keys = DB::table($this->table('role_has_permissions'))
            ->join($this->table('permissions'), $this->table('permissions').'.id', '=', $this->table('role_has_permissions').'.permission_id')
            ->where($this->table('role_has_permissions').'.role_id', $roleId)
            ->pluck($this->table('permissions').'.name')
            ->map(static fn (mixed $name): string => (string) $name)
            ->values()
            ->all();

        return $keys;
    }

    /**
     * The workspaces a custom role reaches through the keys it already holds.
     *
     * @param  list<string>  $held
     * @return list<string>
     */
    private function derive(array $held): array
    {
        $groups = array_map(static fn (string $key): string => explode('.', $key, 2)[0], $held);
        $granted = [];

        if (array_intersect($groups, self::SALES_GROUPS) !== [] || array_intersect($held, self::SALES_KEYS) !== []) {
            $granted[] = 'workspace.sales';
        }

        if (array_intersect($groups, self::TASK_GROUPS) !== []) {
            $granted[] = 'workspace.tasks';
        }

        if (array_intersect($held, self::REPORT_KEYS) !== []) {
            $granted[] = 'workspace.reports';
        }

        if (array_intersect($held, self::ADMIN_KEYS) !== []) {
            $granted[] = 'workspace.admin';
        }

        return $granted;
    }

    private function table(string $key): string
    {
        return (string) config('permission.table_names.'.$key, $key);
    }
};
