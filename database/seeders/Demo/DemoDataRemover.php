<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Export;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\Access\RecordVisibilityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removes the rows one `app:demo-data` run recorded in DemoRegistry
 * (`app:demo-data --fresh`), in dependency order, inside one transaction.
 *
 * The rows are deleted permanently, through the query builder. That is
 * deliberate and limited to demo data: soft deletes (D-13) keep business
 * records restorable because somebody's work is in them, while these rows
 * were invented by the command and a soft-deleted demo account would keep
 * its e-mail addresses, names and audit trail in every "trashed" filter. The
 * query builder also bypasses the append-only observers of the audit ledger
 * and the activities, the same way the retention prune does, and writes no
 * deletion audit rows about records that never existed.
 *
 * Only registered ids are removed. Reference data (roles, permissions,
 * settings, lookups, pipelines) and every real user stay. Rows other people
 * added later keep the database's own rules: references declared
 * `nullOnDelete()` (a note or task someone added to a demo lead) are
 * cleared, and a real contact, deal or line item that still points at a
 * demo account or product through a `restrictOnDelete()` key stops the
 * removal with an explanation before anything is deleted. What belongs to the
 * demo users themselves goes with them: their roles, notifications,
 * sessions, password reset tokens, notification preferences and export files.
 *
 * Rows the registry does not hold can still point at a demo user: a contact
 * someone imported while signed in as `admin@demo.crm.test` (owner and
 * creator), its import run, an audit entry that demo user caused. Removing
 * the user would silently set those references to NULL (a record no rep can
 * see any more), cascade-delete the import and export history, or leave the
 * audit entry pointing at nobody. userReferences() lists them, read from the
 * database's own foreign keys on `users` (so a new owner column is covered
 * without a change here) plus the audit ledger's causer and subject, and
 * remove() refuses while any exist unless the caller explicitly allows the
 * orphans (`app:demo-data --fresh --allow-orphans`).
 */
final class DemoDataRemover
{
    private const CHUNK = 1000;

    /**
     * Tables whose rows referencing a user are that user's own settings or
     * bookkeeping; they are removed with the demo user and never listed.
     */
    private const USER_OWNED_TABLES = ['notification_preferences', 'notifications', 'sessions'];

    public function __construct(
        private readonly DemoRegistry $registry,
        private readonly PermissionRegistrar $permissions,
        private readonly RecordVisibilityResolver $visibility,
    ) {}

    /**
     * Real rows that would block the removal; empty when none.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $accounts = $this->registry->ids('accounts');
        $blockers = [];

        $checks = [
            'contacts' => ['account_id', $accounts, $this->registry->ids('contacts'), 'contact(s) not created by the demo belong to a demo account'],
            'deals' => ['account_id', $accounts, $this->registry->ids('deals'), 'deal(s) not created by the demo belong to a demo account'],
            'deal_products' => ['product_id', $this->registry->ids('products'), $this->registry->ids('deal_products'), 'deal line item(s) not created by the demo use a demo product'],
        ];

        foreach ($checks as $table => [$column, $referenced, $own, $message]) {
            $own = array_flip($own);
            $count = 0;

            foreach (array_chunk($referenced, self::CHUNK) as $chunk) {
                $count += DB::table($table)->whereIn($column, $chunk)->pluck('id')
                    ->filter(static fn (mixed $id): bool => ! isset($own[(int) $id]))
                    ->count();
            }

            if ($count > 0) {
                $blockers[] = $count.' '.$message;
            }
        }

        return $blockers;
    }

    /**
     * Rows not created by the recorded run that reference a demo user, one
     * line per table and column with what removing the users does to them;
     * empty when none.
     *
     * @return list<string>
     */
    public function userReferences(): array
    {
        $users = $this->registry->ids('users');

        if ($users === []) {
            return [];
        }

        $references = [];

        // The connection's own database only: without a schema the listing covers every database the account can read.
        foreach (array_unique(Schema::getTableListing(DB::connection()->getDatabaseName(), false)) as $table) {
            if (in_array($table, self::USER_OWNED_TABLES, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] !== 'users' || count($foreignKey['columns']) !== 1) {
                    continue;
                }

                $column = $foreignKey['columns'][0];
                $count = $this->countOutsideRegistry($table, $column, $users);

                if ($count > 0) {
                    $references[] = sprintf('%d %s row(s) not created by the demo have %s set to a demo user (%s)', $count, $table, $column, match (strtolower((string) $foreignKey['on_delete'])) {
                        'set null' => 'it would be set to NULL',
                        'cascade' => 'the rows would be deleted',
                        default => 'the removal would fail',
                    });
                }
            }
        }

        $userMorph = (new User)->getMorphClass();

        foreach (['causer' => 'caused by', 'subject' => 'about'] as $morph => $relation) {
            $count = $this->countOutsideRegistry('activity_log', $morph.'_id', $users, [$morph.'_type' => $userMorph]);

            if ($count > 0) {
                $references[] = sprintf('%d activity_log row(s) not created by the demo are %s a demo user (they would point at a user that no longer exists)', $count, $relation);
            }
        }

