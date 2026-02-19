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

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Composite Items
    Route::get('composite-items', [CompositeItemController::class, 'index']);
    Route::post('composite-items', [CompositeItemController::class, 'store']);
    Route::get('composite-items/{id}', [CompositeItemController::class, 'show']);
    Route::patch('composite-items/{id}', [CompositeItemController::class, 'update']);
    Route::delete('composite-items/{id}', [CompositeItemController::class, 'destroy']);
    Route::post('composite-items/{id}/duplicate', [CompositeItemController::class, 'duplicate']);

    // Recipes (nested under composite items for creation, standalone for show/update)
    Route::get('composite-items/{compositeItemId}/recipes', [RecipeController::class, 'index']);
    Route::post('composite-items/{compositeItemId}/recipes', [RecipeController::class, 'store']);
    Route::get('recipes/{id}', [RecipeController::class, 'show']);
    Route::patch('recipes/{id}', [RecipeController::class, 'update']);
    Route::post('recipes/{id}/activate', [RecipeController::class, 'activate']);
    Route::post('recipes/{id}/calculate-cost', [RecipeController::class, 'calculateCost']);

    // Recipe Lines (nested under recipes)
    Route::post('recipes/{recipeId}/lines', [RecipeLineController::class, 'store']);
    Route::patch('recipes/{recipeId}/lines/{lineId}', [RecipeLineController::class, 'update']);
    Route::delete('recipes/{recipeId}/lines/{lineId}', [RecipeLineController::class, 'destroy']);

    // Composite Item Variants (nested under composite items for listing/creation)
    Route::get('composite-items/{compositeItemId}/variants', [CompositeItemVariantController::class, 'index']);
    Route::post('composite-items/{compositeItemId}/variants', [CompositeItemVariantController::class, 'store']);
    Route::patch('variants/{id}', [CompositeItemVariantController::class, 'update']);
    Route::delete('variants/{id}', [CompositeItemVariantController::class, 'destroy']);

    // Modifier Group assignment to composite items
    Route::post('composite-items/{compositeItemId}/modifier-groups', [ModifierGroupController::class, 'assignToItem']);
    Route::delete('composite-items/{compositeItemId}/modifier-groups/{modifierGroupId}', [ModifierGroupController::class, 'removeFromItem']);

    // Modifier Groups (standalone CRUD)
    Route::get('modifier-groups', [ModifierGroupController::class, 'index']);
    Route::post('modifier-groups', [ModifierGroupController::class, 'store']);
    Route::get('modifier-groups/{id}', [ModifierGroupController::class, 'show']);
    Route::patch('modifier-groups/{id}', [ModifierGroupController::class, 'update']);
    Route::delete('modifier-groups/{id}', [ModifierGroupController::class, 'destroy']);

    // Modifiers (nested under groups for creation)
    Route::post('modifier-groups/{groupId}/modifiers', [ModifierController::class, 'store']);
    Route::patch('modifiers/{id}', [ModifierController::class, 'update']);
    Route::delete('modifiers/{id}', [ModifierController::class, 'destroy']);
});
