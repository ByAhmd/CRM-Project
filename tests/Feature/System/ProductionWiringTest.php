<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The production-only wiring (D-1, D-11, D-13, section 7 of the plan) exists
 * and is shaped as documented, so a deploy cannot silently lose it.
 */
final class ProductionWiringTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        return array_map(
            static fn ($event): string => (string) $event->command,
            app(Schedule::class)->events(),
        );
    }

    #[Test]
    public function the_scheduler_drains_the_database_queue_every_minute(): void
    {
        $commands = implode("\n", $this->scheduledCommands());

        $this->assertStringContainsString('queue:work --stop-when-empty --max-time=50', $commands);
    }

    #[Test]
    public function the_scheduler_prunes_the_audit_ledger_after_the_configured_retention(): void
    {
        $commands = implode("\n", $this->scheduledCommands());

        $this->assertStringContainsString('activitylog:clean --days='.config('crm.audit.retention_days').' --force', $commands);
        $this->assertSame(730, config('crm.audit.retention_days'));
        $this->assertStringContainsString('queue:prune-failed --hours=168', $commands);
    }

    #[Test]
    public function every_response_carries_the_security_headers(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    #[Test]
    public function there_is_no_registration_route_and_the_password_reset_is_throttled(): void
    {
        $this->assertFalse(Route::has('filament.admin.auth.register'));
        $this->assertTrue(Route::has('filament.admin.auth.password-reset.request'));
        $this->assertTrue(Route::has('filament.admin.auth.profile'));

        $this->get('/admin/register')->assertNotFound();
    }

    #[Test]
    public function the_login_form_locks_out_after_five_failed_attempts(): void
    {
        $this->seedAccess();
        $this->usePanel();
        $user = $this->salesRep();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            Livewire::test(Login::class)
                ->fillForm(['email' => $user->email, 'password' => 'Wrong-Password-'.$attempt])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        // The sixth attempt is refused with a throttle notice, even with the right password.
        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertGuest();
    }

    #[Test]
    public function multi_factor_authentication_is_offered_to_everyone_and_required_of_no_one(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasMultiFactorAuthentication(), 'D-11: MFA providers are wired');
        $this->assertCount(2, $panel->getMultiFactorAuthenticationProviders());
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired(), 'D-11: MFA is optional');
    }

    #[Test]
    public function the_locale_and_timezone_defaults_are_the_decided_ones(): void
    {
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        $this->assertSame(['ar', 'en'], config('app.locales'));
        $this->assertSame('SAR', config('crm.currency'));
        $this->assertSame(120, config('session.lifetime'));
    }
}
