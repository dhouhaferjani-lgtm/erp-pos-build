<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
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

    /**
     * The catch-all must not swallow HttpExceptionInterface: an unknown api/*
     * route has to keep its own 404, not become a 500 INTERNAL_ERROR.
     */
    public function test_unknown_api_route_still_returns_404_not_the_internal_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/__no-such-route-anywhere');

        $response->assertStatus(404);
        self::assertNotSame(
            'INTERNAL_ERROR',
            $response->json('error.code'),
            'A missing route must not be rewritten into a 500 by the catch-all'
        );
    }

    /**
     * HttpResponseException is a plain RuntimeException — it does NOT implement
     * HttpExceptionInterface — and Laravel matches render callbacks BEFORE the
     * Handler's own match on it. Thrown outside a route action (e.g. from
     * middleware) it therefore reaches the catch-all and would become a 500,
     * discarding the response the thrower explicitly built.
     */
    public function test_http_response_exception_keeps_its_own_status_and_body(): void
    {
        $this->app['router']
            ->middleware('api')
            ->get('/api/v1/__http-response-exception-probe', static function (): never {
                throw new HttpResponseException(
                    response()->json(['error' => ['code' => 'DELIBERATE_403', 'message' => 'nope']], 403)
                );
            });

        $response = $this->getJson('/api/v1/__http-response-exception-probe');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'DELIBERATE_403');
    }

    /**
     * The route-action path is protected by Illuminate\Routing\Route::run(),
     * so it must be probed through the exception handler directly — that is the
     * middleware-thrown path the render callback actually sees.
     */
    public function test_http_response_exception_is_not_rewritten_by_the_exception_handler(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = app(ExceptionHandler::class);

        $response = $handler->render(
            Request::create('/api/v1/anything', 'GET', server: ['HTTP_ACCEPT' => 'application/json']),
            new HttpResponseException(
                response()->json(['error' => ['code' => 'FROM_MIDDLEWARE', 'message' => 'nope']], 422)
            ),
        );

        self::assertSame(422, $response->getStatusCode(), 'The handler must return the response the thrower built');
        self::assertStringContainsString('FROM_MIDDLEWARE', (string) $response->getContent());
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
