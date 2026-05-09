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
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
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
