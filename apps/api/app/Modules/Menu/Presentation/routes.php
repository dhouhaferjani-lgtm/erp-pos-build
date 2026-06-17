<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Menu\Presentation\Controllers\ActiveMenuController;
use App\Modules\Menu\Presentation\Controllers\MenuCategoryController;
use App\Modules\Menu\Presentation\Controllers\MenuController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, 'module:Menu'])->group(function () {
    // Menus CRUD
    Route::get('menus', [MenuController::class, 'index']);
    Route::post('menus', [MenuController::class, 'store']);
    Route::get('menus/{id}', [MenuController::class, 'show']);
    Route::patch('menus/{id}', [MenuController::class, 'update']);
    Route::delete('menus/{id}', [MenuController::class, 'destroy']);

    // Menu Categories (nested under menus for creation, standalone for update/delete)
    Route::post('menus/{menuId}/categories', [MenuCategoryController::class, 'store']);
    Route::patch('menu-categories/{id}', [MenuCategoryController::class, 'update']);
    Route::delete('menu-categories/{id}', [MenuCategoryController::class, 'destroy']);

    // Menu Category Items
    Route::put('menu-categories/{id}/items', [MenuCategoryController::class, 'syncItems']);
    Route::post('menu-categories/{id}/items', [MenuCategoryController::class, 'addItem']);
    Route::delete('menu-categories/{categoryId}/items/{itemId}', [MenuCategoryController::class, 'removeItem']);

    // Active Menu (POS-facing: resolve current menu)
    Route::get('active-menu', ActiveMenuController::class);
});
