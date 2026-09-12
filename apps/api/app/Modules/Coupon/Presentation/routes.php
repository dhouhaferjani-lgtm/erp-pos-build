<?php

declare(strict_types=1);

use App\Modules\Coupon\Presentation\Controllers\CouponController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Coupons CRUD. The two reads are the only API surface behind the /coupons
    // page the frontend ALREADY gates on coupons.view (spec 4.9.3), so gating
    // them turns a key the audit called dead into a key the router enforces.
    Route::get('coupons', [CouponController::class, 'index'])
        ->middleware('can:coupons.view');
    Route::post('coupons', [CouponController::class, 'store']);
    Route::get('coupons/{id}', [CouponController::class, 'show'])
        ->middleware('can:coupons.view');
    Route::patch('coupons/{id}', [CouponController::class, 'update']);
    Route::delete('coupons/{id}', [CouponController::class, 'destroy'])
        ->middleware('can:coupons.manage');

    // POS validation endpoint — deliberately NOT gated in wave 0a. It is a write
    // verb on the till path; gating it on a back-office permission is a
    // functional change, not a hardening. It stays in the uncovered baseline and
    // closes with the rest of the POS surface in wave 3.
    Route::post('coupons/validate', [CouponController::class, 'validate']);

    // Status transitions
    Route::post('coupons/{id}/revoke', [CouponController::class, 'revoke'])
        ->middleware('can:coupons.manage');
    Route::post('coupons/{id}/reactivate', [CouponController::class, 'reactivate'])
        ->middleware('can:coupons.manage');
});
