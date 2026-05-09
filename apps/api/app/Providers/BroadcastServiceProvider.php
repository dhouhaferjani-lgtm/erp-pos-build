<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Product\Infrastructure\Listeners\BroadcastProductEventsListener;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Broadcast Service Provider
 *
 * Registers event subscribers that bridge domain events to broadcast events.
 * This maintains separation of concerns by keeping broadcasting logic in the infrastructure layer.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register broadcasting auth endpoint under API middleware
        // (default `channels:` in withRouting uses `web` middleware with CSRF,
        // but our frontend authenticates via Bearer token, not session cookies).
        //
        // SetPermissionsTeam + EnforceTokenTenantClaim mirror the protected
        // route-stack contract from master-plan §15 Invariants B + D so the
        // /broadcasting/auth endpoint is held to the same token-claim
        // defense-in-depth as every other auth:sanctum-guarded surface.
        // Channel callbacks in routes/channels.php already gate on
        // tenant_id + UserCompanyMembership via User::canAccessChannel /
        // canAccessCompanyChannel, so SetPermissionsTeam is included for
        // contract uniformity rather than functional necessity.
        Broadcast::routes([
            'middleware' => [
                'api',
                'auth:sanctum',
                SetPermissionsTeam::class,
                EnforceTokenTenantClaim::class,
            ],
        ]);

        // Load channel authorization definitions
        require base_path('routes/channels.php');

        // Register event subscribers that handle broadcasting
        Event::subscribe(BroadcastProductEventsListener::class);
    }
}
