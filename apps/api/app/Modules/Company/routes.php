<?php

declare(strict_types=1);

use App\Modules\Company\Presentation\Controllers\CompanyController;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Company Module API Routes
|--------------------------------------------------------------------------
|
| Company management routes.
| Note: Company creation does NOT require company context (X-Company-Id header)
| because the user is creating a new company and doesn't have one yet.
|
*/

// Routes that do NOT require company context
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Company creation - available to any authenticated user within their tenant
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');

    // Company retrieval and update
    Route::get('companies/{companyId}', [CompanyController::class, 'show'])->name('companies.show');
    Route::put('companies/{companyId}', [CompanyController::class, 'update'])->name('companies.update');

    // Reservation settings management
    Route::get('companies/{companyId}/reservation-settings', [CompanyController::class, 'getReservationSettings'])
        ->name('companies.reservation-settings.show');

    Route::put('companies/{companyId}/reservation-settings', [CompanyController::class, 'updateReservationSettings'])
        ->name('companies.reservation-settings.update');

    // POS settings (read-only for now)
    Route::get('companies/{companyId}/pos-settings', [CompanyController::class, 'getPOSSettings'])
        ->name('companies.pos-settings.show');
});
