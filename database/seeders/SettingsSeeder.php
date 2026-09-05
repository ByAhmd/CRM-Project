<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * Seeds the organisation settings from config defaults (decisions D-2, D-8).
 * Existing values are never overwritten: an administrator's choice survives a
 * redeploy.
 */
final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        app(SettingsRepository::class)->seedDefaults();
    }
}
