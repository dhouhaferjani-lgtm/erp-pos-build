<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * BUG-005 / RCA A1 (aggravating factor) — the API sits behind an nginx/Traefik
 * TLS terminator that forwards plain HTTP upstream. Without a trusted-proxy
 * configuration Laravel reads the upstream scheme, so every absolute URL it
 * mints (`route()`, `url()`, `asset()`) comes out as `http://…` — which a
 * browser on an `https://` page blocks as mixed content, and which makes any
 * signed absolute URL sign the wrong scheme.
 *
 * `->trustProxies(at: '*')` makes Laravel honour X-Forwarded-Proto/-Host.
 * `at: '*'` is safe here because the only route to the container is through
 * the proxy — nothing else can reach it to spoof the headers.
 */
final class TrustedProxyTest extends TestCase
{
    public function test_forwarded_proto_https_makes_the_request_secure(): void
    {
        $captured = null;

        $this->app['router']->get('/__trusted-proxy-probe', function (Request $request) use (&$captured): array {
            $captured = [
                'secure' => $request->isSecure(),
                'scheme' => $request->getScheme(),
                'root' => $request->getSchemeAndHttpHost(),
            ];

            return $captured;
        });

        $this->get('/__trusted-proxy-probe', [
            'X-Forwarded-Proto' => 'https',
        ])->assertOk();

        self::assertIsArray($captured);
        self::assertTrue($captured['secure'], 'X-Forwarded-Proto: https must mark the request secure behind a trusted proxy');
        self::assertSame('https', $captured['scheme']);
    }

    /**
     * Host poisoning guard (authz gate, 2026-08-06).
     *
     * `trustProxies()` with no `$headers` argument keeps Symfony's default set,
     * which includes X-Forwarded-HOST and -PREFIX. Combined with `at: '*'` that
     * makes the request host attacker-controlled for anything that can reach the
     * container — and Dokploy co-locates containers on a shared host, so the
     * "only the proxy can reach it" premise is not verifiable from this repo.
     *
     * A1 only ever needed the SCHEME. The header set is therefore narrowed to
     * FOR | PROTO | PORT: `at: '*'` stays (Dokploy's bridge subnets are
     * ephemeral, so a CIDR would rot), but X-Forwarded-Host must be ignored.
     */
    public function test_forwarded_host_is_not_trusted(): void
    {
        $captured = null;

        $this->app['router']->get('/__trusted-proxy-host-probe', function (Request $request) use (&$captured): array {
            $captured = ['root' => $request->getSchemeAndHttpHost()];

            return $captured;
        });

        $this->get('/__trusted-proxy-host-probe', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example.com',
        ])->assertOk();

        self::assertIsArray($captured);
        self::assertStringNotContainsString(
            'attacker.example.com',
            $captured['root'],
            'X-Forwarded-Host must NOT be trusted — it would poison every url()/route()/asset() and any absolute signed URL'
        );
    }
}
