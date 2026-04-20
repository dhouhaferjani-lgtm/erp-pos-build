<?php

declare(strict_types=1);

use App\Modules\Scheduling\Presentation\Controllers\StorefrontAvailabilityController;
use App\Modules\Scheduling\Presentation\Controllers\StorefrontBookingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scheduling Module API Routes
|--------------------------------------------------------------------------
|
| Two distinct route groups:
|
|   1. PUBLIC STOREFRONT — /api/v1/storefront/{company_id}/...
|      Middleware: ['api'] + per-route throttle + VerifyCaptcha (for POST).
|      NO `auth:sanctum`; the `{company_id}` path parameter is the
|      `companies.uuid` primary key.
|
|   2. INTERNAL STAFF — /api/v1/scheduling/...
|      Middleware: ['api', 'auth:sanctum', SetPermissionsTeam]. Staff
|      routes are registered by Task 16 in the same file.
|
*/

Route::prefix('api/v1/storefront/{company_id}')
    ->middleware(['api'])
    ->group(function (): void {
        Route::get('availability', [StorefrontAvailabilityController::class, 'index'])
            ->middleware('throttle:storefront-booking-ip');

        Route::post('appointments', [StorefrontBookingController::class, 'store'])
            ->middleware([
                'throttle:storefront-booking-ip',
                'throttle:storefront-booking-company-phone',
                'scheduling.captcha',
            ]);
    });
