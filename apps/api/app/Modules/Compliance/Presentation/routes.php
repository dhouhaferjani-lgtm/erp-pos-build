<?php

declare(strict_types=1);

use App\Modules\Compliance\Presentation\Controllers\FraudAlertController;
use App\Modules\Compliance\Presentation\Controllers\FraudSettingsController;
use App\Modules\Compliance\Presentation\Controllers\Nf525ExportController;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Compliance Module API Routes
|--------------------------------------------------------------------------
|
| Fraud detection, audit logs, and compliance features.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function (): void {
    // Fraud Detection Settings
    Route::get('/fraud-settings', [FraudSettingsController::class, 'show'])
        ->middleware('can:fraud-settings.view')
        ->name('fraud-settings.show');

    Route::patch('/fraud-settings', [FraudSettingsController::class, 'update'])
        ->middleware('can:fraud-settings.update')
        ->name('fraud-settings.update');

    Route::post('/fraud-settings/reset', [FraudSettingsController::class, 'reset'])
        ->middleware('can:fraud-settings.update')
        ->name('fraud-settings.reset');

    // Fraud Alerts
    Route::get('/fraud-alerts', [FraudAlertController::class, 'index'])
        ->middleware('can:fraud-alerts.view')
        ->name('fraud-alerts.index');

    Route::get('/fraud-alerts/statistics', [FraudAlertController::class, 'statistics'])
        ->middleware('can:fraud-alerts.view')
        ->name('fraud-alerts.statistics');

    Route::get('/fraud-alerts/{alert}', [FraudAlertController::class, 'show'])
        ->middleware('can:fraud-alerts.view')
        ->name('fraud-alerts.show');

    Route::post('/fraud-alerts/{alert}/assign', [FraudAlertController::class, 'assign'])
        ->middleware('can:fraud-alerts.manage')
        ->name('fraud-alerts.assign');

    Route::post('/fraud-alerts/{alert}/dismiss', [FraudAlertController::class, 'dismiss'])
        ->middleware('can:fraud-alerts.manage')
        ->name('fraud-alerts.dismiss');

    Route::post('/fraud-alerts/{alert}/resolve', [FraudAlertController::class, 'resolve'])
        ->middleware('can:fraud-alerts.manage')
        ->name('fraud-alerts.resolve');

    // NF525 Export
    Route::post('/compliance/nf525/export-jet', [Nf525ExportController::class, 'exportJet'])
        ->middleware('can:compliance.export_jet')
        ->name('compliance.nf525.export-jet');

    Route::post('/compliance/nf525/verify-chains', [Nf525ExportController::class, 'verifyChains'])
        ->middleware('can:compliance.verify_chains')
        ->name('compliance.nf525.verify-chains');

    Route::get('/compliance/nf525/reprint-log', [Nf525ExportController::class, 'reprintLog'])
        ->middleware('can:compliance.view_reprint_log')
        ->name('compliance.nf525.reprint-log');
});
