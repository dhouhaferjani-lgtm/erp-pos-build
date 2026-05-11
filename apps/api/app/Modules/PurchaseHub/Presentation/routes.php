<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\PurchaseHub\Presentation\Controllers\PurchaseHubOfferController;
use App\Modules\PurchaseHub\Presentation\Controllers\PurchaseHubOrderController;
use App\Modules\PurchaseHub\Presentation\Controllers\PurchaseHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/purchase-hub')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(function () {
        Route::get('offers', [PurchaseHubOfferController::class, 'index']);
        Route::get('offers/{id}', [PurchaseHubOfferController::class, 'show']);

        Route::post('orders', [PurchaseHubOrderController::class, 'store']);
        Route::get('orders', [PurchaseHubOrderController::class, 'index']);
        Route::get('orders/{id}', [PurchaseHubOrderController::class, 'show']);
    });

Route::prefix('api/webhooks')
    ->middleware(['api'])
    ->group(function () {
        Route::post('purchase-hub', PurchaseHubWebhookController::class);
    });
