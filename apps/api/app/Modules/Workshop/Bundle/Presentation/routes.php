<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Workshop\Bundle\Presentation\Controllers\BundleApplicabilityController;
use App\Modules\Workshop\Bundle\Presentation\Controllers\BundleApplicableController;
use App\Modules\Workshop\Bundle\Presentation\Controllers\BundleComponentController;
use App\Modules\Workshop\Bundle\Presentation\Controllers\BundleController;
use App\Modules\Workshop\Bundle\Presentation\Controllers\BundleExpansionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/workshop')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Workshop'])->group(function (): void {
    // Picker endpoint must come before the {id} route so `applicable` is
    // not mistaken for a bundle UUID.
    Route::get('bundles/applicable', [BundleApplicableController::class, 'index']);

    Route::get('bundles', [BundleController::class, 'index']);
    Route::post('bundles', [BundleController::class, 'store']);
    Route::get('bundles/{id}', [BundleController::class, 'show']);
    Route::patch('bundles/{id}', [BundleController::class, 'update']);
    Route::delete('bundles/{id}', [BundleController::class, 'destroy']);

    Route::get('bundles/{id}/expansion', [BundleExpansionController::class, 'show']);

    Route::post('bundles/{id}/components', [BundleComponentController::class, 'store']);
    Route::patch('bundles/{id}/components/{componentId}', [BundleComponentController::class, 'update']);
    Route::delete('bundles/{id}/components/{componentId}', [BundleComponentController::class, 'destroy']);

    Route::put('bundles/{id}/vehicle-applicabilities', [BundleApplicabilityController::class, 'replace']);
});
