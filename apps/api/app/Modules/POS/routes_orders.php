<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\POS\Presentation\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/**
 * POS Order Management Routes
 *
 * Separate route file to avoid merge conflicts with the main POS routes.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Order CRUD
    Route::post('/pos/orders', [OrderController::class, 'store']);
    Route::get('/pos/orders', [OrderController::class, 'index']);
    Route::get('/pos/orders/{id}', [OrderController::class, 'show']);

    // Order Line Management
    Route::post('/pos/orders/{id}/lines', [OrderController::class, 'addLine']);
    Route::patch('/pos/orders/{id}/lines/{lineId}', [OrderController::class, 'modifyLine']);
    Route::delete('/pos/orders/{id}/lines/{lineId}', [OrderController::class, 'removeLine']);

    // Order Workflow
    Route::post('/pos/orders/{id}/send-to-kitchen', [OrderController::class, 'sendToKitchen']);
    // §14.2 — Order-close → SALE_RECEIPT path retired. The order-close
    // controller chain (OrderManagementService::closeOrder →
    // OrderToReceiptService::convertToReceipt →
    // ReceiptCreationService::createReceipt) is one of three §14.2
    // server-authoring paths. The route returns 410 Gone with
    // NEW_SALE_AUTHORING_RETIRED; the rest of order CRUD/lines/kitchen
    // routes above are untouched. The order itself is NOT mutated to
    // closed when the route returns 410 — the route closure short-circuits
    // before any side effect.
    //
    // Auth-middleware contract (Opus T29-F2 P2 deferral — round-2): The
    // closure sits inside the `auth:sanctum` middleware group from
    // routes.php. Anonymous callers receive 401 BEFORE reaching the
    // closure — by design on an authenticated POS/API surface. Both
    // reviewers acknowledged. (Task 29 R2)
    Route::post('/pos/orders/{id}/close', function (string $id) {
        return response()->json([
            'error' => [
                'code' => 'NEW_SALE_AUTHORING_RETIRED',
                'message' => 'POST /api/v1/pos/orders/{id}/close is retired for new-sale SALE_RECEIPT authoring per fiscal Phase 1 §14.2. Receipts are now device-authored and ingested via POST /api/v1/pos/sync/fiscal-events.',
            ],
        ], 410);
    });
    Route::post('/pos/orders/{id}/cancel', [OrderController::class, 'cancel']);
});
