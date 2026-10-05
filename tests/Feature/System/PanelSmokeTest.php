<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The panels boot, the root is the public landing page (D-24) whose way in is the one sign-in
 * page, the workspace screen sends a guest there (D-23), and the default locale/direction reach
 * the HTML of the sign-in page.
 */
final class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_url_is_the_landing_page_and_its_way_in_is_the_sign_in_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.url('/app/login').'"', escape: false);

        $this->get('/app')->assertRedirect('/app/login');
    }

    #[Test]
    public function the_sign_in_page_renders_in_arabic_and_rtl_by_default(): void
    {
        $this->get('/app/login')
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
