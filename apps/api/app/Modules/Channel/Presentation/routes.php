<?php

declare(strict_types=1);

use App\Modules\Channel\Presentation\Controllers\ChannelController;
use App\Modules\Channel\Presentation\Controllers\ChannelOrderController;
use App\Modules\Channel\Presentation\Controllers\ChannelProductController;
use App\Modules\Channel\Presentation\Controllers\ChannelSyncOperationController;
use App\Modules\Channel\Presentation\Controllers\ChannelWebhookController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

// Unauthenticated webhook ingress — external sales platforms call it directly.
//
// Two guards, both required (2026-08-05 adversarial review, B1):
//   1. `Str::isUuid()` in BOTH ChannelWebhookController and
//      ChannelWebhookDirectoryRegistrar::resolveTenantId() —
//      `channel_webhook_directory.channel_id` is a PostgreSQL `uuid` column, so
//      a non-UUID segment reaching the lookup raises SQLSTATE 22P02. Nothing
//      renders `QueryException`, so that was a 500 any anonymous caller could
//      drive in a loop.
//   2. `throttle:channel-webhook` — 60/min per IP (AppServiceProvider). Without
//      it this route is an unbounded anonymous channel into the central
//      directory, and for a real channel id into a tenant database switch.
//
// **`->whereUuid('channelId')` was REMOVED (N-3, 2026-08-05 re-gate)** — it was
// B1's third layer and it cost more than it bought:
//   - a segment the ROUTER refuses never reaches route-group middleware, so a
//     malformed-id flood bypassed `throttle:channel-webhook` entirely. The
//     constraint was protecting the cheapest request on the route from the one
//     limiter that bounds it;
//   - Laravel then rendered the router's `{"message":"The route … could not be
//     found."}`, so the malformed case was the ONE fail-closed answer on this
//     endpoint that was not byte-identical to the rest.
// A companion unconstrained route is not an option: `RouteCollection` keys on
// method + domain + URI, so a second `POST {channelId}` OVERWRITES the
// constrained one instead of sitting behind it. Dropping the constraint hands
// the case to the controller guard, which was written for exactly this and had
// until now been masked by the route (N-4).
Route::prefix('api/v1/webhooks/channels')
    ->middleware(['api', 'throttle:channel-webhook'])
    ->group(function (): void {
        Route::post('{channelId}', ChannelWebhookController::class)
            ->name('channels.webhooks.ingest');
    });

// Tenant-facing channel management — gated on the Ecommerce extra. The
// webhook ingress above is intentionally NOT module-gated: external
// platforms call it and authenticate via the channel signature strategy.
Route::prefix('api/v1/channels')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Ecommerce'])
    ->group(function (): void {
        Route::get('/', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('/', [ChannelController::class, 'store'])->name('channels.store');
        // MUST stay registered before any '{id}/...' route so the literal
        // 'orders' segment is never captured as a channel {id}.
        Route::get('orders', [ChannelOrderController::class, 'indexAll'])->name('channels.orders.all');
        Route::post('{id}/test-connection', [ChannelController::class, 'testConnection'])->name('channels.test-connection');
        Route::post('{id}/resync', [ChannelController::class, 'resync'])->name('channels.resync');
        Route::get('{id}/sync-operations', [ChannelSyncOperationController::class, 'index'])->name('channels.sync-operations.index');
        Route::get('{id}/orders', [ChannelOrderController::class, 'index'])->name('channels.orders.index');
        Route::post('{channelId}/orders/{orderId}/promote', [ChannelOrderController::class, 'promote'])->name('channels.orders.promote');
        Route::post('{channelId}/products/{productId}/publish', [ChannelProductController::class, 'publish'])->name('channels.products.publish');
        Route::post('{channelId}/products/{productId}/unpublish', [ChannelProductController::class, 'unpublish'])->name('channels.products.unpublish');
    });
