<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Console\Commands\PreflightCommand;
use App\Enums\ActivityKind;
use App\Enums\Permission as PermissionKey;
use App\Enums\UserStatus;
use App\Models\ActivityType;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Support\System\PlatformRequirements;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

final class PreflightCommandTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAccess();
        $this->seedLookups();
        $this->superAdmin();
    }

    #[Test]
    public function a_local_configuration_passes_with_the_mail_warning(): void
    {
        config()->set('mail.default', 'log');

        $this->artisan('app:preflight')
            ->expectsOutputToContain('MAIL_MAILER is "log"')
            ->expectsOutputToContain('[preflight] OK')
            ->assertSuccessful();
    }

    #[Test]
    public function a_dangerous_production_configuration_refuses_to_go_live(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        config()->set('app.debug', true);
        config()->set('app.url', 'http://crm.example');
        config()->set('session.secure', false);
        config()->set('queue.default', 'sync');

        $this->artisan('app:preflight')
            ->expectsOutputToContain('APP_DEBUG is true')
            ->expectsOutputToContain('APP_URL is not https')
            ->expectsOutputToContain('SESSION_SECURE_COOKIE is not true')
            ->expectsOutputToContain('QUEUE_CONNECTION is sync')
            ->expectsOutputToContain('Refusing to go live')
            ->assertFailed();
    }

    #[Test]
    public function a_correct_production_configuration_passes(): void
    {
        $this->productionReady();

        $this->artisan('app:preflight')
            ->expectsOutputToContain('[preflight] OK (production environment, 0 warnings)')
            ->assertSuccessful();
    }

    #[Test]
    public function a_pending_migration_refuses_to_go_live(): void
    {
        $latest = (string) DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $latest)->delete();

        $this->artisan('app:preflight')
            ->expectsOutputToContain('1 migration is pending (first: '.$latest.')')
            ->expectsOutputToContain('Refusing to go live')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_default_lead_status_refuses_to_go_live(): void
    {
        LeadStatus::query()->where('is_default', true)->update(['is_default' => false]);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('No active default lead status')
            ->expectsOutputToContain('Refusing to go live')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_converted_lead_status_refuses_to_go_live(): void
    {
        DB::table('lead_statuses')->where('kind', 'converted')->delete();

        $this->artisan('app:preflight')
            ->expectsOutputToContain('No lead status of kind "converted"')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_default_pipeline_refuses_to_go_live(): void
    {
        Pipeline::query()->where('is_default', true)->update(['is_default' => false]);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('No active default pipeline')
            ->expectsOutputToContain('Refusing to go live')
            ->assertFailed();
    }

    #[Test]
    public function a_default_pipeline_without_a_default_stage_refuses_to_go_live(): void
    {
        $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();
        PipelineStage::query()->where('pipeline_id', $pipeline->getKey())->update(['is_default' => false]);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('The default pipeline has no default stage')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_system_activity_type_refuses_to_go_live(): void
    {
        ActivityType::query()->where('is_system', true)->where('kind', ActivityKind::Email->value)->delete();
        ActivityType::query()->where('is_system', true)->where('kind', ActivityKind::System->value)->delete();

        $this->artisan('app:preflight')
            ->expectsOutputToContain('System activity types are missing for email, system')
            ->expectsOutputToContain('Refusing to go live')
            ->assertFailed();
    }

    #[Test]
    public function an_empty_database_lists_every_missing_reference_row(): void
    {
        DB::table('pipeline_stages')->delete();
        DB::table('pipelines')->delete();
        DB::table('lead_statuses')->delete();
        DB::table('activity_types')->delete();

        $this->artisan('app:preflight')
            ->expectsOutputToContain('No active default lead status')
            ->expectsOutputToContain('No lead status of kind "converted"')
            ->expectsOutputToContain('No active default pipeline')
            ->expectsOutputToContain('System activity types are missing for '.implode(', ', array_map(static fn (ActivityKind $kind): string => $kind->value, ActivityKind::cases())))
            ->assertFailed();
    }

    #[Test]
    public function a_blank_application_key_refuses_to_go_live(): void
    {
        config()->set('app.key', '');

        $this->artisan('app:preflight')
            ->expectsOutputToContain('APP_KEY is not set')
            ->assertFailed();
    }

    #[Test]
    public function a_php_older_than_the_floor_refuses_to_go_live(): void
    {
        $this->app->instance(PlatformRequirements::class, new PlatformRequirements('8.2.12', get_loaded_extensions()));

        $this->artisan('app:preflight')
            ->expectsOutputToContain('PHP 8.2.12 is running; the CRM needs PHP 8.3.0 or newer')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_required_extension_refuses_to_go_live(): void
    {
        $loaded = array_values(array_filter(get_loaded_extensions(), static fn (string $extension): bool => ! in_array(strtolower($extension), ['intl', 'zip'], true)));
        $this->app->instance(PlatformRequirements::class, new PlatformRequirements(PHP_VERSION, $loaded));

        $this->artisan('app:preflight')
            ->expectsOutputToContain('Required PHP extensions are not loaded: intl, zip')
            ->assertFailed();
    }

    #[Test]
    public function the_required_extensions_are_the_ones_composer_json_requires_and_this_php_loads_them(): void
    {
        /** @var array{require: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $required = array_map(
            static fn (string $package): string => substr($package, 4),
            array_values(array_filter(array_keys($composer['require']), static fn (string $package): bool => str_starts_with($package, 'ext-'))),
        );

        $this->assertEqualsCanonicalizing(PlatformRequirements::REQUIRED_EXTENSIONS, $required);
        $this->assertSame('^8.3', $composer['require']['php']);
        $this->assertSame([], (new PlatformRequirements)->missingExtensions());
        $this->assertTrue((new PlatformRequirements)->phpIsSupported());
    }

    #[Test]
    public function the_array_cache_store_refuses_to_go_live_in_production(): void
    {
        $this->productionReady();
        config()->set('cache.default', 'array');

        $this->artisan('app:preflight')
            ->expectsOutputToContain('CACHE_STORE is array')
            ->assertFailed();
    }

    #[Test]
    public function a_session_lifetime_other_than_the_decided_one_warns_in_production(): void
    {
        $this->productionReady();
        config()->set('session.lifetime', 480);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('SESSION_LIFETIME is 480 minutes; the decided lifetime is 120')
            ->expectsOutputToContain('[preflight] OK (production environment, 1 warning)')
            ->assertSuccessful();
    }

    #[Test]
    public function uncached_configuration_routes_views_and_components_warn_in_production_with_the_optimize_hint(): void
    {
        $this->productionReady();
        $this->app->instance('config_loaded_from_cache', false);
        $this->app->instance('routes.cached', false);
        config()->set('view.compiled', $this->scratchDirectory('views-empty'));
        config()->set('filament.cache_path', $this->scratchDirectory('filament-empty'));

        $this->artisan('app:preflight')
            ->expectsOutputToContain('Not cached: configuration, routes, views, Filament components — run `php artisan optimize` and `php artisan filament:optimize`')
            ->assertSuccessful();
    }

    #[Test]
    public function the_cache_warning_is_production_only(): void
    {
        $this->app->instance('config_loaded_from_cache', false);
        $this->app->instance('routes.cached', false);

        $this->artisan('app:preflight')
            ->doesntExpectOutputToContain('Not cached:')
            ->doesntExpectOutputToContain('CRM_TRUSTED_PROXIES')
            ->assertSuccessful();
    }

    #[Test]
    public function missing_trusted_proxies_warn_in_production(): void
    {
        $this->productionReady();
        config()->set('crm.trusted_proxies', null);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('CRM_TRUSTED_PROXIES is not set')
            ->expectsOutputToContain('[preflight] OK (production environment, 1 warning)')
            ->assertSuccessful();
    }

    #[Test]
    public function an_attachments_disk_under_public_refuses_to_go_live(): void
    {
        config()->set('filesystems.disks.'.config('crm.attachments.disk').'.root', public_path('uploads'));

        $this->artisan('app:preflight')
            ->expectsOutputToContain('is rooted under public/')
            ->assertFailed();
    }

    #[Test]
    public function unwritable_storage_and_bootstrap_cache_directories_refuse_to_go_live(): void
    {
        $missing = $this->scratchDirectory('gone').DIRECTORY_SEPARATOR.'missing';
        $this->app->useStoragePath($missing);
        $this->app->useBootstrapPath($missing);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('storage/app is not writable')
            ->expectsOutputToContain('storage/logs is not writable')
            ->expectsOutputToContain('bootstrap/cache is not writable')
            ->assertFailed();
    }

    #[Test]
    public function an_incomplete_permission_catalogue_refuses_to_go_live(): void
    {
        DB::table('permissions')->where('name', PermissionKey::LeadConvert->value)->delete();

        $this->artisan('app:preflight')
            ->expectsOutputToContain(sprintf('The permissions table has %d rows; the catalogue (App\Enums\Permission) has %d', count(PermissionKey::cases()) - 1, count(PermissionKey::cases())))
            ->assertFailed();
    }

    #[Test]
    public function no_active_super_admin_refuses_to_go_live(): void
    {
        User::query()->update(['status' => UserStatus::Disabled->value]);

        $this->artisan('app:preflight')
            ->expectsOutputToContain('No active super admin')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_or_stale_scheduler_heartbeat_warns_and_a_fresh_one_does_not(): void
    {
        Cache::forget(PreflightCommand::HEARTBEAT_KEY);

        $this->artisan('app:preflight')
            // One output line: the warning and the cron line of DEPLOYMENT.md section 3.10 (an absolute PHP binary, never a bare `php`).
            ->expectsOutputToContain('No scheduler heartbeat — `schedule:run` has not run in the last ten minutes: reminders, the queue drain and the retention prunes are not happening; add the cron entry `* * * * * cd /path/to/app && <php> artisan schedule:run >> /dev/null 2>&1` on the host, with <php> the absolute path of the PHP 8.3 CLI binary (docs/DEPLOYMENT.md section 3.10, D-1).')
            ->assertSuccessful();

        Cache::put(PreflightCommand::HEARTBEAT_KEY, now()->subMinutes(7)->toIso8601String(), now()->addMinutes(10));

        $this->artisan('app:preflight')
            ->expectsOutputToContain('The last scheduler heartbeat is 7 minutes old')
            ->assertSuccessful();

        Cache::put(PreflightCommand::HEARTBEAT_KEY, now()->subMinute()->toIso8601String(), now()->addMinutes(10));

        $this->artisan('app:preflight')
            ->doesntExpectOutputToContain('heartbeat')
            ->assertSuccessful();
    }

    #[Test]
    public function the_json_option_reports_status_failures_and_warnings_with_the_same_exit_codes(): void
    {
        config()->set('mail.default', 'log');

        $this->assertSame(0, Artisan::call('app:preflight', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $this->assertSame(['status', 'failures', 'warnings'], array_keys($report));
        $this->assertSame('ok', $report['status']);
        $this->assertSame([], $report['failures']);
        $this->assertContains('MAIL_MAILER is "log" — invitations, password resets and mail notifications are not delivered (D-10).', $report['warnings']);

        config()->set('app.key', '');

        $this->assertSame(1, Artisan::call('app:preflight', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($report);
        $this->assertSame('failed', $report['status']);
        $this->assertContains('APP_KEY is not set — encrypted casts and sessions cannot work.', $report['failures']);
        $this->assertStringNotContainsString('[preflight]', Artisan::output());
    }

    /**
     * A production configuration that passes with no warning: every setting
     * decided, caches built, proxies trusted and the scheduler alive.
     */
    private function productionReady(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        config()->set('app.debug', false);
        config()->set('app.url', 'https://crm.example');
        config()->set('session.secure', true);
        config()->set('session.lifetime', 120);
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'smtp');
        config()->set('cache.default', 'database');
        config()->set('crm.trusted_proxies', '10.0.0.0/8');

        $this->app->instance('config_loaded_from_cache', true);
        $this->app->instance('routes.cached', true);

        $views = $this->scratchDirectory('views');
        file_put_contents($views.DIRECTORY_SEPARATOR.'0123456789abcdef.php', '<?php // compiled');
        config()->set('view.compiled', $views);

        $filament = $this->scratchDirectory('filament');
        mkdir($filament.DIRECTORY_SEPARATOR.'panels');
        file_put_contents($filament.DIRECTORY_SEPARATOR.'panels'.DIRECTORY_SEPARATOR.'admin.php', '<?php return [];');
        config()->set('filament.cache_path', $filament);

        Cache::put(PreflightCommand::HEARTBEAT_KEY, now()->toIso8601String(), now()->addMinutes(10));
    }

    /** An empty directory under the system temp directory, removed after the test. */
    private function scratchDirectory(string $name): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'crm-preflight-'.getmypid().'-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);

        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            (new Filesystem)->deleteDirectory($directory);
        });

        return $directory;
    }
}
