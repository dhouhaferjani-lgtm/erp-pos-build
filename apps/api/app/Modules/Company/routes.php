<?php

declare(strict_types=1);

use App\Modules\Company\Presentation\Controllers\CompanyController;
use App\Modules\Company\Presentation\Controllers\FiscalPeriodController;
use App\Modules\Company\Presentation\Controllers\LocationController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
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
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Company creation - available to any authenticated user within their tenant
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');

    // Company retrieval and update
    Route::get('companies/{companyId}', [CompanyController::class, 'show'])->name('companies.show');
    Route::put('companies/{companyId}', [CompanyController::class, 'update'])
        ->middleware('can:settings.update')
        ->name('companies.update');

    // Reservation settings management
    Route::get('companies/{companyId}/reservation-settings', [CompanyController::class, 'getReservationSettings'])
        ->name('companies.reservation-settings.show');

    Route::put('companies/{companyId}/reservation-settings', [CompanyController::class, 'updateReservationSettings'])
        ->middleware('can:settings.update')
        ->name('companies.reservation-settings.update');

    // POS settings
    Route::get('companies/{companyId}/pos-settings', [CompanyController::class, 'getPOSSettings'])
        ->name('companies.pos-settings.show');

    // Receipt customization settings
    Route::put('companies/{companyId}/receipt-settings', [CompanyController::class, 'updateReceiptSettings'])
        ->middleware('can:settings.update')
        ->name('companies.receipt-settings.update');

    // Fiscal-period lifecycle — Session B lane Q-10 (c).
    //
    // The nightly auto-lock (FiscalPeriodAutoLockService) drives periods
    // Open -> Closed -> Locked and, before this lane, nothing in the product
    // drove them back: `PeriodStatus::Open` was written at fiscal-year creation
    // and nowhere else, so an opening-period correction one month after go-live
    // required a manual `UPDATE fiscal_periods`.
    //
    // Gated on the dedicated `fiscal-periods.reopen`, seeded in
    // RolesAndPermissionsSeeder and held by `admin` (via Permission::all()) and
    // `accountant`. Naming mirrors the sibling `bank-statements.reopen`; the role
    // choice mirrors `reports.manage` (the VAT-period generate/close/reopen/file
    // family), which the 2026-08-06 gate finding I-1 ruling deliberately kept on
    // accountant and removed from manager because it is financial-lifecycle
    // mutation. Locked periods are NOT reopenable — the service refuses.
    // `whereUuid` (gate r1, M-2): without it a malformed `{id}` reaches
    // `findOrFail()` and PostgreSQL raises 22P01/22P02 `invalid input syntax for
    // type uuid` — a 500, because no QueryException renderable is registered. The
    // constraint turns it into the 404 it always was. Same shape as
    // app/Modules/Partner/routes.php:29.
    Route::post('fiscal-periods/{id}/reopen', [FiscalPeriodController::class, 'reopen'])
        ->whereUuid('id')
        ->middleware('can:fiscal-periods.reopen')
        ->name('fiscal-periods.reopen');

    Route::get('company/locations', [LocationController::class, 'scopedIndex'])
        ->name('company.locations.scoped');

    Route::get('company/locations/transaction-destinations', [LocationController::class, 'transactionIndex'])
        ->middleware('require.any.permission:inventory.transfers.create,inventory.transfer,purchase-orders.receive,repositories.manage,document-ingestions.create')
        ->name('company.locations.transaction-destinations');

    Route::get('company/locations/all', [LocationController::class, 'managementIndex'])
        ->middleware('can:users.manage_location_access')
        ->name('company.locations.all');
});
