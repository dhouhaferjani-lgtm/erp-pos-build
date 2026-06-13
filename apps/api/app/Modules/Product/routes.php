<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Product\Presentation\Controllers\CategoryController;
use App\Modules\Product\Presentation\Controllers\CertificationController;
use App\Modules\Product\Presentation\Controllers\EnrichmentReviewController;
use App\Modules\Product\Presentation\Controllers\HealthClaimController;
use App\Modules\Product\Presentation\Controllers\IngredientController;
use App\Modules\Product\Presentation\Controllers\KeyComponentController;
use App\Modules\Product\Presentation\Controllers\ProductController;
use App\Modules\Catalog\Presentation\Controllers\ProductMediaController;
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

// Group 1: Categories — ungated (no Inventory module required)
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
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

// Group 2: Products and all product-related routes — gated behind Inventory module
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Inventory'])->group(function () {
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

    // Parapharmacy master data — additionally gated on the Parapharmacy
    // module (default module of the parapharmacy vertical). Appended after
    // the outer group's middleware so the rule-12 ordering is preserved.
    Route::middleware('module:Parapharmacy')->group(function () {
        // Parapharmacy Master Data - Ingredients
        Route::prefix('parapharmacy/ingredients')->middleware('can:settings.manage')->group(function () {
            Route::get('/', [IngredientController::class, 'index'])->name('parapharmacy.ingredients.index');
            Route::get('/{id}', [IngredientController::class, 'show'])->name('parapharmacy.ingredients.show');
            Route::post('/', [IngredientController::class, 'store'])->name('parapharmacy.ingredients.store');
            Route::patch('/{id}', [IngredientController::class, 'update'])->name('parapharmacy.ingredients.update');
            Route::delete('/{id}', [IngredientController::class, 'destroy'])->name('parapharmacy.ingredients.destroy');
        });

        // Parapharmacy Master Data - Certifications
        Route::prefix('parapharmacy/certifications')->middleware('can:settings.manage')->group(function () {
            Route::get('/', [CertificationController::class, 'index'])->name('parapharmacy.certifications.index');
            Route::get('/{id}', [CertificationController::class, 'show'])->name('parapharmacy.certifications.show');
            Route::post('/', [CertificationController::class, 'store'])->name('parapharmacy.certifications.store');
            Route::patch('/{id}', [CertificationController::class, 'update'])->name('parapharmacy.certifications.update');
            Route::delete('/{id}', [CertificationController::class, 'destroy'])->name('parapharmacy.certifications.destroy');
        });

        // Parapharmacy Master Data - Health Claims
        Route::prefix('parapharmacy/health-claims')->middleware('can:settings.manage')->group(function () {
            Route::get('/', [HealthClaimController::class, 'index'])->name('parapharmacy.health-claims.index');
            Route::get('/{id}', [HealthClaimController::class, 'show'])->name('parapharmacy.health-claims.show');
            Route::post('/', [HealthClaimController::class, 'store'])->name('parapharmacy.health-claims.store');
            Route::patch('/{id}', [HealthClaimController::class, 'update'])->name('parapharmacy.health-claims.update');
            Route::delete('/{id}', [HealthClaimController::class, 'destroy'])->name('parapharmacy.health-claims.destroy');
        });

        // Parapharmacy Master Data - Key Components
        Route::prefix('parapharmacy/key-components')->middleware('can:settings.manage')->group(function () {
            Route::get('/', [KeyComponentController::class, 'index'])->name('parapharmacy.key-components.index');
            Route::get('/{id}', [KeyComponentController::class, 'show'])->name('parapharmacy.key-components.show');
            Route::post('/', [KeyComponentController::class, 'store'])->name('parapharmacy.key-components.store');
            Route::patch('/{id}', [KeyComponentController::class, 'update'])->name('parapharmacy.key-components.update');
            Route::delete('/{id}', [KeyComponentController::class, 'destroy'])->name('parapharmacy.key-components.destroy');
        });
    });

    // Enrichment Review
    Route::middleware('can:enrichment.view')->group(function () {
        Route::get('/enrichment-results', [EnrichmentReviewController::class, 'index'])->name('enrichment.index');
        Route::get('/enrichment-results/{id}', [EnrichmentReviewController::class, 'show'])->name('enrichment.show');
    });
    Route::middleware('can:enrichment.review')->group(function () {
        Route::post('/enrichment-results/{id}/accept', [EnrichmentReviewController::class, 'accept'])->name('enrichment.accept');
        Route::post('/enrichment-results/{id}/reject', [EnrichmentReviewController::class, 'reject'])->name('enrichment.reject');
    });

    // Product Images (authenticated) — façade over media_assets / media_attachments
    Route::prefix('products/{product}/images')->middleware('can:products.view')->group(function () {
        Route::get('/', [ProductMediaController::class, 'index'])
            ->name('products.images.index');

        Route::post('/', [ProductMediaController::class, 'store'])
            ->middleware(['can:products.update', 'throttle:image-upload'])
            ->name('products.images.store');

        Route::patch('/{image}', [ProductMediaController::class, 'update'])
            ->middleware('can:products.update')
            ->name('products.images.update');

        Route::delete('/{image}', [ProductMediaController::class, 'destroy'])
            ->middleware('can:products.update')
            ->name('products.images.destroy');

        Route::get('/{image}/download', [ProductMediaController::class, 'download'])
            ->name('products.images.download');

        Route::post('/reorder', [ProductMediaController::class, 'reorder'])
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
