<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\KitchenDisplayController;
use Illuminate\Support\Facades\Route;

/**
 * POS Kitchen Display System Routes
 *
 * Separate route file for the KDS feature.
 *
 * Module gate (rule 12, Session B lane Q-9 / triage F1): the KDS is an F&B
 * surface. The web layer has gated it on `ModuleGuard module="Menu"`
 * (`apps/web/src/routes/index.tsx`, `Sidebar.tsx`) since the F&B-leak pass, but
 * the backend mirrored nothing — every route below was reachable by any holder
 * of `pos.operate_terminal` on ANY vertical, parapharmacy included. `Menu` (not
 * `Tables`) is the correct key: `coffee_shop` has Menu but NOT Tables
 * (`config/verticals.php`) and must keep the order/kitchen workflow.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Menu'])->group(function () {
    // KDS endpoints
    Route::get('/pos/kitchen/orders', [KitchenDisplayController::class, 'index']);
    Route::patch('/pos/kitchen/orders/{orderId}/lines/{lineId}/status', [KitchenDisplayController::class, 'updateLineStatus']);
    Route::post('/pos/kitchen/orders/{orderId}/bump', [KitchenDisplayController::class, 'bump']);
    Route::post('/pos/orders/{orderId}/served', [KitchenDisplayController::class, 'served']);
});
