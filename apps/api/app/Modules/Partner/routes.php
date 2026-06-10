<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Partner\Presentation\Controllers\PartnerController;
use App\Modules\Partner\Presentation\Controllers\PartnerDepositController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Partner Module API Routes
|--------------------------------------------------------------------------
|
| Partner management routes for customers and suppliers.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Partner CRUD with permission middleware
    Route::get('partners', [PartnerController::class, 'index'])
        ->middleware('can:partners.view')
        ->name('partners.index');

    Route::get('partners/{partner}', [PartnerController::class, 'show'])
        ->middleware('can:partners.view')
        ->name('partners.show')
        ->whereUuid('partner');

    // Back-office customer-account deposits (settle FIFO, overflow to credit).
    Route::post('partners/{partner}/deposits', [PartnerDepositController::class, 'store'])
        ->middleware('can:payments.create')
        ->name('partners.deposits.store')
        ->whereUuid('partner');

    Route::get('partners/{partner}/deposits', [PartnerDepositController::class, 'index'])
        ->middleware('can:payments.view')
        ->name('partners.deposits.index')
        ->whereUuid('partner');

    Route::post('partners', [PartnerController::class, 'store'])
        ->middleware('can:partners.create')
        ->name('partners.store');

    Route::patch('partners/{partner}', [PartnerController::class, 'update'])
        ->middleware('can:partners.update')
        ->name('partners.update')
        ->whereUuid('partner');

    Route::delete('partners/{partner}', [PartnerController::class, 'destroy'])
        ->middleware('can:partners.delete')
        ->name('partners.destroy')
        ->whereUuid('partner');

    Route::post('partners/{partner}/validate-tax-id', [PartnerController::class, 'validateTaxId'])
        ->middleware('can:partners.update')
        ->name('partners.validate-tax-id')
        ->whereUuid('partner');

    Route::get('partners/{partner}/tax-status', [PartnerController::class, 'taxStatus'])
        ->middleware('can:partners.view')
        ->name('partners.tax-status')
        ->whereUuid('partner');

    Route::get('partners/{partner}/contacts', [PartnerController::class, 'contacts'])
        ->middleware('can:partners.view')
        ->name('partners.contacts')
        ->whereUuid('partner');
});
