<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Progression\Presentation\Controllers\CompanyProgressionController;
use App\Modules\Progression\Presentation\Controllers\ModuleReadinessController;
use App\Modules\Progression\Presentation\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/progression')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
    ->group(function (): void {
        // Company profile & registration
        Route::get('profile', [CompanyProgressionController::class, 'show'])->name('progression.profile');
        Route::post('register', [CompanyProgressionController::class, 'register'])->name('progression.register');
        Route::get('milestones', [CompanyProgressionController::class, 'milestones'])->name('progression.milestones');

        // Module readiness
        Route::get('modules', [ModuleReadinessController::class, 'index'])->name('progression.modules.index');
        Route::post('modules/{moduleId}/activate', [ModuleReadinessController::class, 'activate'])->name('progression.modules.activate');

        // Recommendations
        Route::get('recommendations', [RecommendationController::class, 'index'])->name('progression.recommendations.index');
        Route::post('recommendations/{recommendationId}/accept', [RecommendationController::class, 'accept'])->name('progression.recommendations.accept');
        Route::post('recommendations/{recommendationId}/dismiss', [RecommendationController::class, 'dismiss'])->name('progression.recommendations.dismiss');
    });
