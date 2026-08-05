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
// Three guards, all required (2026-08-05 adversarial review, B1):
//   1. `whereUuid('channelId')` — `channel_webhook_directory.channel_id` is a
//      PostgreSQL `uuid` column, so a non-UUID segment reaching the lookup
//      raises SQLSTATE 22P02. Nothing renders `QueryException`, so that was a
//      500 any anonymous caller could drive in a loop. The constraint means a
//      malformed id never enters the middleware stack at all; the controller
//      and the registrar repeat the check with `Str::isUuid()` so the guard
//      does not depend on this one line staying here.
//   2. `throttle:channel-webhook` — 60/min per IP (AppServiceProvider). Without
//      it this route is an unbounded anonymous channel into the central
//      directory, and for a real channel id into a tenant database switch.
//   3. Fail-closed 404 in the controller, identical for every unresolvable id.
Route::prefix('api/v1/webhooks/channels')
    ->middleware(['api', 'throttle:channel-webhook'])
    ->group(function (): void {
        Route::post('{channelId}', ChannelWebhookController::class)
            ->whereUuid('channelId')
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
