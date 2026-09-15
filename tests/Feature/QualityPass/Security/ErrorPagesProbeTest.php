<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: error pages (plan section 7 — "APP_DEBUG=false enforced by
 * preflight; friendly translated error pages").
 *
 * There is no resources/views/errors directory and no lang/ar.json, so every
 * 403 / 404 / 419 / 500 falls back to Laravel's English default views in an
 * Arabic-default, RTL application.
 */
final class ErrorPagesProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    #[Test]
    public function a_not_found_page_is_rendered_in_the_default_arabic_locale(): void
    {
        $this->seedAccess();

        $response = $this->get('/this-route-does-not-exist');

        $response->assertNotFound();
        $response->assertDontSee('Not Found');
    }
}
