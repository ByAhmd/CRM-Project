<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\CrmRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\ActivityTypeSeeder;
use Database\Seeders\DealCloseReasonSeeder;
use Database\Seeders\IndustrySeeder;
use Database\Seeders\LeadSourceSeeder;
use Database\Seeders\LeadStatusSeeder;
use Database\Seeders\PipelineSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixture helpers shared by feature tests.
 *
 * Permissions are cached in the array store for the whole test process while
 * the database is rolled back between tests, so every seed also flushes the
 * spatie cache; otherwise a later test would read permission ids from a
 * transaction that no longer exists.
 */
trait CreatesCrmFixtures
{
    protected function seedAccess(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The production lookup defaults (statuses, sources, pipeline, …). */
    protected function seedLookups(): void
    {
        $this->seed([
            LeadSourceSeeder::class,
            LeadStatusSeeder::class,
            IndustrySeeder::class,
            PipelineSeeder::class,
            ActivityTypeSeeder::class,
            DealCloseReasonSeeder::class,
        ]);
    }

    protected function usePanel(): void
    {
        Filament::setCurrentPanel('admin');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(CrmRole $role, array $attributes = [], ?Team $team = null): User
    {
        $user = User::factory()->create($attributes + ['team_id' => $team?->getKey()]);
        $user->syncRoles([$role->value]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh() ?? $user;
    }

    protected function superAdmin(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::SuperAdmin, ['name' => 'Super Admin'], $team);
    }

    protected function admin(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::Admin, ['name' => 'Admin User'], $team);
    }

    protected function salesManager(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::SalesManager, ['name' => 'Sales Manager'], $team);
    }

    protected function salesRep(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::SalesRep, ['name' => 'Sales Rep'], $team);
    }

    protected function support(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::Support, ['name' => 'Support User'], $team);
    }

    protected function readOnly(?Team $team = null): User
    {
        return $this->makeUser(CrmRole::ReadOnly, ['name' => 'Read Only User'], $team);
    }

    protected function makeTeam(string $nameEn = 'Riyadh Team', string $nameAr = 'فريق الرياض', ?User $manager = null): Team
    {
        return Team::factory()->create([
            'name_en' => $nameEn,
            'name_ar' => $nameAr,
            'manager_user_id' => $manager?->getKey(),
        ]);
    }
}
