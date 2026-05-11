<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\HeldOrderController;
use Illuminate\Support\Facades\Route;

/**
 * POS Held Orders Routes
 *
 * Routes for cart parking (hold/recall/discard) functionality.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    Route::post('/pos/held-orders', [HeldOrderController::class, 'store']);
    Route::get('/pos/held-orders', [HeldOrderController::class, 'index']);
    Route::get('/pos/held-orders/{id}', [HeldOrderController::class, 'show']);
    Route::post('/pos/held-orders/{id}/recall', [HeldOrderController::class, 'recall']);
    Route::delete('/pos/held-orders/{id}', [HeldOrderController::class, 'destroy']);
});
