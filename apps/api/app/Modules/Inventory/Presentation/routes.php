<?php

declare(strict_types=1);

use App\Modules\Company\Presentation\Controllers\LocationController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Inventory\Presentation\Controllers\CountingItemController;
use App\Modules\Inventory\Presentation\Controllers\EntryExitNoteController;
use App\Modules\Inventory\Presentation\Controllers\GoodsReceiptController;
use App\Modules\Inventory\Presentation\Controllers\InventoryCountingController;
use App\Modules\Inventory\Presentation\Controllers\LocationNodeController;
use App\Modules\Inventory\Presentation\Controllers\ProductPlacementController;
use App\Modules\Inventory\Presentation\Controllers\StockLevelController;
use App\Modules\Inventory\Presentation\Controllers\StockMovementController;
use App\Modules\Inventory\Presentation\Controllers\StockReservationController;
use App\Modules\Inventory\Presentation\Controllers\StockTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory Module API Routes
|--------------------------------------------------------------------------
|
| Stock management, locations, and inventory operations.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Inventory'])->group(function (): void {
    // Locations (using Company module's full-featured LocationController)
    Route::get('/locations', [LocationController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('locations.index');

    Route::get('/locations/{location}', [LocationController::class, 'show'])
        ->middleware('can:inventory.view')
        ->name('locations.show');

    Route::post('/locations', [LocationController::class, 'store'])
        ->middleware('can:inventory.adjust')
        ->name('locations.store');

    Route::patch('/locations/{location}', [LocationController::class, 'update'])
        ->middleware('can:inventory.adjust')
        ->name('locations.update');

    Route::delete('/locations/{location}', [LocationController::class, 'destroy'])
        ->middleware('can:inventory.adjust')
        ->name('locations.destroy');

    Route::post('/locations/{location}/set-default', [LocationController::class, 'setDefault'])
        ->middleware('can:inventory.adjust')
        ->name('locations.set-default');

    // Stock Levels
    Route::get('/stock-levels', [StockLevelController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('stock-levels.index');

    Route::get('/stock-levels/{product}/{location}', [StockLevelController::class, 'show'])
        ->middleware('can:inventory.view')
        ->name('stock-levels.show');

    // Stock Movements
    Route::get('/stock-movements', [StockMovementController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('stock-movements.index');

    Route::get('/entry-exit-notes', [EntryExitNoteController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('entry-exit-notes.index');

    Route::post('/stock-movements/receive', [StockMovementController::class, 'receive'])
        ->middleware('can:inventory.receive')
        ->name('stock-movements.receive');

    Route::post('/stock-movements/issue', [StockMovementController::class, 'issue'])
        ->middleware('can:inventory.adjust')
        ->name('stock-movements.issue');

    Route::post('/stock-movements/transfer', [StockMovementController::class, 'transfer'])
        ->middleware('can:inventory.transfer')
        ->name('stock-movements.transfer');

    // Stock Transfer documents (multi-line, lifecycle-tracked, WAC-aware).
    Route::get('/stock-transfers', [StockTransferController::class, 'index'])
        ->middleware('can:inventory.transfers.view')
        ->name('stock-transfers.index');

    Route::post('/stock-transfers', [StockTransferController::class, 'store'])
        ->middleware('can:inventory.transfers.create')
        ->name('stock-transfers.store');

    Route::get('/stock-transfers/{transfer}', [StockTransferController::class, 'show'])
        ->middleware('can:inventory.transfers.view')
        ->name('stock-transfers.show');

    Route::post('/stock-transfers/{transfer}/complete', [StockTransferController::class, 'complete'])
        ->middleware('can:inventory.transfers.complete')
        ->name('stock-transfers.complete');

    Route::post('/stock-transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])
        ->middleware('can:inventory.transfers.cancel')
        ->name('stock-transfers.cancel');

    Route::post('/stock-movements/adjust', [StockMovementController::class, 'adjust'])
        ->middleware('can:inventory.adjust')
        ->name('stock-movements.adjust');

    // Goods Receipts
    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('goods-receipts.index');
    Route::get('/goods-receipts/{receipt}', [GoodsReceiptController::class, 'show'])
        ->whereUuid('receipt')
        ->middleware('can:inventory.view')
        ->name('goods-receipts.show');

    Route::get('/goods-receipts/{receipt}/pdf', [GoodsReceiptController::class, 'pdf'])
        ->whereUuid('receipt')
        ->middleware('can:inventory.view')
        ->name('goods-receipts.pdf');

    Route::post('/goods-receipts/{receipt}/post', [GoodsReceiptController::class, 'post'])
        ->whereUuid('receipt')
        ->middleware('can:purchase-orders.receive')
        ->name('goods-receipts.post');

    Route::delete('/goods-receipts/{receipt}', [GoodsReceiptController::class, 'destroy'])
        ->whereUuid('receipt')
        ->middleware('can:purchase-orders.receive')
        ->name('goods-receipts.destroy');

    // Stock Reservations
    Route::get('/stock-reservations', [StockReservationController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('stock-reservations.index');

    // The literal `/breakdown` segment MUST be registered before the `{id}`
    // route, otherwise `/stock-reservations/breakdown` is captured as
    // `{id}` = "breakdown" and hits show() — which on PostgreSQL probes a uuid
    // column with a non-uuid and 500s (22P02) instead of reaching breakdown().
    Route::get('/stock-reservations/breakdown', [StockReservationController::class, 'breakdown'])
        ->middleware('can:inventory.view')
        ->name('stock-reservations.breakdown');

    Route::get('/stock-reservations/{id}', [StockReservationController::class, 'show'])
        ->whereUuid('id')
        ->middleware('can:inventory.view')
        ->name('stock-reservations.show');

    Route::post('/stock-reservations', [StockReservationController::class, 'store'])
        ->middleware('can:inventory.adjust')
        ->name('stock-reservations.store');

    Route::post('/stock-reservations/{id}/release', [StockReservationController::class, 'release'])
        ->whereUuid('id')
        ->middleware('can:inventory.adjust')
        ->name('stock-reservations.release');

    // ==========================================
    // Inventory Counting Routes
    // ==========================================

    // Onboarding worklist (C3): negative-on-hand / no-stock-row products at a
    // location still pending count, for the onboarding-mode review screen.
    Route::get('/inventory/onboarding-worklist', [InventoryCountingController::class, 'onboardingWorklist'])
        ->middleware('can:inventory.view')
        ->name('inventory.onboarding-worklist');

    // Dashboard
    Route::get('/inventory/countings/dashboard', [InventoryCountingController::class, 'dashboard'])
        ->middleware('can:inventory.view')
        ->name('inventory-countings.dashboard');

    // My Tasks (counter view)
    Route::get('/inventory/countings/my-tasks', [InventoryCountingController::class, 'myTasks'])
        ->name('inventory-countings.my-tasks');

    // Mobile-initiated Draft Management
    Route::get('/inventory/countings/my-drafts', [InventoryCountingController::class, 'myDrafts'])
        ->middleware('can:inventory.view')
        ->name('inventory-countings.my-drafts');

    Route::post('/inventory/countings/drafts', [InventoryCountingController::class, 'createDraft'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.create-draft');

    // Batch endpoints for offline sync
    Route::post('/inventory/countings/drafts/batch', [InventoryCountingController::class, 'batchCreateDrafts'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.batch-create-drafts');

    Route::patch('/inventory/countings/drafts/batch', [InventoryCountingController::class, 'batchUpdateDrafts'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.batch-update-drafts');

    Route::post('/inventory/countings/{counting}/add-products/batch', [InventoryCountingController::class, 'batchAddProducts'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.batch-add-products');

    Route::patch('/inventory/countings/{counting}/draft', [InventoryCountingController::class, 'updateDraft'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.update-draft');

    Route::post('/inventory/countings/{counting}/add-product', [InventoryCountingController::class, 'addProduct'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.add-product');

    Route::delete('/inventory/countings/{counting}/products/{product}', [InventoryCountingController::class, 'removeProduct'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.remove-product');

    Route::post('/inventory/countings/{counting}/activate-draft', [InventoryCountingController::class, 'activateDraft'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.activate-draft');

    // List & CRUD
    Route::get('/inventory/countings', [InventoryCountingController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('inventory-countings.index');

    Route::post('/inventory/countings', [InventoryCountingController::class, 'store'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.store');

    Route::get('/inventory/countings/{counting}', [InventoryCountingController::class, 'show'])
        ->middleware('can:inventory.view')
        ->name('inventory-countings.show');

    Route::get('/inventory/countings/{counting}/report', [InventoryCountingController::class, 'report'])
        ->middleware('can:inventory.view')
        ->name('inventory-countings.report');

    // Counter-specific endpoints (BLIND view)
    Route::get('/inventory/countings/{counting}/counter-view', [InventoryCountingController::class, 'counterView'])
        ->name('inventory-countings.counter-view');

    Route::get('/inventory/countings/{counting}/items/to-count', [CountingItemController::class, 'toCount'])
        ->name('inventory-countings.items.to-count');

    Route::get('/inventory/countings/{counting}/lookup', [CountingItemController::class, 'lookupByBarcode'])
        ->name('inventory-countings.lookup');

    // Submit count
    Route::post('/inventory/countings/{counting}/items/{item}/count', [CountingItemController::class, 'submitCount'])
        ->name('inventory-countings.items.submit-count');

    // Admin actions
    Route::post('/inventory/countings/{counting}/activate', [InventoryCountingController::class, 'activate'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.activate');

    Route::post('/inventory/countings/{counting}/cancel', [InventoryCountingController::class, 'cancel'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.cancel');

    Route::post('/inventory/countings/{counting}/finalize', [InventoryCountingController::class, 'finalize'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.finalize');

    // Reconciliation
    Route::get('/inventory/countings/{counting}/reconciliation', [CountingItemController::class, 'reconciliation'])
        ->middleware('can:inventory.view')
        ->name('inventory-countings.reconciliation');

    Route::post('/inventory/countings/{counting}/trigger-third-count', [CountingItemController::class, 'triggerThirdCount'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.trigger-third-count');

    Route::post('/inventory/countings/items/{item}/override', [CountingItemController::class, 'override'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.items.override');

    // Opening-cost backfill for onboarding lines (D3). Route params are
    // uuid-guarded so a non-uuid never reaches a uuid PK probe (PG 22P02 → 500).
    Route::patch('/inventory/countings/{counting}/items/{item}/opening-cost', [CountingItemController::class, 'setOpeningCost'])
        ->whereUuid(['counting', 'item'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-countings.items.opening-cost');

    // ==========================================
    // Location placement hierarchy — nodes are aisle/rack/shelf/bin LABELS
    // for count scoping + product placement (stock quantity stays at
    // product/location grain; nodes never carry quantity). Replaces the flat
    // zones API (FE placement UI lands in Phase 2).
    // ==========================================

    Route::get('/inventory/locations/{location}/nodes', [LocationNodeController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('inventory-nodes.index');

    Route::post('/inventory/nodes', [LocationNodeController::class, 'store'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-nodes.store');

    Route::patch('/inventory/nodes/{node}', [LocationNodeController::class, 'update'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-nodes.update');

    Route::post('/inventory/nodes/{node}/move', [LocationNodeController::class, 'move'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-nodes.move');

    Route::delete('/inventory/nodes/{node}', [LocationNodeController::class, 'destroy'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-nodes.destroy');

    Route::post('/inventory/nodes/{node}/restore', [LocationNodeController::class, 'restore'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-nodes.restore');

    // Product placements on nodes (one LIVE placement per product+location;
    // unassign = tombstone for offline delta sync)
    Route::get('/inventory/nodes/{node}/products', [ProductPlacementController::class, 'products'])
        ->middleware('can:inventory.view')
        ->name('inventory-nodes.products');

    Route::post('/inventory/nodes/{node}/assign-products', [ProductPlacementController::class, 'assignProducts'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-nodes.assign-products');

    Route::delete('/inventory/nodes/{node}/products/{product}', [ProductPlacementController::class, 'unassignProduct'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-nodes.unassign-product');

    Route::post('/inventory/placements/bulk-move', [ProductPlacementController::class, 'bulkMove'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-placements.bulk-move');

    Route::get('/inventory/products/{product}/placements', [ProductPlacementController::class, 'productPlacements'])
        ->middleware('can:inventory.view')
        ->name('inventory-products.placements');

    Route::put('/inventory/products/{product}/placements', [ProductPlacementController::class, 'setProductPlacement'])
        ->middleware('can:inventory.adjust')
        ->name('inventory-products.placements.set');
});
