<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Product\Presentation\Controllers\CategoryController;
use App\Modules\Product\Presentation\Controllers\ProductController;
use App\Modules\Product\Presentation\Controllers\ProductImageController;
use App\Modules\Product\Presentation\Controllers\PublicProductImageController;
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

    // Product Images (authenticated)
    Route::prefix('products/{product}/images')->middleware('can:products.view')->group(function () {
        Route::get('/', [ProductImageController::class, 'index'])
            ->name('products.images.index');

        Route::post('/', [ProductImageController::class, 'store'])
            ->middleware(['can:products.update', 'throttle:image-upload'])
            ->name('products.images.store');

        Route::patch('/{image}', [ProductImageController::class, 'update'])
            ->middleware('can:products.update')
            ->name('products.images.update');

        Route::delete('/{image}', [ProductImageController::class, 'destroy'])
            ->middleware('can:products.update')
            ->name('products.images.destroy');

        Route::get('/{image}/download', [ProductImageController::class, 'download'])
            ->name('products.images.download');

        Route::post('/reorder', [ProductImageController::class, 'reorder'])
            ->middleware('can:products.update')
            ->name('products.images.reorder');
    });
});

// Public routes (rate-limited)
Route::prefix('api/v1/public')->middleware(['api', 'throttle:public-product-images'])->group(function () {
    Route::get('products/{product}/images', [PublicProductImageController::class, 'index'])
        ->name('public.products.images.index');

    Route::get('products/{product}/images/{image}', [PublicProductImageController::class, 'show'])
        ->name('public.products.images.show');
});
