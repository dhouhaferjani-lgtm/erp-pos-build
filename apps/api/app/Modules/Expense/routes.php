<?php

declare(strict_types=1);

use App\Modules\Expense\Presentation\Controllers\ExpenseCategoryController;
use App\Modules\Expense\Presentation\Controllers\ExpenseController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Expense Module API Routes
|--------------------------------------------------------------------------
|
| Here are the routes for expense management including expense documents
| and expense categories.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function () {
    // Expense documents
    Route::apiResource('expenses', ExpenseController::class);
    Route::post('expenses/{id}/post', [ExpenseController::class, 'post'])->name('expenses.post');

    // Expense categories
    Route::apiResource('expense-categories', ExpenseCategoryController::class);
});
