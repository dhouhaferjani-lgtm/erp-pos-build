<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/**
 * POS Order Management Routes
 *
 * Separate route file to avoid merge conflicts with the main POS routes.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Order CRUD
    Route::post('/pos/orders', [OrderController::class, 'store']);
    Route::get('/pos/orders', [OrderController::class, 'index']);
    Route::get('/pos/orders/{id}', [OrderController::class, 'show']);

    // Order Line Management
    Route::post('/pos/orders/{id}/lines', [OrderController::class, 'addLine']);
    Route::patch('/pos/orders/{id}/lines/{lineId}', [OrderController::class, 'modifyLine']);
    Route::delete('/pos/orders/{id}/lines/{lineId}', [OrderController::class, 'removeLine']);

    // Order Workflow
    Route::post('/pos/orders/{id}/send-to-kitchen', [OrderController::class, 'sendToKitchen']);
    Route::post('/pos/orders/{id}/close', [OrderController::class, 'close']);
    Route::post('/pos/orders/{id}/cancel', [OrderController::class, 'cancel']);
});
