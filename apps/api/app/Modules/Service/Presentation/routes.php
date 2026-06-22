<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Service\Presentation\Controllers\ServiceCategoryController;
use App\Modules\Service\Presentation\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Service Module API Routes
|--------------------------------------------------------------------------
|
| Service catalog management - services, categories, and pricing.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Workshop'])->group(function (): void {
    // Services
    Route::get('/services', [ServiceController::class, 'index'])
        ->name('services.index');

    Route::get('/services/{service}', [ServiceController::class, 'show'])
        ->name('services.show');

    Route::post('/services', [ServiceController::class, 'store'])
        ->name('services.store');

    Route::patch('/services/{service}', [ServiceController::class, 'update'])
        ->name('services.update');

    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])
        ->name('services.destroy');

    // Service Categories
    Route::get('/service-categories', [ServiceCategoryController::class, 'index'])
        ->name('service-categories.index');

    Route::get('/service-categories/tree', [ServiceCategoryController::class, 'tree'])
        ->name('service-categories.tree');

    Route::get('/service-categories/{category}', [ServiceCategoryController::class, 'show'])
        ->name('service-categories.show');

    Route::post('/service-categories', [ServiceCategoryController::class, 'store'])
        ->name('service-categories.store');

    Route::patch('/service-categories/{category}', [ServiceCategoryController::class, 'update'])
        ->name('service-categories.update');

    Route::delete('/service-categories/{category}', [ServiceCategoryController::class, 'destroy'])
        ->name('service-categories.destroy');
});
