<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The panel boots, the root redirects into it, and the default locale/direction reach the HTML.
 */
final class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_url_redirects_to_the_admin_login(): void
    {
        $this->get('/')->assertRedirect('/admin/login');
    }

    #[Test]
    public function the_admin_login_page_renders_in_arabic_and_rtl_by_default(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('lang="ar"', escape: false)
            ->assertSee('dir="rtl"', escape: false);
    }

    #[Test]
    public function the_health_endpoint_answers(): void
    {
        $this->get('/up')->assertOk();
    }
}
