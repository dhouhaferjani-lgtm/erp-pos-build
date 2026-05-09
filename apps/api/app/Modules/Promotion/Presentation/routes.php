<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Promotion\Presentation\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Promotions CRUD
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::post('promotions', [PromotionController::class, 'store']);
    Route::get('promotions/{id}', [PromotionController::class, 'show']);
    Route::patch('promotions/{id}', [PromotionController::class, 'update']);
    Route::delete('promotions/{id}', [PromotionController::class, 'destroy']);

    // Status transitions
    Route::post('promotions/{id}/activate', [PromotionController::class, 'activate']);
    Route::post('promotions/{id}/pause', [PromotionController::class, 'pause']);
    Route::post('promotions/{id}/archive', [PromotionController::class, 'archive']);
});
