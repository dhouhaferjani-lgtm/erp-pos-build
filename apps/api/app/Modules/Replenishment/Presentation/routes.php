<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Replenishment\Presentation\Controllers\ReplenishmentRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(static function (): void {
        Route::get('/replenishment-requests', [ReplenishmentRequestController::class, 'index'])
            ->middleware('can:replenishment.view')
            ->name('replenishment-requests.index');
        Route::post('/replenishment-requests', [ReplenishmentRequestController::class, 'store'])
            ->middleware('can:replenishment.create')
            ->name('replenishment-requests.store');
        Route::post('/replenishment-requests/{id}/cancel', [ReplenishmentRequestController::class, 'cancel'])
            ->middleware('can:replenishment.create')
            ->whereUuid('id')
            ->name('replenishment-requests.cancel');
    });
