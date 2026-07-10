<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Replenishment\Presentation\Controllers\ReplenishmentActionController;
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
        Route::post('/replenishment-requests/actions/create-transfer', [ReplenishmentActionController::class, 'createTransfer'])
            ->middleware('can:replenishment.process')
            ->name('replenishment-requests.actions.create-transfer');
        Route::post('/replenishment-requests/actions/create-po', [ReplenishmentActionController::class, 'createPo'])
            ->middleware('can:replenishment.process')
            ->name('replenishment-requests.actions.create-po');
        Route::post('/replenishment-requests/actions/reject', [ReplenishmentActionController::class, 'reject'])
            ->middleware('can:replenishment.process')
            ->name('replenishment-requests.actions.reject');
        Route::post('/replenishment-requests/{id}/cancel', [ReplenishmentRequestController::class, 'cancel'])
            ->whereUuid('id')
            ->name('replenishment-requests.cancel');
    });
