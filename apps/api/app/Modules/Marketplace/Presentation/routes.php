<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Marketplace\Presentation\Controllers\MarketplaceListingController;
use App\Modules\Marketplace\Presentation\Controllers\MarketplaceOrderController;
use App\Modules\Marketplace\Presentation\Controllers\MarketplaceSellerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketplace kill-switch — `config('marketplace.enabled')`, default FALSE
|--------------------------------------------------------------------------
|
| Full rationale (why the flag exists, what it closes, and why no
| `module:Marketplace` middleware gate is added) lives on
| App\Modules\Marketplace\Providers\MarketplaceServiceProvider::boot().
|
| The guard is repeated HERE — not only around that provider's
| `loadRoutesFrom()` — because this file is ALSO `include`d as a side effect of
| spatie/laravel-event-sourcing's projector auto-discovery: config/event-sourcing.php
| points `auto_discover_projectors_and_reactors` at `app()->path()`, and
| DiscoverEventHandlers::addToProjectionist() maps EVERY file under it to a PSR-4
| class name and calls `is_subclass_of()` on it, which makes Composer's autoloader
| `include` this routes file. Verified 2026-08-05 by a backtrace taken from inside
| this file: ClassLoader::loadClass <- is_subclass_of <- DiscoverEventHandlers:67
| <- EventSourcingServiceProvider::packageBooted. That include registers the routes
| no matter what the module's own service provider decides, so a provider-side
| condition alone is NOT a gate. (Same reason the Cart module's
| marketplace-checkout route is guarded inline.)
|
| SCOPE OF THAT TRAP — it is autoloader-dependent, do not generalise it to prod:
| the discovery probe can only `include` a class-less routes file through
| Composer's PSR-4 FALLBACK, i.e. under a non-authoritative autoloader (local
| dev, CI, `composer install` without --classmap-authoritative). The production
| image is built `composer dump-autoload --optimize --classmap-authoritative`
| (apps/api/Dockerfile), and vendor/composer/ClassLoader::findFile() returns
| false immediately when classMapAuthoritative is set — a routes file declares
| no class, so it is not in the classmap and the fallback never runs. In the
| production image this file is therefore NEVER included by discovery and the
| provider condition IS the gate; in dev/CI this early return is the gate.
| Both guards are required. Whoever writes the CI guard for this pattern must
| model both worlds.
|
| Once past this guard, access is governed by the per-route `can:` permissions.
*/
if (! (bool) config('marketplace.enabled', false)) {
    return;
}

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
