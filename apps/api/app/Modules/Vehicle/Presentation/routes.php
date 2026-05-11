<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Vehicle\Presentation\Controllers\PartnerVehiclesController;
use App\Modules\Vehicle\Presentation\Controllers\VehicleController;
use App\Modules\Vehicle\Presentation\Controllers\VehicleMileageController;
use App\Modules\Vehicle\Presentation\Controllers\VehicleOwnershipController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vehicle Module API Routes
|--------------------------------------------------------------------------
|
| Vehicle management routes for tracking customer vehicles.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Vehicle'])->group(function (): void {
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

    // Ownership history (per-vehicle)
    Route::get('/vehicles/{vehicle}/ownerships', [VehicleOwnershipController::class, 'index'])
        ->middleware('can:vehicles.view')
        ->name('vehicles.ownerships.index');

    Route::post('/vehicles/{vehicle}/ownerships', [VehicleOwnershipController::class, 'store'])
        ->middleware('can:vehicles.manage_ownership')
        ->name('vehicles.ownerships.store');

    // Mileage readings (per-vehicle)
    Route::get('/vehicles/{vehicle}/mileage', [VehicleMileageController::class, 'index'])
        ->middleware('can:vehicles.view')
        ->name('vehicles.mileage.index');

    Route::post('/vehicles/{vehicle}/mileage', [VehicleMileageController::class, 'store'])
        ->middleware('can:vehicles.log_mileage')
        ->name('vehicles.mileage.store');

    // Vehicles currently owned by a given partner
    Route::get('/partners/{partner}/vehicles', [PartnerVehiclesController::class, 'index'])
        ->middleware('can:vehicles.view')
        ->name('partners.vehicles.index');
});
