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

Route::prefix('api/v1/webhooks/channels')
    ->middleware(['api'])
    ->group(function (): void {
        Route::post('{channelId}', ChannelWebhookController::class)
            ->name('channels.webhooks.ingest');
    });

Route::prefix('api/v1/channels')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
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
