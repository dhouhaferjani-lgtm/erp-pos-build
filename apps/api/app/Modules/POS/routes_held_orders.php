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
 *
 * AUTHORISATION (LEDGER C-16(iii)). This group used to carry NO per-verb
 * permission at all, so every one of these routes — including the audited,
 * actor-attributed discard — was authorised to ANY authenticated member of the
 * company. The three permissions have been seeded since the feature shipped
 * (`RolesAndPermissionsSeeder.php:396-398`) and are granted to admin, manager
 * and cashier; the seeded `technician` and `accountant` roles hold none of them
 * and could still delete a cashier's parked cart.
 *
 * House mechanism is Laravel's `can:` (≈600 route usages) — Spatie's
 * `permission:` alias is NOT registered in bootstrap/app.php, so a
 * `permission:` middleware string would resolve to a missing class.
 *
 * Verb mapping:
 *   store   -> pos_held_orders.create  (park a cart)
 *   index   -> pos_held_orders.view
 *   show    -> pos_held_orders.view
 *   recall  -> pos_held_orders.create  (resuming a parked cart is the write side
 *                                       of the same hold lifecycle as parking it;
 *                                       it flips status held -> recalled)
 *   destroy -> pos_held_orders.delete
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    Route::post('/pos/held-orders', [HeldOrderController::class, 'store'])
        ->middleware('can:pos_held_orders.create');
    Route::get('/pos/held-orders', [HeldOrderController::class, 'index'])
        ->middleware('can:pos_held_orders.view');
    Route::get('/pos/held-orders/{id}', [HeldOrderController::class, 'show'])
        ->middleware('can:pos_held_orders.view');
    Route::post('/pos/held-orders/{id}/recall', [HeldOrderController::class, 'recall'])
        ->middleware('can:pos_held_orders.create');
    Route::delete('/pos/held-orders/{id}', [HeldOrderController::class, 'destroy'])
        ->middleware('can:pos_held_orders.delete');
});
