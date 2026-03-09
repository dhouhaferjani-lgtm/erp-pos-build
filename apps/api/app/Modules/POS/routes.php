<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\CashDrawerController;
use App\Modules\POS\Presentation\Controllers\DiscountController;
use App\Modules\POS\Presentation\Controllers\PosAuthController;
use App\Modules\POS\Presentation\Controllers\ReceiptController;
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
    // POS Auth
    Route::post('/pos/auth/verify-pin', [PosAuthController::class, 'verifyPin']);
    Route::post('/pos/auth/setup-pin', [PosAuthController::class, 'setupPin']);
    Route::get('/pos/auth/has-pins', [PosAuthController::class, 'hasPins']);

    // Terminal Management
    Route::get('/pos/terminals', [TerminalController::class, 'index']);
    Route::get('/pos/terminals/available', [TerminalController::class, 'available']);
    Route::post('/pos/terminals/claim', [TerminalController::class, 'claim']);
    Route::post('/pos/terminals/request', [TerminalController::class, 'requestTerminal']);
    Route::post('/pos/terminals/web', [TerminalController::class, 'getOrCreateWebTerminal']);
    Route::get('/pos/terminals/by-device/{hardwareIdentifier}', [TerminalController::class, 'findByDevice']);
    Route::post('/pos/terminals', [TerminalController::class, 'store']);
    Route::get('/pos/terminals/{id}', [TerminalController::class, 'show']);
    Route::patch('/pos/terminals/{id}', [TerminalController::class, 'update']);
    Route::delete('/pos/terminals/{id}', [TerminalController::class, 'destroy']);
    Route::patch('/pos/terminals/{id}/activate', [TerminalController::class, 'activate']);
    Route::patch('/pos/terminals/{id}/deactivate', [TerminalController::class, 'deactivate']);
    Route::patch('/pos/terminals/{id}/archive', [TerminalController::class, 'archive']);

    // Shift Management
    Route::post('/pos/shifts/open', [ShiftController::class, 'open']);
    Route::post('/pos/shifts/{id}/close', [ShiftController::class, 'close']);
    Route::get('/pos/shifts/current/{terminalCode}', [ShiftController::class, 'current']);
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

    // Receipts (collection routes BEFORE parameterized)
    Route::get('/pos/receipts', [ReceiptController::class, 'index']);
    Route::post('/pos/receipts', [ReceiptController::class, 'store']);
    Route::get('/pos/receipts/{id}', [ReceiptController::class, 'show']);
    Route::post('/pos/receipts/{id}/void', [ReceiptController::class, 'void']);
    Route::post('/pos/receipts/{id}/return', [ReceiptController::class, 'processReturn']);
    Route::post('/pos/receipts/{id}/payments', [ReceiptController::class, 'storePayments']);
    Route::get('/pos/receipts/{id}/pdf', [ReceiptController::class, 'streamPdf']);
    Route::get('/pos/receipts/{id}/pdf/download', [ReceiptController::class, 'downloadPdf']);

    // Shift receipts (transaction history)
    Route::get('/pos/shifts/{id}/receipts', [ShiftController::class, 'receipts']);

    // Discount Permissions & Preview
    Route::get('/pos/discount-permissions', [DiscountController::class, 'getPermissions']);
    Route::post('/pos/cart/preview-discounts', [DiscountController::class, 'previewDiscounts']);
});
