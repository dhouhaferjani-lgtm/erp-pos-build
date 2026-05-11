<?php

declare(strict_types=1);

use App\Modules\Company\Presentation\Controllers\LocationController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Inventory\Presentation\Controllers\CountingItemController;
use App\Modules\Inventory\Presentation\Controllers\InventoryCountingController;
use App\Modules\Inventory\Presentation\Controllers\StockLevelController;
use App\Modules\Inventory\Presentation\Controllers\StockMovementController;
use App\Modules\Inventory\Presentation\Controllers\StockReservationController;
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

    Route::post('/stock-movements/receive', [StockMovementController::class, 'receive'])
        ->middleware('can:inventory.receive')
        ->name('stock-movements.receive');

    Route::post('/stock-movements/issue', [StockMovementController::class, 'issue'])
        ->middleware('can:inventory.adjust')
        ->name('stock-movements.issue');

    Route::post('/stock-movements/transfer', [StockMovementController::class, 'transfer'])
        ->middleware('can:inventory.transfer')
        ->name('stock-movements.transfer');

    Route::post('/stock-movements/adjust', [StockMovementController::class, 'adjust'])
        ->middleware('can:inventory.adjust')
        ->name('stock-movements.adjust');

    // Stock Reservations
    Route::get('/stock-reservations', [StockReservationController::class, 'index'])
        ->middleware('can:inventory.view')
        ->name('stock-reservations.index');

    Route::get('/stock-reservations/{id}', [StockReservationController::class, 'show'])
        ->middleware('can:inventory.view')
        ->name('stock-reservations.show');

    Route::post('/stock-reservations', [StockReservationController::class, 'store'])
        ->middleware('can:inventory.adjust')
        ->name('stock-reservations.store');

    Route::post('/stock-reservations/{id}/release', [StockReservationController::class, 'release'])
        ->middleware('can:inventory.adjust')
        ->name('stock-reservations.release');

    Route::get('/stock-reservations/breakdown', [StockReservationController::class, 'breakdown'])
        ->middleware('can:inventory.view')
        ->name('stock-reservations.breakdown');

    // ==========================================
    // Inventory Counting Routes
    // ==========================================

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
});
