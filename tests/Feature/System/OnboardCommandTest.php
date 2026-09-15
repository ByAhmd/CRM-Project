<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Enums\ActivityKind;
use App\Enums\CrmRole;
use App\Enums\LeadStatusKind;
use App\Enums\UserStatus;
use App\Models\ActivityType;
use App\Models\DealCloseReason;
use App\Models\EmailTemplate;
use App\Models\Industry;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OnboardCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_reference_data_and_creates_the_first_active_super_admin(): void
    {
        $this->assertSame(0, Role::query()->count());

        $this->artisan('app:onboard', [
            '--name' => 'Ahmed',
            '--email' => 'Admin@Example.com',
            '--password' => 'Correct-Horse-Battery-2026',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('Correct-Horse-Battery-2026', (string) $user->password));
        $this->assertSame(count(CrmRole::cases()), Role::query()->count());
        $this->assertDatabaseHas('settings', ['key' => 'general.currency']);
    }

    #[Test]
    public function a_fresh_install_is_usable_after_onboarding(): void
    {
        $this->artisan('app:onboard', [
            '--name' => 'Ahmed',
            '--email' => 'admin@example.com',
            '--password' => 'Correct-Horse-Battery-2026',
        ])->assertSuccessful();

        $this->assertTrue(LeadStatus::query()->where('is_default', true)->where('is_active', true)->exists());
        $this->assertTrue(LeadStatus::query()->where('kind', LeadStatusKind::Converted->value)->exists());
        $this->assertTrue(LeadSource::query()->exists());
        $this->assertTrue(Industry::query()->exists());

        $pipeline = Pipeline::query()->where('is_default', true)->firstOrFail();
        $this->assertTrue(PipelineStage::query()->where('pipeline_id', $pipeline->getKey())->where('is_default', true)->exists());

        foreach (ActivityKind::cases() as $kind) {
            $this->assertTrue(
                ActivityType::query()->where('kind', $kind->value)->where('is_system', true)->exists(),
                "No system activity type for {$kind->value}.",
            );
        }

        $this->assertTrue(DealCloseReason::query()->exists());
        $this->assertTrue(EmailTemplate::query()->exists());

        $this->artisan('app:preflight')
            ->doesntExpectOutputToContain('[preflight] FAIL')
            ->assertSuccessful();
    }

    #[Test]
    public function re_running_onboarding_repairs_missing_reference_rows_without_duplicating_any(): void
    {
        $this->artisan('app:onboard', ['--name' => 'Ahmed', '--email' => 'a@example.com', '--password' => 'Correct-Horse-Battery-2026'])
            ->assertSuccessful();

        $counts = $this->referenceCounts();
        ActivityType::query()->where('kind', ActivityKind::Email->value)->where('is_system', true)->delete();

        $this->artisan('app:onboard', ['--name' => 'Other', '--email' => 'b@example.com', '--password' => 'Correct-Horse-Battery-2026'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->assertSame($counts, $this->referenceCounts());
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function it_refuses_a_weak_password_and_a_second_super_admin(): void
    {
        $this->artisan('app:onboard', ['--name' => 'Ahmed', '--email' => 'a@example.com', '--password' => 'weak'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());

        $this->artisan('app:onboard', ['--name' => 'Ahmed', '--email' => 'a@example.com', '--password' => 'Correct-Horse-Battery-2026'])
            ->assertSuccessful();

        $this->artisan('app:onboard', ['--name' => 'Other', '--email' => 'b@example.com', '--password' => 'Correct-Horse-Battery-2026'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    /**
     * @return array<string, int>
     */
    private function referenceCounts(): array
    {
        return [
            'roles' => Role::query()->count(),
            'lead_sources' => LeadSource::query()->count(),
            'lead_statuses' => LeadStatus::query()->count(),
            'industries' => Industry::query()->count(),
            'pipelines' => Pipeline::withTrashed()->count(),
            'pipeline_stages' => PipelineStage::query()->count(),
            'activity_types' => ActivityType::query()->count(),
            'deal_close_reasons' => DealCloseReason::query()->count(),
            'email_templates' => EmailTemplate::withTrashed()->count(),
        ];
    }
}
