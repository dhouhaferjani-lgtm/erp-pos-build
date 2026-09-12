<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the liveness fixture's throw-away routes.
 *
 * It is registered by RoutePermissionCoverageRatchetLivenessTest's
 * createApplication() override, BEFORE `$app->make(Kernel::class)->bootstrap()`,
 * so its boot() runs while providers are booting and the routes exist by the
 * time anything consults the router. It is referenced from NOWHERE else — not
 * bootstrap/providers.php, not config — so no application boot outside that one
 * test class can see these routes.
 *
 * Rev 1 registered the routes inside the test METHOD, on an already-booted
 * router. That works for `Route::getRoutes()` but proves nothing about a route
 * that exists at boot time, and it is not the shape the spec accepted (gate r1
 * B-2 (1)).
 */
final class LivenessRouteServiceProvider extends ServiceProvider
{
    /** The prefix of the fixture's IN-UNIVERSE records; the one out-of-universe record deliberately does not carry it. */
    public const URI_PREFIX = 'api/v1/__liveness__/';

    public function boot(): void
    {
        foreach (LivenessRouteFixture::records() as $record) {
            $route = match ($record['method']) {
                'GET' => Route::get($record['uri'], static fn (): string => 'ok'),
                'POST' => Route::post($record['uri'], static fn (): string => 'ok'),
                'PUT' => Route::put($record['uri'], static fn (): string => 'ok'),
                'PATCH' => Route::patch($record['uri'], static fn (): string => 'ok'),
                'DELETE' => Route::delete($record['uri'], static fn (): string => 'ok'),
                default => throw new \RuntimeException('Unsupported liveness method: '.$record['method']),
            };

            $route->middleware($record['middleware']);
        }
    }
}
