<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Vehicle\Presentation\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vehicle Module API Routes
|--------------------------------------------------------------------------
|
| Vehicle management routes for tracking customer vehicles.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Vehicle'])->group(function (): void {
    Route::get('/vehicles', [VehicleController::class, 'index'])
        ->middleware('can:vehicles.view')
        ->name('vehicles.index');

    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])
        ->middleware('can:vehicles.view')
        ->name('vehicles.show');

    Route::post('/vehicles', [VehicleController::class, 'store'])
        ->middleware('can:vehicles.create')
        ->name('vehicles.store');

    Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])
        ->middleware('can:vehicles.update')
        ->name('vehicles.update');

    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])
        ->middleware('can:vehicles.delete')
        ->name('vehicles.destroy');
});
