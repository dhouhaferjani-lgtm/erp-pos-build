<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Pricing\Presentation\Controllers\PricingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pricing Module API Routes
|--------------------------------------------------------------------------
|
| Price lists, pricing rules, discounts, and partner-specific pricing.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function (): void {
    // Price Lists
    Route::get('/price-lists', [PricingController::class, 'index'])
        ->middleware('can:pricing.view')
        ->name('price-lists.index');

    Route::get('/price-lists/{priceList}', [PricingController::class, 'show'])
        ->middleware('can:pricing.view')
        ->name('price-lists.show');

    Route::post('/price-lists', [PricingController::class, 'store'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.store');

    Route::patch('/price-lists/{priceList}', [PricingController::class, 'update'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.update');

    Route::delete('/price-lists/{priceList}', [PricingController::class, 'destroy'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.destroy');

    // Price List Items
    Route::post('/price-lists/{priceList}/items', [PricingController::class, 'addItem'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.items.store');

    Route::patch('/price-lists/{priceList}/items/{item}', [PricingController::class, 'updateItem'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.items.update');

    Route::delete('/price-lists/{priceList}/items/{item}', [PricingController::class, 'removeItem'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.items.destroy');

    // Partner Price List Assignments
    Route::post('/price-lists/{priceList}/partners', [PricingController::class, 'assignToPartner'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.partners.store');

    Route::delete('/price-lists/{priceList}/partners/{partner}', [PricingController::class, 'removeFromPartner'])
        ->middleware('can:pricing.manage')
        ->name('price-lists.partners.destroy');

    // Pricing Operations
    Route::post('/pricing/get-price', [PricingController::class, 'getPrice'])
        ->middleware('can:pricing.view')
        ->name('pricing.get-price');

    Route::post('/pricing/quantity-breaks', [PricingController::class, 'getQuantityBreaks'])
        ->middleware('can:pricing.view')
        ->name('pricing.quantity-breaks');

    Route::post('/pricing/calculate-line', [PricingController::class, 'calculateLineTotal'])
        ->middleware('can:pricing.view')
        ->name('pricing.calculate-line');

    Route::post('/pricing/bulk-prices', [PricingController::class, 'getBulkPrices'])
        ->middleware('can:pricing.view')
        ->name('pricing.bulk-prices');

    // Margin Check
    Route::post('/pricing/check-margin', [PricingController::class, 'checkMargin'])
        ->middleware('can:pricing.view')
        ->name('pricing.check-margin');
});
