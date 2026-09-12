<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Promotion\Presentation\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Promotions CRUD. index/show already check promotions.view in the
    // controller (PromotionController.php:29,69) and store/update check
    // promotions.manage in their FormRequests (StorePromotionRequest.php:17,
    // UpdatePromotionRequest.php:17); destroy and the three status transitions
    // (PromotionController.php:122,146,170,194) check NOTHING at any layer — an
    // intra-controller inconsistency, not a policy.
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::post('promotions', [PromotionController::class, 'store']);
    Route::get('promotions/{id}', [PromotionController::class, 'show']);
    Route::patch('promotions/{id}', [PromotionController::class, 'update']);
    Route::delete('promotions/{id}', [PromotionController::class, 'destroy'])
        ->middleware('can:promotions.manage');

    // Status transitions
    Route::post('promotions/{id}/activate', [PromotionController::class, 'activate'])
        ->middleware('can:promotions.manage');
    Route::post('promotions/{id}/pause', [PromotionController::class, 'pause'])
        ->middleware('can:promotions.manage');
    Route::post('promotions/{id}/archive', [PromotionController::class, 'archive'])
        ->middleware('can:promotions.manage');
});
