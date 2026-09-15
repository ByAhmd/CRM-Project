<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Enums\ActivityKind;
use App\Models\ActivityType;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->app->detectEnvironment(static fn (): string => 'production');

        config()->set('app.debug', false);
        config()->set('app.url', 'https://crm.example');
        config()->set('session.secure', true);
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'smtp');
        config()->set('cache.default', 'file');

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
}
