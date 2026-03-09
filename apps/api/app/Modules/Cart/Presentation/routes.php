<?php

declare(strict_types=1);

use App\Modules\Cart\Presentation\Controllers\CatalogCartController;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/catalog-carts')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])
    ->group(function () {
        Route::get('/', [CatalogCartController::class, 'index'])
            ->middleware('can:catalog_cart.view')
            ->name('catalog-carts.index');

        Route::post('/', [CatalogCartController::class, 'store'])
            ->middleware('can:catalog_cart.create')
            ->name('catalog-carts.store');

        Route::get('{id}', [CatalogCartController::class, 'show'])
            ->middleware('can:catalog_cart.view')
            ->name('catalog-carts.show');

        Route::patch('{id}', [CatalogCartController::class, 'update'])
            ->middleware('can:catalog_cart.create')
            ->name('catalog-carts.update');

        Route::delete('{id}', [CatalogCartController::class, 'destroy'])
            ->middleware('can:catalog_cart.create')
            ->name('catalog-carts.destroy');

        // Cart items
        Route::post('{id}/items', [CatalogCartController::class, 'addItem'])
            ->middleware('can:catalog_cart.create')
            ->name('catalog-carts.items.store');

        Route::patch('{id}/items/{itemId}', [CatalogCartController::class, 'updateItem'])
            ->middleware('can:catalog_cart.create')
            ->name('catalog-carts.items.update');

        Route::delete('{id}/items/{itemId}', [CatalogCartController::class, 'removeItem'])
            ->middleware('can:catalog_cart.create')
            ->name('catalog-carts.items.destroy');

        // Conversion
        Route::post('{id}/convert', [CatalogCartController::class, 'convert'])
            ->middleware('can:catalog_cart.convert_po')
            ->name('catalog-carts.convert');

        // Marketplace checkout
        Route::post('{id}/marketplace-checkout', [CatalogCartController::class, 'marketplaceCheckout'])
            ->middleware('can:catalog_cart.marketplace_checkout')
            ->name('catalog-carts.marketplace-checkout');
    });
