<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Voucher\Presentation\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Voucher Module API Routes
|--------------------------------------------------------------------------
|
| Back-office voucher management: list, detail, issuance, void, transfer,
| and expiry extension.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    Route::prefix('vouchers')->group(function () {
        Route::get('/', [VoucherController::class, 'index'])
            ->name('vouchers.index');

        Route::post('/issue-goodwill', [VoucherController::class, 'issueGoodwill'])
            ->middleware('can:pos.issue_goodwill_voucher')
            ->name('vouchers.issue-goodwill');

        Route::get('/{id}', [VoucherController::class, 'show'])
            ->name('vouchers.show');

        Route::post('/{id}/void', [VoucherController::class, 'void'])
            ->middleware('can:pos.void_voucher')
            ->name('vouchers.void');

        Route::post('/{id}/transfer', [VoucherController::class, 'transfer'])
            ->middleware('can:pos.transfer_voucher')
            ->name('vouchers.transfer');

        Route::post('/{id}/extend-expiry', [VoucherController::class, 'extendExpiry'])
            ->middleware('can:pos.extend_voucher_expiry')
            ->name('vouchers.extend-expiry');
    });
});
