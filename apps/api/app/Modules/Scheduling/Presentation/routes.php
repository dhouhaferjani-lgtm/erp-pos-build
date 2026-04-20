<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Scheduling\Presentation\Controllers\AppointmentController;
use App\Modules\Scheduling\Presentation\Controllers\AppointmentConversionController;
use App\Modules\Scheduling\Presentation\Controllers\AppointmentTransitionController;
use App\Modules\Scheduling\Presentation\Controllers\BayController;
use App\Modules\Scheduling\Presentation\Controllers\CalendarController;
use App\Modules\Scheduling\Presentation\Controllers\ScheduleConfigController;
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
|      Middleware: ['api', 'auth:sanctum', SetPermissionsTeam].
|      Permissions gated per-endpoint via FormRequest::authorize() +
|      can() checks: scheduling.bays.view/manage,
|      scheduling.appointments.view/create/update/cancel/convert.
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

Route::prefix('api/v1/scheduling')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
    ->group(function (): void {
        // Bays — CRUD.
        Route::get('bays', [BayController::class, 'index']);
        Route::post('bays', [BayController::class, 'store']);
        Route::get('bays/{id}', [BayController::class, 'show']);
        Route::patch('bays/{id}', [BayController::class, 'update']);
        Route::delete('bays/{id}', [BayController::class, 'destroy']);

        // Schedule config (per-location).
        Route::get('config/{locationId}', [ScheduleConfigController::class, 'show']);
        Route::patch('config/{locationId}', [ScheduleConfigController::class, 'update']);

        // Appointments — CRUD.
        Route::get('appointments', [AppointmentController::class, 'index']);
        Route::post('appointments', [AppointmentController::class, 'store']);
        Route::get('appointments/{id}', [AppointmentController::class, 'show']);
        Route::patch('appointments/{id}', [AppointmentController::class, 'update']);
        Route::delete('appointments/{id}', [AppointmentController::class, 'destroy']);

        // Appointment transitions.
        Route::post('appointments/{id}/confirm', [AppointmentTransitionController::class, 'confirm']);
        Route::post('appointments/{id}/reschedule', [AppointmentTransitionController::class, 'reschedule']);
        Route::post('appointments/{id}/check-in', [AppointmentTransitionController::class, 'checkIn']);
        Route::post('appointments/{id}/cancel', [AppointmentTransitionController::class, 'cancel']);

        // Appointment conversion to WorkOrder.
        Route::post('appointments/{id}/convert', [AppointmentConversionController::class, 'convert']);

        // Calendar read endpoints.
        Route::get('calendar/day', [CalendarController::class, 'day']);
        Route::get('calendar/week', [CalendarController::class, 'week']);
        Route::get('calendar/month', [CalendarController::class, 'month']);
        Route::get('calendar/free-slots', [CalendarController::class, 'freeSlots']);
    });
