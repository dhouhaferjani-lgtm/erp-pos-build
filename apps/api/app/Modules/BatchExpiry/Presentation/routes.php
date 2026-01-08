<?php

declare(strict_types=1);

use App\Modules\BatchExpiry\Presentation\Controllers\BatchController;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Batch CRUD
    Route::get('/batches', [BatchController::class, 'index']);
    Route::post('/batches', [BatchController::class, 'store']);
    Route::get('/batches/{uuid}', [BatchController::class, 'show']);
    Route::patch('/batches/{uuid}', [BatchController::class, 'update']);
    Route::delete('/batches/{uuid}', [BatchController::class, 'destroy']);

    // Batch operations
    Route::post('/batches/{uuid}/recall', [BatchController::class, 'recall']);
    Route::get('/batches/expiring', [BatchController::class, 'expiring']);

    // Batch stock
    Route::get('/batches/{uuid}/stock', [BatchController::class, 'stock']);

    // Product batch stock
    Route::get('/products/{productId}/batch-stock', [BatchController::class, 'productBatchStock']);

    // POS Integration
    Route::get('/pos/products/{productId}/batches', [BatchController::class, 'posAvailableBatches']);
});
