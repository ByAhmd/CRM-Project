<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The production-only wiring (D-1, D-13, section 7 of the plan) exists and is
 * shaped as documented, so a deploy cannot silently lose it.
 */
final class ProductionWiringTest extends TestCase
{
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
    public function the_locale_and_timezone_defaults_are_the_decided_ones(): void
    {
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        $this->assertSame(['ar', 'en'], config('app.locales'));
        $this->assertSame('SAR', config('crm.currency'));
        $this->assertSame(120, config('session.lifetime'));
    }
}
