<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTPS and client-IP detection behind the host's proxy (go-live checklist
 * section 3, D-1): forwarded headers count only when they come from a proxy
 * named in crm.trusted_proxies, and the setting is read per request.
 */
final class TrustedProxiesTest extends TestCase
{
    private const PROBE = '/__trusted-proxies-probe';

    /**
     * Requests go to an absolute plain-HTTP URL: a relative one is resolved
     * through the URL generator, which keeps the scheme of the previous
     * request, so an https response would make the next request https by
     * itself and hide what the proxy headers did.
     */
    private const ORIGIN = 'http://crm.test';

    private const PROXY = '10.0.0.5';

    private const CLIENT = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get(self::PROBE, static fn (Request $request): JsonResponse => new JsonResponse([
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
        ]));
    }

    protected function tearDown(): void
    {
        // Symfony keeps the trusted proxies in static state; leave none behind for the next test.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        parent::tearDown();
    }

    #[Test]
    public function the_framework_trust_proxies_middleware_is_replaced_by_the_configurable_one(): void
    {
        $global = $this->app->make(Kernel::class)->getGlobalMiddleware();

        $this->assertContains(TrustProxies::class, $global);
        $this->assertNotContains(FrameworkTrustProxies::class, $global);
    }

    #[Test]
    public function without_the_setting_forwarded_headers_are_ignored_from_any_address(): void
    {
        config()->set('crm.trusted_proxies', null);

        $this->probeFrom(self::PROXY)->assertExactJson(['secure' => false, 'ip' => self::PROXY]);
        $this->probeFrom('192.168.1.20')->assertExactJson(['secure' => false, 'ip' => '192.168.1.20']);
    }

    #[Test]
    public function an_empty_setting_trusts_nobody(): void
    {
        config()->set('crm.trusted_proxies', '');

        $this->probeFrom(self::PROXY)->assertExactJson(['secure' => false, 'ip' => self::PROXY]);
    }

    #[Test]
    public function a_wildcard_honours_the_forwarded_protocol_and_client_from_any_peer(): void
    {
        config()->set('crm.trusted_proxies', '*');

        $this->probeFrom(self::PROXY)->assertExactJson(['secure' => true, 'ip' => self::CLIENT]);
        $this->probeFrom('198.51.100.77')->assertExactJson(['secure' => true, 'ip' => self::CLIENT]);
    }

    #[Test]
    public function a_list_trusts_only_the_named_proxies_and_ranges(): void
    {
        config()->set('crm.trusted_proxies', ' 10.0.0.5 , 192.168.0.0/16 ');

        $this->probeFrom(self::PROXY)->assertExactJson(['secure' => true, 'ip' => self::CLIENT]);
        $this->probeFrom('192.168.1.20')->assertExactJson(['secure' => true, 'ip' => self::CLIENT]);
        $this->probeFrom('10.0.0.6')->assertExactJson(['secure' => false, 'ip' => '10.0.0.6']);
    }

    #[Test]
    public function the_setting_is_read_on_every_request_rather_than_at_boot(): void
    {
        config()->set('crm.trusted_proxies', '*');
        $this->probeFrom(self::PROXY)->assertJsonPath('secure', true);

        config()->set('crm.trusted_proxies', null);
        $this->probeFrom(self::PROXY)->assertExactJson(['secure' => false, 'ip' => self::PROXY]);
    }

    #[Test]
    public function the_panel_login_page_sees_https_only_through_a_trusted_proxy(): void
    {
        config()->set('crm.trusted_proxies', null);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->get(self::ORIGIN.'/admin/login', $this->forwardedHeaders())
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        config()->set('crm.trusted_proxies', self::PROXY);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->get(self::ORIGIN.'/admin/login', $this->forwardedHeaders())
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->get(self::ORIGIN.'/admin/login', $this->forwardedHeaders())
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[Test]
    public function the_setting_is_parsed_into_nobody_a_wildcard_or_a_list(): void
    {
        $this->assertNull(TrustProxies::parse(null));
        $this->assertNull(TrustProxies::parse(''));
        $this->assertNull(TrustProxies::parse('  , '));
        $this->assertNull(TrustProxies::parse('null'));
        $this->assertSame('*', TrustProxies::parse(' * '));
        $this->assertSame(['10.0.0.5', '192.168.0.0/16'], TrustProxies::parse('10.0.0.5,, 192.168.0.0/16'));
        $this->assertSame(['10.0.0.5'], TrustProxies::parse(['10.0.0.5']));
    }

    private function probeFrom(string $remoteAddress): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->get(self::ORIGIN.self::PROBE, $this->forwardedHeaders())
            ->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function forwardedHeaders(): array
    {
        return [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => self::CLIENT,
            'X-Forwarded-Port' => '443',
        ];
    }
}
