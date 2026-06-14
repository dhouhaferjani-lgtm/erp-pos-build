<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\AnalyticsController;
use App\Modules\POS\Presentation\Controllers\AuditEventSyncController;
use App\Modules\POS\Presentation\Controllers\AuthorizedManagersController;
use App\Modules\POS\Presentation\Controllers\CashDrawerController;
use App\Modules\POS\Presentation\Controllers\CustomerHistorySearchAuditController;
use App\Modules\POS\Presentation\Controllers\DiscountController;
use App\Modules\POS\Presentation\Controllers\FiscalSchemaCutoverController;
use App\Modules\POS\Presentation\Controllers\FraudSettingsPosController;
use App\Modules\POS\Presentation\Controllers\ManagerPinController;
use App\Modules\POS\Presentation\Controllers\PosAuthController;
use App\Modules\POS\Presentation\Controllers\PosCustomerSyncController;
use App\Modules\POS\Presentation\Controllers\PosPendingCustomerController;
use App\Modules\POS\Presentation\Controllers\PosStockLevelController;
use App\Modules\POS\Presentation\Controllers\ReceiptController;
use App\Modules\POS\Presentation\Controllers\ReportController;
use App\Modules\POS\Presentation\Controllers\ShiftController;
use App\Modules\POS\Presentation\Controllers\StockDistributionController;
use App\Modules\POS\Presentation\Controllers\SyncController;
use App\Modules\POS\Presentation\Controllers\TerminalController;
use App\Modules\POS\Presentation\Controllers\VoucherSyncController;
use App\Modules\POS\Presentation\Controllers\ZReportSyncController;
use App\Modules\POS\Presentation\Middleware\EnsureWebPosDemoTenant;
use Illuminate\Support\Facades\Route;

