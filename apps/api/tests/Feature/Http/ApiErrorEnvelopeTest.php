<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * BUG-005 / RCA B3 — bootstrap/app.php renders only TYPED exceptions. Anything
 * else fell through to Laravel's default `{"message":"Server Error"}`, while the
 * SPA interceptor dereferences `data.error.message` unconditionally
 * (apps/web/src/lib/api.ts) → a TypeError inside the interceptor, garbage error
 * text on screen and a poisoned Sentry breadcrumb.
 *
 * Every api/* 5xx must carry the same `{error: {code, message, request_id}}`
 * envelope as the typed handlers.
 */
final class ApiErrorEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']
            ->middleware('api')
            ->get('/api/v1/__unknown-exception-probe', static function (): never {
                throw new \LogicException('an untyped failure nobody wrote a handler for');
            });
    }

    public function test_unknown_exception_on_an_api_route_returns_the_json_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/__unknown-exception-probe');

        $response->assertStatus(500);
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id'],
        ]);
        $response->assertJsonPath('error.code', 'INTERNAL_ERROR');

        /** @var array{error: array{message: string, request_id: string|null}} $payload */
        $payload = $response->json();

        self::assertNotSame('', $payload['error']['message'], 'The envelope must carry a non-empty message');
    }

    public function test_internal_error_message_does_not_leak_exception_internals_in_production(): void
    {
        config()->set('app.debug', false);

        $response = $this->getJson('/api/v1/__unknown-exception-probe');

        $response->assertStatus(500);
        self::assertStringNotContainsString(
            'an untyped failure nobody wrote a handler for',
            (string) $response->json('error.message'),
            'With debug off the raw exception message must not reach the client'
        );
    }

    public function test_debug_mode_surfaces_the_underlying_message_for_diagnosis(): void
    {
        config()->set('app.debug', true);

        $response = $this->getJson('/api/v1/__unknown-exception-probe');

        $response->assertStatus(500);
        self::assertStringContainsString(
            'an untyped failure nobody wrote a handler for',
            (string) $response->json('error.message'),
        );
    }
}
