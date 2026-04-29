<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\AnalyticsController;
use App\Modules\POS\Presentation\Controllers\AuthorizedManagersController;
use App\Modules\POS\Presentation\Controllers\CashDrawerController;
use App\Modules\POS\Presentation\Controllers\DiscountController;
use App\Modules\POS\Presentation\Controllers\FiscalSchemaCutoverController;
use App\Modules\POS\Presentation\Controllers\FraudSettingsPosController;
use App\Modules\POS\Presentation\Controllers\ManagerPinController;
use App\Modules\POS\Presentation\Controllers\PosAuthController;
use App\Modules\POS\Presentation\Controllers\ReceiptController;
use App\Modules\POS\Presentation\Controllers\ReportController;
use App\Modules\POS\Presentation\Controllers\ShiftController;
use App\Modules\POS\Presentation\Controllers\SyncController;
use App\Modules\POS\Presentation\Controllers\TerminalController;
use App\Modules\POS\Presentation\Controllers\VoucherSyncController;
use App\Modules\POS\Presentation\Controllers\ZReportSyncController;
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
    Route::get('/pos/auth/pin-data', [PosAuthController::class, 'pinData']);
    Route::post('/pos/auth/sync-pins', [PosAuthController::class, 'syncPins']);

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
    Route::post('/pos/terminals/{id}/toggle-training', [TerminalController::class, 'toggleTrainingMode']);
    Route::get('/pos/terminals/{id}/z-chain-state', [TerminalController::class, 'zChainState']);
    // Fiscal-schema cutover: admin-only, gated on no-open-shift + no-unzreported + empty-queue
    Route::post('/pos/terminals/{terminal}/fiscal-schema-cutover', FiscalSchemaCutoverController::class);

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

    // Reports (sync route before parameterized routes)
    Route::post('/pos/reports/z/sync', [ZReportSyncController::class, 'sync']);
    Route::post('/pos/reports/x', [ReportController::class, 'generateXReport']);
    Route::post('/pos/reports/z', [ReportController::class, 'generateZReport']);
    Route::get('/pos/reports/z/{zNumber}', [ReportController::class, 'showZReport']);
    Route::get('/pos/reports/z/{zNumber}/pdf', [ReportController::class, 'downloadPdf']);
    Route::get('/pos/reports/z', [ReportController::class, 'listZReports']);
    Route::post('/pos/reports/z/verify-chain', [ReportController::class, 'verifyZReportChain']);
    Route::post('/pos/reports/receipts/verify-chain', [ReportController::class, 'verifyReceiptChain']);

    // Sync endpoints (offline POS terminal synchronization)
    Route::post('/pos/receipts/sync', [SyncController::class, 'syncReceipts']);
    Route::get('/pos/sync/pull', [SyncController::class, 'pull']);
    Route::get('/pos/sync/menu', [SyncController::class, 'menu']);
    Route::post('/pos/shifts/{id}/sync-close', [SyncController::class, 'syncCloseShift']);

    // Voucher + receipt-QR-index sync (Session 1.5 — offline POS mirror)
    Route::get('/pos/vouchers/sync', [VoucherSyncController::class, 'pullVouchers']);
    Route::get('/pos/voucher-ledger/sync', [VoucherSyncController::class, 'pullVoucherLedger']);
    Route::post('/pos/voucher-ledger/sync', [VoucherSyncController::class, 'pushVoucherLedger']);
    Route::get('/pos/receipts/qr-index', [VoucherSyncController::class, 'pullReceiptQrIndex']);

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

    // Manager PIN verification (for shift close with variance override)
    Route::post('/pos/verify-manager-pin', [ManagerPinController::class, 'verify']);

    // Fraud settings cache (POS fetches these to populate local SQLite cache)
    Route::get('/pos/fraud-settings', [FraudSettingsPosController::class, 'show']);

    // Authorized managers for variance-close PIN approval
    Route::get('/pos/authorized-managers', [AuthorizedManagersController::class, 'index']);

    // Analytics
    Route::get('/pos/analytics/summary', [AnalyticsController::class, 'summary']);
    Route::get('/pos/analytics/sales-by-category', [AnalyticsController::class, 'salesByCategory']);
    Route::get('/pos/analytics/sales-by-product', [AnalyticsController::class, 'salesByProduct']);
    Route::get('/pos/analytics/sales-by-period', [AnalyticsController::class, 'salesByPeriod']);
    Route::get('/pos/analytics/cashiers', [AnalyticsController::class, 'cashiers']);
    Route::get('/pos/analytics/discounts', [AnalyticsController::class, 'discounts']);
    Route::get('/pos/analytics/customers', [AnalyticsController::class, 'customers']);
    Route::get('/pos/analytics/fnb', [AnalyticsController::class, 'fnb']);
});
