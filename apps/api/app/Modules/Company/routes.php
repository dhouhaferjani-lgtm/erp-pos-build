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

    // The MANUAL close — Session B2 lane C-24 (i), the other half of the pair above.
    //
    // Q-10 made the nightly auto-lock skip a reopened period AND hold the close of its
    // fiscal year, so that without a human `Open -> Closed` edge a correction kept the
    // period — and the whole year — open forever. This route is that edge; the scheduler
    // needed no change at all, because `lockPeriodsInClosedFiscalYears()` already treats
    // "reopened but Closed again by a human" as lockable.
    //
    // Its own permission, `fiscal-periods.close`, rather than reusing
    // `fiscal-periods.reopen`: they are seeded to the same roles today (accountant +
    // admin), but they are different acts — one reverses a settlement, the other makes
    // one — and a tenant-custom role must be able to grant the settling half without the
    // reversing half. `whereUuid` for the same reason as the reopen route: a malformed
    // `{id}` would otherwise reach `findOrFail()` and raise PostgreSQL 22P02 as a 500.
    Route::post('fiscal-periods/{id}/close', [FiscalPeriodController::class, 'close'])
        ->whereUuid('id')
        ->middleware('can:fiscal-periods.close')
        ->name('fiscal-periods.close');

    Route::get('company/locations', [LocationController::class, 'scopedIndex'])
        ->name('company.locations.scoped');

    Route::get('company/locations/transaction-destinations', [LocationController::class, 'transactionIndex'])
        ->middleware('require.any.permission:inventory.transfers.create,inventory.transfer,purchase-orders.receive,repositories.manage,document-ingestions.create')
        ->name('company.locations.transaction-destinations');

    Route::get('company/locations/all', [LocationController::class, 'managementIndex'])
        ->middleware('can:users.manage_location_access')
        ->name('company.locations.all');
});
