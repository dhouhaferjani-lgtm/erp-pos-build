<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\KitchenDisplayController;
use Illuminate\Support\Facades\Route;

/**
 * POS Kitchen Display System Routes
 *
 * Separate route file for the KDS feature.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // KDS endpoints
    Route::get('/pos/kitchen/orders', [KitchenDisplayController::class, 'index']);
    Route::patch('/pos/kitchen/orders/{orderId}/lines/{lineId}/status', [KitchenDisplayController::class, 'updateLineStatus']);
    Route::post('/pos/kitchen/orders/{orderId}/bump', [KitchenDisplayController::class, 'bump']);
    Route::post('/pos/orders/{orderId}/served', [KitchenDisplayController::class, 'served']);
});