        return $references;
    }

    /**
     * @param  bool  $allowOrphans  remove the demo users even when userReferences() lists rows
     * @return array<string, int> table => rows removed
     */
    public function remove(bool $allowOrphans = false): array
    {
        $blockers = $this->blockers();

        if ($blockers !== []) {
            throw new RuntimeException('The demo data cannot be removed: '.implode('; ', $blockers).'. Reassign or delete those records first.');
        }

        $references = $allowOrphans ? [] : $this->userReferences();

        if ($references !== []) {
            throw new RuntimeException('The demo users cannot be removed: '.implode('; ', $references).'. Reassign or delete those records first, or allow the orphans explicitly.');
        }

        $counts = $this->registry->counts();
        $users = $this->registry->ids('users');
        $files = [];

        foreach (array_chunk($this->registry->ids('attachments'), self::CHUNK) as $chunk) {
            foreach (DB::table('attachments')->whereIn('id', $chunk)->get(['disk', 'path']) as $row) {
                $files[] = [(string) $row->disk, (string) $row->path];
            }
        }

        // Export files, like attachment files, are removed only once the rows are
        // gone: a delete that fails rolls the rows back and must leave every file.
        $exports = Export::query()->whereIn('user_id', $users)->get();

        DB::transaction(function () use ($users): void {
            $userMorph = (new User)->getMorphClass();

            $this->delete('activity_log', $this->registry->ids('activity_log'));
            $this->deleteWhere('notifications', 'notifiable_id', $users, ['notifiable_type' => $userMorph]);
            $this->delete('attachments', $this->registry->ids('attachments'));
            $this->delete('activities', $this->registry->ids('activities'));
            $this->delete('notes', $this->registry->ids('notes'));

            $tasks = $this->registry->ids('tasks');
            $this->update('tasks', 'series_id', $tasks, ['series_id' => null]);
            $this->delete('tasks', $tasks);

            foreach ([Lead::class => 'leads', Contact::class => 'contacts', Account::class => 'accounts', Deal::class => 'deals', Task::class => 'tasks'] as $class => $table) {
                $morph = (new $class)->getMorphClass();
                $this->deleteWhere('taggables', 'taggable_id', $this->registry->ids($table), ['taggable_type' => $morph]);
                $this->deleteWhere('custom_field_values', 'entity_id', $this->registry->ids($table), ['entity_type' => $morph]);
            }

            $this->delete('deal_products', $this->registry->ids('deal_products'));
            $this->delete('deal_stage_logs', $this->registry->ids('deal_stage_logs'));
            $this->delete('deals', $this->registry->ids('deals'));
            $this->delete('lead_status_logs', $this->registry->ids('lead_status_logs'));
            $this->delete('leads', $this->registry->ids('leads'));
            $this->delete('contacts', $this->registry->ids('contacts'));

            $accounts = $this->registry->ids('accounts');
            $this->update('accounts', 'parent_account_id', $accounts, ['parent_account_id' => null]);
            $this->delete('accounts', $accounts);
            $this->delete('products', $this->registry->ids('products'));

            $this->delete('notification_preferences', $this->registry->ids('notification_preferences'));

            $morphKey = (string) config('permission.column_names.model_morph_key', 'model_id');
            $this->deleteWhere((string) config('permission.table_names.model_has_roles', 'model_has_roles'), $morphKey, $users, ['model_type' => $userMorph]);
            $this->deleteWhere((string) config('permission.table_names.model_has_permissions', 'model_has_permissions'), $morphKey, $users, ['model_type' => $userMorph]);

            if (Schema::hasTable('sessions')) {
                $this->deleteWhere('sessions', 'user_id', $users, []);
            }

            $emails = DB::table('users')->whereIn('id', $users)->pluck('email')->map(static fn (mixed $email): string => (string) $email)->all();
            $this->deleteWhere((string) config('auth.passwords.users.table', 'password_reset_tokens'), 'email', $emails, []);

            $this->delete('users', $users);
            $this->delete('teams', $this->registry->ids('teams'));

            $this->registry->forget();
        });

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        foreach ($exports as $export) {
            $export->deleteFileDirectory();
        }

        $this->permissions->forgetCachedPermissions();
        $this->visibility->forgetTeamMembers();

        return $counts;
    }

    /**
     * Rows of the table whose column holds one of the values, not counting
     * the rows the registry recorded for that table.
     *
     * @param  list<int>  $values
     * @param  array<string, string>  $where
     */
    private function countOutsideRegistry(string $table, string $column, array $values, array $where = []): int
    {
        $keyed = Schema::hasColumn($table, 'id');
        $own = $keyed ? array_flip($this->registry->ids($table)) : [];
        $count = 0;

        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk);

            foreach ($where as $key => $value) {
                $query->where($key, $value);
            }

            $count += $keyed
                ? $query->pluck('id')->filter(static fn (mixed $id): bool => ! isset($own[(int) $id]))->count()
                : $query->count();
        }

        return $count;
    }

    /**
     * @param  list<int>  $ids
     */
    private function delete(string $table, array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table($table)->whereIn('id', $chunk)->delete();
        }
    }

    /**
     * @param  list<int|string>  $values
     * @param  array<string, string>  $where
     */
    private function deleteWhere(string $table, string $column, array $values, array $where = []): void
    {
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk);

            foreach ($where as $key => $value) {
                $query->where($key, $value);
            }

            $query->delete();
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, null>  $values
     */
    private function update(string $table, string $column, array $ids, array $values): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table($table)->whereIn($column, $chunk)->update($values);
        }
    }
}
