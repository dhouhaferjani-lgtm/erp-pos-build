<?php

declare(strict_types=1);

use App\Modules\Catalog\Presentation\Controllers\AttributeController;
use App\Modules\Catalog\Presentation\Controllers\CompositeItemController;
use App\Modules\Catalog\Presentation\Controllers\CompositeItemVariantController;
use App\Modules\Catalog\Presentation\Controllers\ModifierController;
use App\Modules\Catalog\Presentation\Controllers\ModifierGroupController;
use App\Modules\Catalog\Presentation\Controllers\ProductVariantController;
use App\Modules\Catalog\Presentation\Controllers\RecipeController;
use App\Modules\Catalog\Presentation\Controllers\RecipeLineController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

// Group 1: Composite items + menu modifiers — gated behind the CompositeItems module.
// Defining sellable composite items (dishes/combos/bundles) and their menu
// modifiers is a CompositeItems concern (a default module for restaurant /
// coffee_shop). It does NOT require inventory tracking — composites can be built
// and sold with no stock ledger. Ingredient recipes (which drive stock
// depletion/costing) live in the Inventory-gated group below. This mirrors the
// industry split (e.g. Lightspeed/MarketMan author recipes inside the inventory
// capability, while item + modifier definition is base catalog/menu).
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:CompositeItems'])->group(function () {
    // Composite Items
    Route::get('composite-items', [CompositeItemController::class, 'index'])->middleware('can:composite-items.view');
    Route::post('composite-items', [CompositeItemController::class, 'store'])->middleware('can:composite-items.create');
    Route::get('composite-items/{id}', [CompositeItemController::class, 'show'])->middleware('can:composite-items.view');
    Route::patch('composite-items/{id}', [CompositeItemController::class, 'update'])->middleware('can:composite-items.update');
    Route::delete('composite-items/{id}', [CompositeItemController::class, 'destroy'])->middleware('can:composite-items.delete');
    Route::post('composite-items/{id}/duplicate', [CompositeItemController::class, 'duplicate'])->middleware('can:composite-items.create');
    Route::get('composite-items/{id}/availability', [CompositeItemController::class, 'checkAvailability'])->middleware('can:composite-items.view');

    // Modifier Group assignment to composite items
    Route::post('composite-items/{compositeItemId}/modifier-groups', [ModifierGroupController::class, 'assignToItem'])->middleware('can:composite-items.update');
    Route::delete('composite-items/{compositeItemId}/modifier-groups/{modifierGroupId}', [ModifierGroupController::class, 'removeFromItem'])->middleware('can:composite-items.update');

    // Modifier Groups (standalone CRUD)
    Route::get('modifier-groups', [ModifierGroupController::class, 'index'])->middleware('can:modifier-groups.view');
    Route::post('modifier-groups', [ModifierGroupController::class, 'store'])->middleware('can:modifier-groups.manage');
    Route::get('modifier-groups/{id}', [ModifierGroupController::class, 'show'])->middleware('can:modifier-groups.view');
    Route::patch('modifier-groups/{id}', [ModifierGroupController::class, 'update'])->middleware('can:modifier-groups.manage');
    Route::delete('modifier-groups/{id}', [ModifierGroupController::class, 'destroy'])->middleware('can:modifier-groups.manage');

    // Modifiers (nested under groups for creation)
    Route::post('modifier-groups/{groupId}/modifiers', [ModifierController::class, 'store'])->middleware('can:modifier-groups.manage');
    Route::patch('modifiers/{id}', [ModifierController::class, 'update'])->middleware('can:modifier-groups.manage');
    Route::delete('modifiers/{id}', [ModifierController::class, 'destroy'])->middleware('can:modifier-groups.manage');
});

