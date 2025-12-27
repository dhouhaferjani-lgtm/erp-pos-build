<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Product\Presentation\Controllers\CategoryController;
use App\Modules\Product\Presentation\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Product Module API Routes
|--------------------------------------------------------------------------
|
| Product/catalog management routes.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Product CRUD with permission middleware
    Route::get('products', [ProductController::class, 'index'])
        ->middleware('can:products.view')
        ->name('products.index');

    Route::get('products/{product}', [ProductController::class, 'show'])
        ->middleware('can:products.view')
        ->name('products.show');

    Route::get('products/{product}/stock-levels', [ProductController::class, 'stockLevels'])
        ->middleware('can:products.view')
        ->name('products.stock-levels');

    Route::post('products', [ProductController::class, 'store'])
        ->middleware('can:products.create')
        ->name('products.store');

    Route::patch('products/{product}', [ProductController::class, 'update'])
        ->middleware('can:products.update')
        ->name('products.update');

    Route::delete('products/{product}', [ProductController::class, 'destroy'])
        ->middleware('can:products.delete')
        ->name('products.destroy');

    // Categories
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/tree', [CategoryController::class, 'tree'])->name('categories.tree');
        Route::post('/', [CategoryController::class, 'store'])->name('categories.store');
        Route::get('/{id}', [CategoryController::class, 'show'])->name('categories.show');
        Route::put('/{id}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::post('/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
    });
});
