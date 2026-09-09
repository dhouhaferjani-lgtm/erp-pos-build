<?php

declare(strict_types=1);

use App\Modules\BatchExpiry\Presentation\Controllers\BatchController;
use App\Modules\BatchExpiry\Presentation\Controllers\BatchTraceabilityController;
use App\Modules\BatchExpiry\Presentation\Controllers\GroupedWriteOffController;
use App\Modules\BatchExpiry\Presentation\Middleware\BatchActionAccess;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:BatchExpiry'])->group(function () {
    // Batch operations (literal routes BEFORE parameterized)
    Route::get('/batches/expiring', [BatchController::class, 'expiring'])->middleware(BatchActionAccess::class.':batches.view');
    Route::get('/batches/expired', [BatchController::class, 'expired'])->middleware(BatchActionAccess::class.':batches.view');

    // Grouped (multi-lot) write-off — literal route, must stay before {uuid} routes.
    Route::post('/batches/write-off-grouped', [GroupedWriteOffController::class, 'writeOffGrouped'])
        ->middleware('can:batches.write-off');

    // Batch CRUD
    Route::get('/batches', [BatchController::class, 'index'])->middleware(BatchActionAccess::class.':batches.view');
    Route::post('/batches', [BatchController::class, 'store']);
    Route::get('/batches/{uuid}', [BatchController::class, 'show'])->middleware(BatchActionAccess::class.':batches.view');
    Route::patch('/batches/{uuid}', [BatchController::class, 'update']);
    Route::delete('/batches/{uuid}', [BatchController::class, 'destroy'])->middleware(BatchActionAccess::class.':batches.delete');

    // Batch operations (parameterized)
    Route::post('/batches/{uuid}/recall', [BatchController::class, 'recall'])->middleware(BatchActionAccess::class.':batches.recall');
    Route::post('/batches/{uuid}/transfer', [BatchController::class, 'transfer']);
    Route::post('/batches/{uuid}/write-off', [BatchController::class, 'writeOff']);

    // Write-off reversal (targets the write-off stock_movement, not the batch).
    Route::post('/stock-movements/{movementId}/reverse-write-off', [BatchController::class, 'reverseWriteOff'])
        ->middleware('can:batches.write-off');

    // Batch traceability
    Route::get('/batches/{uuid}/traceability', [BatchTraceabilityController::class, 'forwardTrace'])->middleware(BatchActionAccess::class.':batches.traceability');
    Route::get('/partners/{partnerId}/batch-history', [BatchTraceabilityController::class, 'backwardTrace'])->middleware(BatchActionAccess::class.':batches.traceability');

    // Batch stock
    Route::get('/batches/{uuid}/stock', [BatchController::class, 'stock'])->middleware(BatchActionAccess::class.':batches.view');

    // Product batch stock
    Route::get('/products/{productId}/batch-stock', [BatchController::class, 'productBatchStock'])->middleware(BatchActionAccess::class.':batches.view');

    // POS Integration
    Route::get('/pos/products/{productId}/batches', [BatchController::class, 'posAvailableBatches'])->middleware(BatchActionAccess::class.':batches.view');
});
