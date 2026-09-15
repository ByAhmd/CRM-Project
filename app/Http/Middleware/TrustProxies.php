<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Which reverse proxies may speak for the client (go-live checklist section 3,
 * decision D-1).
 *
 * On shared hosting the TLS connection usually ends at the host's proxy, which
 * forwards plain HTTP to PHP and says what it saw in the X-Forwarded-* headers.
 * Unless those headers are trusted, `request()->isSecure()` is false behind an
 * https site, the HSTS header is never sent, signed URLs built from the request
 * fail their check and the audit ledger and the login throttle record the
 * proxy's address instead of the client's.
 *
 * The list comes from `config('crm.trusted_proxies')` (env CRM_TRUSTED_PROXIES)
 * and is read on every request, not at boot, so it keeps working once the
 * configuration is cached (`env()` returns null after `config:cache`):
 *
 * - unset / empty — trust nobody (the secure default): forwarded headers are
 *   ignored and the TCP peer is the client;
 * - `*` — trust whoever connects. Only correct when PHP is reachable solely
 *   through the host's proxy: otherwise any client can send its own
 *   X-Forwarded-For and X-Forwarded-Proto, spoofing its IP address for the
 *   login and import throttles and in every audit row, and claiming https;
 * - a comma-separated list of IPs or CIDR ranges — trust only those peers.
 *   Prefer this whenever the host publishes its proxy addresses.
 *
 * The standard X-Forwarded-For, -Host, -Port, -Proto and -Prefix headers are
 * trusted from a trusted proxy; the RFC 7239 `Forwarded` header is not.
 */
final class TrustProxies extends Middleware
{
    /**
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX;

    /**
     * The parsed `crm.trusted_proxies` value: null (trust nobody), `*`, or the
     * list of addresses.
     *
     * @return list<string>|string|null
     */
    protected function proxies(): array|string|null
    {
        return self::parse(config('crm.trusted_proxies'));
    }

    /**
     * The framework falls back to `config('trustedproxy.proxies')` and trusts
     * every peer on Laravel Cloud, Forge and Vapor hosts when no list is given;
     * here an empty setting means exactly what it says — nobody is trusted.
     */
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $proxies = $this->proxies();

        if ($proxies === '*') {
            $this->setTrustedProxyIpAddressesToTheCallingIp($request);

            return;
        }

        if (is_array($proxies)) {
            $this->setTrustedProxyIpAddressesToSpecificIps($request, $proxies);
        }
    }

    /**
     * @return list<string>|string|null
     */
    public static function parse(mixed $value): array|string|null
    {
        if (is_array($value)) {
            $value = implode(',', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || in_array(strtolower($value), ['null', 'false', 'none'], true)) {
            return null;
        }

        if ($value === '*' || $value === '**') {
            return '*';
        }

        $proxies = array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $proxy): bool => $proxy !== '',
        ));

        return $proxies === [] ? null : $proxies;
    }
}
