<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\CashDrawerController;
use App\Modules\POS\Presentation\Controllers\ReportController;
use App\Modules\POS\Presentation\Controllers\ShiftController;
use App\Modules\POS\Presentation\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

/**
 * POS Module Routes
 *
 * All routes require authentication and company context.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Terminal Management
    Route::get('/pos/terminals', [TerminalController::class, 'index']);
    Route::post('/pos/terminals', [TerminalController::class, 'store']);
    Route::get('/pos/terminals/{id}', [TerminalController::class, 'show']);
    Route::patch('/pos/terminals/{id}', [TerminalController::class, 'update']);
    Route::delete('/pos/terminals/{id}', [TerminalController::class, 'destroy']);
    Route::patch('/pos/terminals/{id}/activate', [TerminalController::class, 'activate']);
    Route::patch('/pos/terminals/{id}/deactivate', [TerminalController::class, 'deactivate']);

    // Shift Management
    Route::post('/pos/shifts/open', [ShiftController::class, 'open']);
    Route::post('/pos/shifts/{id}/close', [ShiftController::class, 'close']);
    Route::get('/pos/shifts/current/{terminalId}', [ShiftController::class, 'current']);
    Route::get('/pos/shifts/{id}', [ShiftController::class, 'show']);
    Route::get('/pos/shifts', [ShiftController::class, 'index']);

    // Cash Drawer Operations
    Route::post('/pos/cash-drawer/deposit', [CashDrawerController::class, 'deposit']);
    Route::post('/pos/cash-drawer/payout', [CashDrawerController::class, 'payout']);
    Route::get('/pos/cash-drawer/{shiftId}/operations', [CashDrawerController::class, 'operations']);
    Route::get('/pos/cash-drawer/{shiftId}/balance', [CashDrawerController::class, 'balance']);

    // Reports
    Route::post('/pos/reports/x', [ReportController::class, 'generateXReport']);
    Route::post('/pos/reports/z', [ReportController::class, 'generateZReport']);
    Route::get('/pos/reports/z/{zNumber}', [ReportController::class, 'showZReport']);
    Route::get('/pos/reports/z', [ReportController::class, 'listZReports']);
    Route::post('/pos/reports/z/verify-chain', [ReportController::class, 'verifyZReportChain']);
});
