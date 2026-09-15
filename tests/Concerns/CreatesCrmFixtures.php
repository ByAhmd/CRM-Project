<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\ActivityKind;
use App\Enums\CloseReasonKind;
use App\Enums\CrmRole;
use App\Enums\LeadStatusKind;
use App\Enums\Permission;
use App\Enums\StageKind;
use App\Models\ActivityType;
use App\Models\Deal;
use App\Models\DealCloseReason;
use App\Models\Import;
use App\Models\LeadStatus;
use App\Models\PipelineStage;
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
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Facades\Filament;
use League\Csv\Reader;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fixture helpers shared by feature tests (CLAUDE.md section 3: fixtures come
 * from this trait, never from copies in the test classes).
 *
 * Permissions are cached in the array store for the whole test process while
 * the database is rolled back between tests, so every seed and every role or
 * permission sync also flushes the spatie cache; otherwise a later test would
 * read permission ids from a transaction that no longer exists.
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

    /**
     * A user holding exactly the given permissions and nothing through a role
     * (buildable through the Roles resource, D-3), optionally in a team.
     *
     * @param  list<Permission>  $permissions
     */
    protected function userWithPermissions(?Team $team, array $permissions): User
    {
        $user = User::factory()->create(['team_id' => $team?->getKey()]);
        $user->syncRoles([]);
        $user->syncPermissions(array_map(static fn (Permission $permission): string => $permission->value, $permissions));

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

    /** The first active seeded lead status of a kind, in status order (needs seedLookups()). */
    protected function statusOfKind(LeadStatusKind $kind): LeadStatus
    {
        return LeadStatus::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }

    /** The first active seeded close reason of a kind, in reason order (needs seedLookups()). */
    protected function reasonOfKind(CloseReasonKind $kind): DealCloseReason
    {
        return DealCloseReason::query()->where('kind', $kind->value)->where('is_active', true)->orderBy('sort')->firstOrFail();
    }

    /** The system activity type of a kind — one is seeded per kind (needs seedLookups()). */
    protected function typeOfKind(ActivityKind $kind): ActivityType
    {
        return ActivityType::query()->where('kind', $kind->value)->where('is_system', true)->firstOrFail();
    }

    /** The n-th stage of a kind in the deal's pipeline, in pipeline order. */
    protected function stageOfKind(Deal $deal, StageKind $kind, int $position = 0): PipelineStage
    {
        return PipelineStage::query()
            ->where('pipeline_id', $deal->pipeline_id)
            ->where('kind', $kind->value)
            ->orderBy('sort')
            ->orderBy('id')
            ->skip($position)
            ->firstOrFail();
    }

    /** The n-th Open stage of the deal's pipeline, in pipeline order (0 = the default stage). */
    protected function openStage(Deal $deal, int $position): PipelineStage
    {
        return $this->stageOfKind($deal, StageKind::Open, $position);
    }

    /**
     * A table column-manager state with the named column toggled on, ready
     * for `applyTableColumnManager` (columns hidden by default, such as
     * custom-field columns).
     *
     * @param  array<int, array<string, mixed>>  $state
     * @return array<int, array<string, mixed>>
     */
    protected function columnStateWith(array $state, string $name): array
    {
        foreach ($state as $index => $item) {
            if (($item['name'] ?? null) === $name) {
                $state[$index]['isToggled'] = true;
            }
        }

        return $state;
    }

    /**
     * The rows of a CSV under tests/Fixtures/imports, keyed by header.
     *
     * @return list<array<string, string>>
     */
    protected function importFixtureRows(string $fixture): array
    {
        $reader = Reader::createFromPath(base_path('tests/Fixtures/imports/'.$fixture));
        $reader->setHeaderOffset(0);

        return array_values(iterator_to_array($reader->getRecords()));
    }

    /** The reason the import refused the row whose $column holds $value; fails the test when no such row failed. */
    protected function failedImportReason(Import $import, string $column, string $value): ?string
    {
        foreach (FailedImportRow::query()->where('import_id', $import->getKey())->get() as $row) {
            if (($row->data[$column] ?? null) === $value) {
                return $row->validation_error;
            }
        }

        $this->fail("no failed row with {$column} = {$value}");
    }
}
