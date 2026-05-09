<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Uom\Presentation\Controllers\UomController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Categories
    Route::get('uom/categories', [UomController::class, 'indexCategories']);

    // Units
    Route::get('uom/units', [UomController::class, 'indexUnits']);
    Route::get('uom/units/{id}', [UomController::class, 'showUnit']);
    Route::post('uom/units', [UomController::class, 'storeUnit']);
    Route::put('uom/units/{id}', [UomController::class, 'updateUnit']);
    Route::delete('uom/units/{id}', [UomController::class, 'destroyUnit']);

    // Conversion
    Route::post('uom/convert', [UomController::class, 'convert']);
});
