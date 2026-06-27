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
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('can:expenses.view');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('can:expenses.create');
    Route::get('expenses/{id}', [ExpenseController::class, 'show'])->name('expenses.show');
    Route::match(['put', 'patch'], 'expenses/{id}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    Route::post('expenses/{id}/post', [ExpenseController::class, 'post'])->name('expenses.post');

    // Expense categories
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->middleware('can:expense-categories.view');
    Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('can:expense-categories.create');
    Route::get('expense-categories/{id}', [ExpenseCategoryController::class, 'show'])->name('expense-categories.show');
    Route::match(['put', 'patch'], 'expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
    Route::delete('expense-categories/{id}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
});