/**
 * POS Module Routes
 *
 * All routes require authentication and company context.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // POS Auth
    Route::post('/pos/auth/verify-pin', [PosAuthController::class, 'verifyPin']);
    Route::post('/pos/auth/setup-pin', [PosAuthController::class, 'setupPin']);
    Route::get('/pos/auth/has-pins', [PosAuthController::class, 'hasPins']);
    Route::get('/pos/auth/pin-data', [PosAuthController::class, 'pinData']);
    Route::post('/pos/auth/sync-pins', [PosAuthController::class, 'syncPins']);

    // POS audit-event ingest (offline-first fraud/audit pipeline — Sub-Spec C)
    Route::post('/pos/audit-events/sync', [AuditEventSyncController::class, 'sync']);

    // Terminal Management
    Route::get('/pos/terminals', [TerminalController::class, 'index']);
    Route::get('/pos/terminals/available', [TerminalController::class, 'available']);
    Route::post('/pos/terminals/claim', [TerminalController::class, 'claim'])
        ->middleware('throttle:pos-terminal-activation');
    Route::post('/pos/terminals/request', [TerminalController::class, 'requestTerminal'])
        ->middleware('throttle:pos-terminal-activation');
    Route::post('/pos/terminals/web', [TerminalController::class, 'getOrCreateWebTerminal'])
        ->middleware('throttle:pos-terminal-activation');
    Route::get('/pos/terminals/by-device/{hardwareIdentifier}', [TerminalController::class, 'findByDevice']);
    Route::post('/pos/terminals', [TerminalController::class, 'store']);
    Route::get('/pos/terminals/{id}', [TerminalController::class, 'show']);
    Route::patch('/pos/terminals/{id}', [TerminalController::class, 'update']);
    Route::delete('/pos/terminals/{id}', [TerminalController::class, 'destroy']);
    Route::patch('/pos/terminals/{id}/activate', [TerminalController::class, 'activate'])
        ->middleware('throttle:pos-terminal-activation');
    Route::patch('/pos/terminals/{id}/deactivate', [TerminalController::class, 'deactivate']);
    Route::patch('/pos/terminals/{id}/archive', [TerminalController::class, 'archive']);
    Route::post('/pos/terminals/{id}/toggle-training', [TerminalController::class, 'toggleTrainingMode']);
    Route::get('/pos/terminals/{id}/z-chain-state', [TerminalController::class, 'zChainState']);
    // Fiscal-schema cutover: admin-only, gated on no-open-shift + no-unzreported + empty-queue
    Route::post('/pos/terminals/{terminal}/fiscal-schema-cutover', FiscalSchemaCutoverController::class);

    // Shift Management
    // Web POS is demo-account-only (owner decision 2026-06-11): the six
    // fiscal-mutating routes the browser POS reaches are gated by
    // EnsureWebPosDemoTenant — Tauri devices (X-Client-Type: pos-tauri)
    // pass; browser callers need tenants.is_demo. Read-only shift/Z views
    // stay open for all tenants (back-office windows onto synced data).
    Route::middleware(EnsureWebPosDemoTenant::class)->group(function (): void {
        Route::post('/pos/shifts/open', [ShiftController::class, 'open']);
        Route::post('/pos/shifts/{id}/close', [ShiftController::class, 'close']);
        Route::post('/pos/cash-drawer/deposit', [CashDrawerController::class, 'deposit']);
        Route::post('/pos/cash-drawer/payout', [CashDrawerController::class, 'payout']);
        Route::post('/pos/reports/x', [ReportController::class, 'generateXReport']);
        Route::post('/pos/reports/z', [ReportController::class, 'generateZReport']);
    });
    Route::get('/pos/shifts/current/{terminalCode}', [ShiftController::class, 'current']);
    Route::get('/pos/shifts/{id}', [ShiftController::class, 'show']);
    Route::get('/pos/shifts', [ShiftController::class, 'index']);

    // Cash Drawer Operations
    Route::get('/pos/cash-drawer/{shiftId}/operations', [CashDrawerController::class, 'operations']);
    Route::get('/pos/cash-drawer/{shiftId}/balance', [CashDrawerController::class, 'balance']);

    // Reports (sync route before parameterized routes)
    Route::post('/pos/reports/z/sync', [ZReportSyncController::class, 'sync']);
    Route::get('/pos/reports/z/{zNumber}', [ReportController::class, 'showZReport']);
    Route::get('/pos/reports/z/{zNumber}/pdf', [ReportController::class, 'downloadPdf']);
    Route::get('/pos/reports/z', [ReportController::class, 'listZReports']);
    Route::post('/pos/reports/z/verify-chain', [ReportController::class, 'verifyZReportChain']);
    Route::post('/pos/reports/receipts/verify-chain', [ReportController::class, 'verifyReceiptChain']);

    // Sync endpoints (offline POS terminal synchronization)
    Route::get('/pos/sync/pull', [SyncController::class, 'pull']);
    Route::get('/pos/sync/menu', [SyncController::class, 'menu']);
    Route::post('/pos/shifts/{id}/sync-close', [SyncController::class, 'syncCloseShift']);

    // Location stock feed for the device sync (spec 2026-06-11 §4.1)
    Route::get('/pos/stock-levels', [PosStockLevelController::class, 'index']);

    // Cross-location stock distribution for one product (Task B5 — gated + variant-aware)
    Route::get('/pos/products/{product}/stock-distribution', [StockDistributionController::class, 'show']);

    // Voucher + receipt-QR-index sync (Session 1.5 — offline POS mirror)
    Route::get('/pos/vouchers/sync', [VoucherSyncController::class, 'pullVouchers']);
    Route::get('/pos/voucher-ledger/sync', [VoucherSyncController::class, 'pullVoucherLedger']);
    Route::post('/pos/voucher-ledger/sync', [VoucherSyncController::class, 'pushVoucherLedger']);
    Route::get('/pos/receipts/qr-index', [VoucherSyncController::class, 'pullReceiptQrIndex']);
    Route::get('/pos/customers/sync', [PosCustomerSyncController::class, 'index'])
        ->name('pos.customers.sync');
    Route::post('/pos/customers/pending', [PosPendingCustomerController::class, 'store'])
        ->name('pos.customers.pending.store');

    // Receipts (collection routes BEFORE parameterized)
    Route::get('/pos/receipts', [ReceiptController::class, 'index']);
    // §14.2 — New-sale SALE_RECEIPT server-authoring retired. Routes return
    // 410 Gone with NEW_SALE_AUTHORING_RETIRED. The route-level closure
    // short-circuits BEFORE FormRequest validation runs, so callers get the
    // disposition code regardless of payload shape (the FormRequest would
    // otherwise convert a missing field into a 422 and mask the retirement).
    // Knowingly retained per §14.2: `void` and `processReturn` —
    // SALE_VOID, REFUND_RECEIPT, PARTIAL_REFUND event types are Phase 2+
    // reserved and both routes are shared with the offline Tauri POS.
    //
    // Auth-middleware contract (Opus T29-F2 P2 deferral — round-2): The 410
    // closures below sit inside the `auth:sanctum` middleware group at
    // routes.php:29. Anonymous callers receive 401 from auth:sanctum BEFORE
    // reaching the closure. This is by design: the retired surface is the
    // authenticated POS/API surface — disposition is returned to in-app
    // (authenticated) callers, while anon probes get the standard 401 for
    // an authenticated endpoint. Both Codex r1 and Opus r1 acknowledged
    // this contract; Codex did not escalate. Tests assert the
    // authenticated-caller contract via Sanctum::actingAs(). (Task 29 R2)
    Route::post('/pos/receipts', function () {
        return response()->json([
            'error' => [
                'code' => 'NEW_SALE_AUTHORING_RETIRED',
                'message' => 'POST /api/v1/pos/receipts is retired for new-sale SALE_RECEIPT authoring per fiscal Phase 1 §14.2. Receipts are now device-authored and ingested via POST /api/v1/pos/sync/fiscal-events.',
            ],
        ], 410);
    });
    Route::get('/pos/receipts/{id}', [ReceiptController::class, 'show']);
    Route::post('/pos/receipts/{id}/void', [ReceiptController::class, 'void']);
    Route::post('/pos/receipts/{id}/return', [ReceiptController::class, 'processReturn']);
    Route::post('/pos/receipts/{id}/payments', function (string $id) {
        // §14.2 — storePayments is the second new-sale authoring call-site
        // (the Treasury Payment + GL write chain). Retired in lock-step
        // with POST /pos/receipts. The device authors the payment lines
        // inside the SALE_RECEIPT envelope; the Treasury bridge projects
        // them on ingestion (Task 22).
        return response()->json([
            'error' => [
                'code' => 'NEW_SALE_AUTHORING_RETIRED',
                'message' => 'POST /api/v1/pos/receipts/{id}/payments is retired for new-sale Treasury payment authoring per fiscal Phase 1 §14.2. Receipts and their payment lines are now device-authored and ingested via POST /api/v1/pos/sync/fiscal-events.',
            ],
        ], 410);
    });
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

    // Customer history search audit log (Manager / Admin only)
    Route::get('/pos/customer-history-searches', [CustomerHistorySearchAuditController::class, 'index'])
        ->middleware('can:pos.search_customer_full_history')
        ->name('pos.customer-history-searches.index');

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
