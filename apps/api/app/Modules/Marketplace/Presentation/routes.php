<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Marketplace\Presentation\Controllers\MarketplaceListingController;
use App\Modules\Marketplace\Presentation\Controllers\MarketplaceOrderController;
use App\Modules\Marketplace\Presentation\Controllers\MarketplaceSellerController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/marketplace')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(function () {
        // Listings (browse)
        Route::get('listings', [MarketplaceListingController::class, 'index'])
            ->middleware('can:marketplace.browse')
            ->name('marketplace.listings.index');

        Route::get('listings/{id}', [MarketplaceListingController::class, 'show'])
            ->middleware('can:marketplace.browse')
            ->name('marketplace.listings.show');

        Route::get('price-comparison', [MarketplaceListingController::class, 'priceComparison'])
            ->middleware('can:marketplace.browse')
            ->name('marketplace.price-comparison');

        // Orders
        Route::post('orders', [MarketplaceOrderController::class, 'store'])
            ->middleware('can:marketplace.order')
            ->name('marketplace.orders.store');

        Route::get('orders', [MarketplaceOrderController::class, 'index'])
            ->middleware('can:marketplace.view_orders')
            ->name('marketplace.orders.index');

        Route::get('orders/{id}', [MarketplaceOrderController::class, 'show'])
            ->middleware('can:marketplace.view_orders')
            ->name('marketplace.orders.show');

        Route::post('orders/{id}/cancel', [MarketplaceOrderController::class, 'cancel'])
            ->middleware('can:marketplace.order')
            ->name('marketplace.orders.cancel');
    });

// Admin seller management
Route::prefix('api/v1/admin/marketplace')
    ->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])
    ->group(function () {
        Route::get('sellers', [MarketplaceSellerController::class, 'index'])
            ->middleware('can:marketplace.admin')
            ->name('marketplace.admin.sellers.index');

        Route::post('sellers', [MarketplaceSellerController::class, 'store'])
            ->middleware('can:marketplace.admin')
            ->name('marketplace.admin.sellers.store');

        Route::patch('sellers/{id}', [MarketplaceSellerController::class, 'update'])
            ->middleware('can:marketplace.admin')
            ->name('marketplace.admin.sellers.update');

        Route::post('sellers/{id}/suspend', [MarketplaceSellerController::class, 'suspend'])
            ->middleware('can:marketplace.admin')
            ->name('marketplace.admin.sellers.suspend');
    });