// Group 1b: Product attributes + product variants (T2) — ungated catalog configuration.
// Variant matrix definition is a catalog concern; it does not require the Inventory
// module to be enabled. cost_override on variants is advisory only (spec §6.7) — these
// endpoints never feed the inventory WAC pipeline.
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Product Attributes
    Route::get('product-attributes', [AttributeController::class, 'index'])->middleware('can:catalog.attributes.view');
    Route::post('product-attributes', [AttributeController::class, 'store'])->middleware('can:catalog.attributes.create');
    Route::delete('product-attributes/{attributeId}', [AttributeController::class, 'destroy'])->middleware('can:catalog.attributes.delete');

    // Attribute Values (nested under attributes)
    Route::get('product-attributes/{attributeId}/values', [AttributeController::class, 'indexValues'])->middleware('can:catalog.attributes.view');
    Route::post('product-attributes/{attributeId}/values', [AttributeController::class, 'storeValue'])->middleware('can:catalog.attributes.update');

    // Product Variants (nested under products for listing/creation/matrix generation)
    Route::get('products/{productId}/variants', [ProductVariantController::class, 'index'])->middleware('can:catalog.variants.view');
    Route::post('products/{productId}/variants', [ProductVariantController::class, 'store'])->middleware('can:catalog.variants.create');
    Route::post('products/{productId}/variants/generate-matrix', [ProductVariantController::class, 'generateMatrix'])->middleware('can:catalog.variants.create');

    // Product Variants (standalone update/delete by variant id).
    // NOTE: distinct `product-variants/` prefix to avoid colliding with the
    // CompositeItemVariantController `variants/{id}` routes in Group 2.
    Route::patch('product-variants/{id}', [ProductVariantController::class, 'update'])->middleware('can:catalog.variants.update');
    Route::delete('product-variants/{id}', [ProductVariantController::class, 'destroy'])->middleware('can:catalog.variants.delete');
});

// Group 2: Recipes, recipe lines, composite-item variants — gated behind the Inventory module.
// Ingredient recipes (bills of materials) drive stock depletion and costing, so
// they require the Inventory module (the stock-tracking upgrade). This matches the
// industry standard: recipe authoring/depletion is part of the inventory
// capability (Toast requires a recipe + recorded stock baseline to deplete;
// Lightspeed/MarketMan author recipes inside the inventory product).
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Inventory'])->group(function () {
    // Recipes (nested under composite items for creation, standalone for show/update)
    Route::get('composite-items/{compositeItemId}/recipes', [RecipeController::class, 'index'])->middleware('can:composite-items.manage-recipes');
    Route::post('composite-items/{compositeItemId}/recipes', [RecipeController::class, 'store'])->middleware('can:composite-items.manage-recipes');
    Route::get('recipes/{id}', [RecipeController::class, 'show'])->middleware('can:composite-items.manage-recipes');
    Route::patch('recipes/{id}', [RecipeController::class, 'update'])->middleware('can:composite-items.manage-recipes');
    Route::post('recipes/{id}/activate', [RecipeController::class, 'activate'])->middleware('can:composite-items.manage-recipes');
    Route::post('recipes/{id}/calculate-cost', [RecipeController::class, 'calculateCost'])->middleware('can:composite-items.manage-recipes');

    // Recipe Lines (nested under recipes)
    Route::post('recipes/{recipeId}/lines', [RecipeLineController::class, 'store'])->middleware('can:composite-items.manage-recipes');
    Route::patch('recipes/{recipeId}/lines/{lineId}', [RecipeLineController::class, 'update'])->middleware('can:composite-items.manage-recipes');
    Route::delete('recipes/{recipeId}/lines/{lineId}', [RecipeLineController::class, 'destroy'])->middleware('can:composite-items.manage-recipes');

    // Composite Item Variants (nested under composite items for listing/creation)
    Route::get('composite-items/{compositeItemId}/variants', [CompositeItemVariantController::class, 'index'])->middleware('can:composite-items.update');
    Route::post('composite-items/{compositeItemId}/variants', [CompositeItemVariantController::class, 'store'])->middleware('can:composite-items.update');
    Route::patch('variants/{id}', [CompositeItemVariantController::class, 'update'])->middleware('can:composite-items.update');
    Route::delete('variants/{id}', [CompositeItemVariantController::class, 'destroy'])->middleware('can:composite-items.update');
});
