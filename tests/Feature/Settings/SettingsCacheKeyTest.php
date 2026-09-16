<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The settings cache entry is scoped to the database it was read from.
 *
 * One organisation means one set of settings rows (D-2), so the scope never
 * varies within a running deployment. It matters when the same cache store is
 * reached by a process pointed at another database — a restore drill, a
 * maintenance shell with DB_DATABASE overridden, a staging copy that shares a
 * cache prefix. An unscoped key would let that process warm the entry from its
 * own database, and every later request would read the wrong currency,
 * timezone and week start until the entry expired twelve hours later.
 */
final class SettingsCacheKeyTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function the_warmed_entry_is_stored_under_the_database_it_was_read_from(): void
    {
        $this->seedLookups();

        Cache::flush();

        app(SettingsRepository::class)->all();

        $database = DB::connection()->getDatabaseName();

        $this->assertTrue(
            Cache::has('crm.settings:'.$database),
            'The settings were not cached under the database they were read from.',
        );

        $this->assertFalse(
            Cache::has('crm.settings'),
            'The settings are still cached under an unscoped key, which a process pointed at another database would read.',
        );
    }

    #[Test]
    public function an_entry_warmed_from_one_database_is_not_visible_to_another(): void
    {
        $this->seedLookups();

        Cache::flush();

        app(SettingsRepository::class)->all();

        $this->assertTrue(Cache::has('crm.settings:'.DB::connection()->getDatabaseName()));

        // A second deployment sharing this cache store, pointed at its own database.
        $this->assertFalse(
            Cache::has('crm.settings:crm_restore_drill'),
            'A database this cache was never warmed from can read the other database settings.',
        );
    }

    #[Test]
    public function a_write_invalidates_the_entry_for_this_database(): void
    {
        $this->seedAccess();
        $this->seedLookups();

        $actor = $this->superAdmin();

        $settings = app(SettingsRepository::class);
        $settings->all();

        $key = 'crm.settings:'.DB::connection()->getDatabaseName();

        $this->assertTrue(Cache::has($key));

        $settings->update([SettingsRepository::ORGANISATION_NAME => 'Renamed'], $actor);

        $this->assertFalse(
            Cache::has($key),
            'A settings write left the scoped cache entry in place, so readers keep the stale value.',
        );

        $this->assertSame('Renamed', app(SettingsRepository::class)->organisationName());
    }
}
