<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Schema;

use App\Enums\ActivityKind;
use App\Models\ActivityType;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Probe item 7: the documented install path (README "Local setup", plan
 * section 9 deploy: `migrate --force` then `app:onboard`) never runs the
 * lookup seeders. app:onboard only seeds roles, permissions and settings, so a
 * fresh install has no lead status (a lead cannot be created), no pipeline (a
 * deal cannot be created) and no system activity types (task completion,
 * conversion and email logging throw) — and app:preflight still says OK.
 */
final class FreshInstallReferenceDataProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function onboarding_a_fresh_database_leaves_a_usable_crm(): void
    {
        $this->artisan('app:onboard', [
            '--name' => 'First Admin',
            '--email' => 'first@example.com',
            '--password' => 'Correct-Horse-Battery-2026',
        ])->assertSuccessful();

        $this->assertTrue(LeadStatus::query()->where('is_default', true)->exists(), 'No default lead status after onboarding.');
        $this->assertTrue(Pipeline::query()->where('is_default', true)->exists(), 'No default pipeline after onboarding.');
        $this->assertSame(
            count(ActivityKind::cases()),
            ActivityType::query()->where('is_system', true)->count(),
            'The system activity types ActivityRecorder::recordSystem() needs are missing after onboarding.',
        );
    }
}
