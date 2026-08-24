<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\TableController;
use Illuminate\Support\Facades\Route;

/**
 * POS Table Management Routes
 *
 * Separate route file to avoid merge conflicts with the main POS routes.
 *
 * Module gate (rule 12, Session B lane Q-13; sibling of the Q-9 kitchen hole
 * recorded as Finding 2 of the Q-9 tenancy gate): table management is an F&B
 * surface. The web layer has gated it on `ModuleGuard module="Tables"`
 * (`apps/web/src/routes/index.tsx`) and `module: 'Tables'` on the sidebar entry
 * (`Sidebar.tsx`), but the backend mirrored nothing - every route below was
 * reachable by any holder of `pos.manage_tables` on ANY vertical, retail and
 * parapharmacy included. `Tables` is the right key per `config/verticals.php`:
 * it is a DEFAULT module of `restaurant`, a COMPATIBLE EXTRA of `coffee_shop`,
 * and neither for `retail`/`parapharmacy`.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Tables'])->group(function () {
    // Floor CRUD (admin)
    Route::get('/pos/floors', [TableController::class, 'indexFloors']);
    Route::post('/pos/floors', [TableController::class, 'storeFloor']);
    Route::patch('/pos/floors/{id}', [TableController::class, 'updateFloor']);
    Route::delete('/pos/floors/{id}', [TableController::class, 'destroyFloor']);

    // Table CRUD
    Route::get('/pos/tables', [TableController::class, 'indexTables']);
    Route::post('/pos/tables', [TableController::class, 'storeTable']);
    Route::patch('/pos/tables/{id}', [TableController::class, 'updateTable']);
    Route::delete('/pos/tables/{id}', [TableController::class, 'destroyTable']);

    // Table operations (POS operators)
    Route::post('/pos/tables/{id}/release', [TableController::class, 'releaseTable']);
    Route::post('/pos/tables/{id}/status', [TableController::class, 'setStatus']);
});
