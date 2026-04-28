<?php

declare(strict_types=1);

use App\Modules\Catalog\Presentation\Controllers\CompositeItemController;
use App\Modules\Catalog\Presentation\Controllers\CompositeItemVariantController;
use App\Modules\Catalog\Presentation\Controllers\ModifierController;
use App\Modules\Catalog\Presentation\Controllers\ModifierGroupController;
use App\Modules\Catalog\Presentation\Controllers\RecipeController;
use App\Modules\Catalog\Presentation\Controllers\RecipeLineController;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

// Group 1: Composite items — ungated (no Inventory module required)
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Composite Items
    Route::get('composite-items', [CompositeItemController::class, 'index'])->middleware('can:composite-items.view');
    Route::post('composite-items', [CompositeItemController::class, 'store'])->middleware('can:composite-items.create');
    Route::get('composite-items/{id}', [CompositeItemController::class, 'show'])->middleware('can:composite-items.view');
    Route::patch('composite-items/{id}', [CompositeItemController::class, 'update'])->middleware('can:composite-items.update');
    Route::delete('composite-items/{id}', [CompositeItemController::class, 'destroy'])->middleware('can:composite-items.delete');
    Route::post('composite-items/{id}/duplicate', [CompositeItemController::class, 'duplicate'])->middleware('can:composite-items.create');
    Route::get('composite-items/{id}/availability', [CompositeItemController::class, 'checkAvailability'])->middleware('can:composite-items.view');
});

// Group 2: Recipes, recipe lines, variants, modifier groups — gated behind Inventory module
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, 'module:Inventory'])->group(function () {
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
