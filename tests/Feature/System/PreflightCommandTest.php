<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PreflightCommandTest extends TestCase
{
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
}
