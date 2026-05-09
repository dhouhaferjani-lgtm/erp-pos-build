<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\SmartPrompts\Presentation\Controllers\SmartPromptsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/smart-prompts')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(function (): void {
        Route::post('/recommendations', [SmartPromptsController::class, 'recommendations'])->name('smart-prompts.recommendations');
    });
