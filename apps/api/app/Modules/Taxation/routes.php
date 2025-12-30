<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Taxation\Presentation\Controllers\StampDutyRuleController;
use App\Modules\Taxation\Presentation\Controllers\TaxConfigurationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function (): void {
    // Tax Configuration endpoints
    Route::prefix('taxation/configurations')->group(function (): void {
        Route::get('/', [TaxConfigurationController::class, 'index']);
        Route::get('/{id}', [TaxConfigurationController::class, 'show']);
        Route::post('/', [TaxConfigurationController::class, 'store']);
        Route::patch('/{id}', [TaxConfigurationController::class, 'update']);
        Route::delete('/{id}', [TaxConfigurationController::class, 'destroy']);
    });

    // Stamp Duty Rule endpoints
    Route::prefix('taxation/stamp-duties')->group(function (): void {
        Route::get('/', [StampDutyRuleController::class, 'index']);
        Route::get('/{id}', [StampDutyRuleController::class, 'show']);
        Route::post('/', [StampDutyRuleController::class, 'store']);
        Route::patch('/{id}', [StampDutyRuleController::class, 'update']);
        Route::delete('/{id}', [StampDutyRuleController::class, 'destroy']);
    });
});
