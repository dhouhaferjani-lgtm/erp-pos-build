<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Uom\Presentation\Controllers\UomController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Categories
    Route::get('uom/categories', [UomController::class, 'indexCategories']);

    // Units. storeUnit/updateUnit/destroyUnit already call
    // authorizeAbility('uom.create'|'uom.edit'|'uom.delete') in the controller
    // (UomController.php:170,207,261); the route gate moves the check to the
    // layer a mechanical sweep can see (spec 4.4.1 E-2).
    Route::get('uom/units', [UomController::class, 'indexUnits']);
    Route::get('uom/units/{id}', [UomController::class, 'showUnit']);
    Route::post('uom/units', [UomController::class, 'storeUnit'])
        ->middleware('can:uom.create');
    Route::put('uom/units/{id}', [UomController::class, 'updateUnit'])
        ->middleware('can:uom.edit');
    Route::delete('uom/units/{id}', [UomController::class, 'destroyUnit'])
        ->middleware('can:uom.delete');

    // Explicit, audited company mapping for legacy/import unit text.
    // applyUnitTextMapping already checks units.manage (UomController.php:52).
    Route::get('uom/unit-text-mappings/unmapped', [UomController::class, 'unmappedUnitTexts']);
    Route::post('uom/unit-text-mappings', [UomController::class, 'applyUnitTextMapping'])
        ->middleware('can:units.manage');

    // Conversion — a pure computation over the tenant's own unit catalogue,
    // checked NOWHERE today (UomController.php:302). uom.view is the
    // catalogue-read key and is held by manager, cashier, viewer, technician and
    // operator, so gating it costs no role the calculator.
    Route::post('uom/convert', [UomController::class, 'convert'])
        ->middleware('can:uom.view');
});
