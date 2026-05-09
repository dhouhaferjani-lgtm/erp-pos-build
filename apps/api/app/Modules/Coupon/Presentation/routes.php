<?php

declare(strict_types=1);

use App\Modules\Coupon\Presentation\Controllers\CouponController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Coupons CRUD
    Route::get('coupons', [CouponController::class, 'index']);
    Route::post('coupons', [CouponController::class, 'store']);
    Route::get('coupons/{id}', [CouponController::class, 'show']);
    Route::patch('coupons/{id}', [CouponController::class, 'update']);
    Route::delete('coupons/{id}', [CouponController::class, 'destroy']);

    // POS validation endpoint
    Route::post('coupons/validate', [CouponController::class, 'validate']);

    // Status transitions
    Route::post('coupons/{id}/revoke', [CouponController::class, 'revoke']);
    Route::post('coupons/{id}/reactivate', [CouponController::class, 'reactivate']);
});
