<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Tenant\Presentation\Controllers\CompanySettingsController;
use App\Modules\Tenant\Presentation\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Module API Routes
|--------------------------------------------------------------------------
|
| Company settings and tenant management routes.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Company settings (requires settings.view or settings.update permission)
    Route::get('settings/company', [CompanySettingsController::class, 'show'])->name('settings.company.show');
    Route::patch('settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
    Route::post('settings/company/logo', [CompanySettingsController::class, 'uploadLogo'])->name('settings.company.logo.upload');
    Route::delete('settings/company/logo', [CompanySettingsController::class, 'deleteLogo'])->name('settings.company.logo.delete');

    // Onboarding checklist
    Route::get('onboarding/status', [OnboardingController::class, 'status'])->name('onboarding.status');
});
