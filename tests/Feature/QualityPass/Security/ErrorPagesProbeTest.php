<?php

declare(strict_types=1);

namespace Tests\Feature\QualityPass\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Security probe: error pages (plan section 7 — "APP_DEBUG=false enforced by
 * preflight; friendly translated error pages").
 *
 * `resources/views/errors/` holds a page per status, all rendered from the
 * shared 500 template with an inline `<style>` block: an error page must not
 * depend on the Vite build, because the build is exactly what may be broken
 * when one is shown.
 *
 * With `APP_DEBUG=false` — what production runs, and what preflight refuses to
 * go live without — every one of them must render its own translated page and
 * leak nothing about the internals. A stack frame, a vendor path, an exception
 * class or a fragment of SQL on an error page hands an attacker the framework
 * version, the directory layout and often the schema.
 */
final class ErrorPagesProbeTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    /** The statuses that have a page of their own. */
    private const STATUSES = [403, 404, 419, 429, 500, 503];

    /**
     * Markers that only ever appear when the debug handler renders: Laravel's
     * debug page, a stack frame, or a path that exposes where the code lives.
     */
    private const LEAKS = [
        'Whoops',
        'Stack trace',
        '#0 ',
        'vendor/laravel',
        'vendor\\laravel',
        'CRM_Project',
        'Illuminate\\',
        'Symfony\\Component',
        'SQLSTATE',
    ];

    #[Test]
    public function every_error_page_renders_its_own_translated_page_with_debug_off(): void
    {
        $this->seedAccess();

        config(['app.debug' => false]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (self::STATUSES as $status) {
                $response = $this->probe($status);

                $response->assertStatus($status);

                $response->assertSee(__('errors.pages.'.$status.'.title'), escape: false);
                $response->assertSee(__('errors.fields.code', ['code' => $status]), escape: false);

                $this->assertStringNotContainsString(
                    'errors.pages.',
                    $response->getContent() ?: '',
                    "the {$status} page in [{$locale}] printed a raw translation key",
                );
            }
        }
    }

    #[Test]
    public function an_error_page_never_leaks_a_stack_trace_or_an_internal_path(): void
    {
        $this->seedAccess();

        config(['app.debug' => false]);

        foreach (self::STATUSES as $status) {
            $content = $this->probe($status)->getContent() ?: '';

            foreach (self::LEAKS as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $content,
                    "the {$status} page leaks [{$leak}] — with APP_DEBUG=false it must say nothing about the internals",
                );
            }
        }
    }

    #[Test]
    public function an_unhandled_exception_is_shown_as_the_friendly_500_page_not_as_the_exception(): void
    {
        $this->seedAccess();

        config(['app.debug' => false]);
        app()->setLocale('ar');

        Route::get('/__probe/boom', function (): void {
            throw new \RuntimeException('the database password is hunter2');
        })->middleware('web');

        $response = $this->get('/__probe/boom');

        $response->assertStatus(500);
        $response->assertSee(__('errors.pages.500.title'), escape: false);

        $content = $response->getContent() ?: '';

        $this->assertStringNotContainsString(
            'hunter2',
            $content,
            'the exception message reached the page — an exception message routinely carries credentials or SQL',
        );

        $this->assertStringNotContainsString('RuntimeException', $content, 'the exception class reached the page');
    }

    #[Test]
    public function a_not_found_page_is_rendered_in_the_default_arabic_locale(): void
    {
        $this->seedAccess();

        $response = $this->get('/this-route-does-not-exist');

        $response->assertNotFound();
        $response->assertDontSee('Not Found');
    }

    /**
     * Renders $status through the real routing and exception pipeline, the way
     * a visitor reaches it, rather than rendering the view directly.
     */
    private function probe(int $status): TestResponse
    {
        Route::get('/__probe/'.$status, fn () => abort($status))->middleware('web');

        return $this->get('/__probe/'.$status);
    }
}
