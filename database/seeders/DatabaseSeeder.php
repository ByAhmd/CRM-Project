<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Production-safe seed: reference data only, never demo records.
 * `php artisan db:seed` is required after every deploy (roles, permissions,
 * settings, lookup defaults); the first super admin is created with
 * `php artisan app:onboard`. Lookup seeders are idempotent and never
 * overwrite rows an administrator has renamed or reordered.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SettingsSeeder::class,
            LeadSourceSeeder::class,
            LeadStatusSeeder::class,
            IndustrySeeder::class,
            PipelineSeeder::class,
            ActivityTypeSeeder::class,
            DealCloseReasonSeeder::class,
            EmailTemplateSeeder::class,
        ]);
    }
}
