<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoDataBuilder;
use Database\Seeders\Demo\DemoDataRemover;
use Database\Seeders\Demo\DemoDataset;
use Database\Seeders\Demo\DemoRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Seeds (or removes) a realistic bilingual Saudi demo dataset for local
 * walks, staging reviews and query plans on volume (plan step 13).
 *
 * - Never in production: exit 1 before anything is read or written.
 * - Asks for confirmation unless --force (a non-interactive run without
 *   --force is cancelled).
 * - Runs the production-safe reference seed first (DatabaseSeeder), then
 *   builds the data through the real services — LeadStatusWorkflow,
 *   LeadConversionWorkflow, DealStageWorkflow, DealCloseService, TaskService,
 *   ActivityRecorder, NoteService, AttachmentStorage, RoleService,
 *   NotificationPreferenceService — so every invariant, status
 *   and stage log, notification and audit row is the one the panel would
 *   have written. History is spread over the last 90 days.
 * - Demo users (`<role>@demo.crm.test`, plus `sales_manager2@` and
 *   `sales_rep2@` for the second team) are active and share one random
 *   password, printed once at the end of the run and stored nowhere else
 *   (only its hash is in the users table).
 * - Refuses a second run while an earlier run's data is recorded in the
 *   registry, and refuses when a demo e-mail, team or product name is taken.
 * - `--fresh` removes exactly the rows the recorded run created (permanently:
 *   see DemoDataRemover for why that is right for demo data), the demo
 *   attachment files and the registry, and stops. It refuses, listing them,
 *   while rows the run did not create reference a demo user (a contact
 *   imported as a demo admin, its import run, an audit entry): removing the
 *   user would null their owner, delete the history or leave the audit entry
 *   pointing at nobody. `--allow-orphans` removes the users anyway, after
 *   printing the same list.
 * - `--scale=N` multiplies accounts, contacts, leads, deals, tasks and
 *   activities by N, for EXPLAIN on volume.
 *
 * Operator-facing CLI output is plain English, like app:onboard and
 * app:preflight.
 */
final class DemoDataCommand extends Command
{
    private const MAX_SCALE = 50;

    protected $signature = 'app:demo-data
        {--force : run without confirmation}
        {--fresh : remove the demo data this command created, then stop}
        {--allow-orphans : with --fresh, remove the demo users even when records the demo did not create still reference them}
        {--scale=1 : multiply the volume, for load testing only}';

    protected $description = 'Seed a bilingual demo dataset through the real services (never in production), or remove it with --fresh';

    public function handle(DemoRegistry $registry, DemoDataBuilder $builder, DemoDataRemover $remover): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('Demo data is never seeded in production (APP_ENV=production). Nothing was changed.');

            return self::FAILURE;
        }

        if ((bool) $this->option('fresh')) {
            return $this->fresh($registry, $remover);
        }

        $scale = $this->scale();

        if ($scale === null) {
            $this->components->error(sprintf('--scale must be a whole number from 1 to %d.', self::MAX_SCALE));

            return self::FAILURE;
        }

        if ($registry->exists()) {
            $this->components->error('Demo data from an earlier run exists. Run `php artisan app:demo-data --fresh` first.');

            return self::FAILURE;
        }

        if (! $this->confirmed(sprintf('Seed demo data (scale %d) into the "%s" database?', $scale, (string) config('database.connections.'.config('database.default').'.database')))) {
            $this->components->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $this->components->info('Seeding reference data (roles, permissions, settings, lookups, pipeline, activity types, templates)…');
        $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $conflicts = $builder->conflicts();

        if ($conflicts !== []) {
            foreach ($conflicts as $conflict) {
                $this->components->error('Cannot seed demo data: '.$conflict.'.');
            }

            return self::FAILURE;
        }

        $password = $this->password();
        $this->components->info('Building the demo dataset through the services…');

        try {
            $context = $builder->build($scale, $password);
        } catch (Throwable $exception) {
            $this->components->error('Demo seeding failed and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->summary($registry, $context, $password);

        return self::SUCCESS;
    }

    private function fresh(DemoRegistry $registry, DemoDataRemover $remover): int
    {
        if (! $registry->exists()) {
            $this->components->info('No demo data is recorded. Nothing to remove.');

            return self::SUCCESS;
        }

        $allowOrphans = (bool) $this->option('allow-orphans');
        $references = $remover->userReferences();

        if ($references !== [] && ! $allowOrphans) {
            $this->components->error('The demo users cannot be removed: records the demo did not create still reference them. Nothing was changed.');
            $this->components->bulletList($references);
            $this->line('  Reassign those records to a real user (or delete them) and run --fresh again, or add --allow-orphans to remove the demo users anyway.');

            return self::FAILURE;
        }

        if ($references !== []) {
            $this->components->warn('Records the demo did not create reference a demo user; --allow-orphans removes the users anyway:');
            $this->components->bulletList($references);
        }

        if (! $this->confirmed('Permanently remove the demo data recorded by the last app:demo-data run?')) {
            $this->components->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $counts = $remover->remove($allowOrphans);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Table', 'Rows removed'], array_map(
            static fn (string $table, int $count): array => [$table, (string) $count],
            array_keys($counts),
            array_values($counts),
        ));
        $this->components->info($references === []
            ? 'Demo data removed. Reference data and real users were left untouched.'
            : 'Demo data removed. Reference data and real users were left untouched; the records listed above were orphaned or deleted as described.');

        return self::SUCCESS;
    }

    private function scale(): ?int
    {
        $value = trim((string) $this->option('scale'));

        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $scale = (int) $value;

        return $scale >= 1 && $scale <= self::MAX_SCALE ? $scale : null;
    }

    private function confirmed(string $question): bool
    {
        return (bool) $this->option('force') || $this->confirm($question, false);
    }

    /**
     * Sixteen characters with upper case, lower case and a digit, so it also
     * passes the panel's password rule if a demo user changes it (D-11).
     */
    private function password(): string
    {
        do {
            $password = Str::password(16, symbols: false);
        } while (preg_match('/[A-Z]/', $password) !== 1 || preg_match('/[a-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1);

        return $password;
    }

    private function summary(DemoRegistry $registry, DemoContext $context, string $password): void
    {
        $this->table(['Table', 'Rows created'], array_map(
            static fn (string $table, int $count): array => [$table, (string) $count],
            array_keys($registry->counts()),
            array_values($registry->counts()),
        ));

        $roles = [];

        foreach (DemoDataset::users() as $definition) {
            $roles[$definition['key']] = $definition['role']->value;
        }

        $rows = [];

        foreach ($context->users as $key => $user) {
            $team = null;

            foreach ($context->teams as $candidate) {
                if ((int) $candidate->getKey() === (int) $user->team_id) {
                    $team = $candidate->name_en;
                }
            }

            $rows[] = [(string) $user->email, $roles[$key] ?? '', (string) $user->name, $team ?? '-'];
        }

        $this->table(['Demo user', 'Role', 'Name', 'Team'], $rows);

        $this->newLine();
        $this->line('  <comment>Password for every demo user (shown once, stored nowhere):</comment> '.$password);
        $this->newLine();
        $this->components->info(sprintf('Demo data seeded (scale %d). Remove it with `php artisan app:demo-data --fresh`.', $context->scale));
    }
}
