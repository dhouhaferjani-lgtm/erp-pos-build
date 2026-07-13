<?php

declare(strict_types=1);

use App\Modules\Expense\Presentation\Controllers\ExpenseAnalyticsController;
use App\Modules\Expense\Presentation\Controllers\ExpenseCategoryController;
use App\Modules\Expense\Presentation\Controllers\ExpenseController;
use App\Modules\Expense\Presentation\Controllers\ExpenseRecurrenceController;
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
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('can:expenses.view')->name('expenses.index');
    Route::get('expenses/linkable-invoices', [ExpenseController::class, 'linkableInvoices'])->middleware('can:expenses.create')->name('expenses.linkable-invoices');
    Route::get('expenses/linkable-operations', [ExpenseController::class, 'linkableOperations'])->middleware('can:expenses.create')->name('expenses.linkable-operations');
    Route::get('expenses/analytics', ExpenseAnalyticsController::class)->middleware('can:expenses.view')->name('expenses.analytics');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('can:expenses.create')->name('expenses.store');
    Route::get('expenses/{id}', [ExpenseController::class, 'show'])->name('expenses.show');
    Route::match(['put', 'patch'], 'expenses/{id}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    Route::post('expenses/{id}/post', [ExpenseController::class, 'post'])->name('expenses.post');
    Route::post('expenses/{id}/pay', [ExpenseController::class, 'pay'])->middleware('can:expenses.pay')->name('expenses.pay');
    Route::post('expenses/{id}/reverse', [ExpenseController::class, 'reverse'])->name('expenses.reverse');

    // Recurring expense templates
    Route::get('expense-recurrences', [ExpenseRecurrenceController::class, 'index'])->middleware('can:expense-recurrences.view')->name('expense-recurrences.index');
    Route::post('expense-recurrences', [ExpenseRecurrenceController::class, 'store'])->middleware('can:expense-recurrences.create')->name('expense-recurrences.store');
    Route::get('expense-recurrences/{id}', [ExpenseRecurrenceController::class, 'show'])->middleware('can:expense-recurrences.view')->name('expense-recurrences.show');
    Route::put('expense-recurrences/{id}', [ExpenseRecurrenceController::class, 'update'])->middleware('can:expense-recurrences.update')->name('expense-recurrences.update');
    Route::delete('expense-recurrences/{id}', [ExpenseRecurrenceController::class, 'destroy'])->middleware('can:expense-recurrences.delete')->name('expense-recurrences.destroy');
    Route::post('expense-recurrences/{id}/pause', [ExpenseRecurrenceController::class, 'pause'])->middleware('can:expense-recurrences.update')->name('expense-recurrences.pause');
    Route::post('expense-recurrences/{id}/resume', [ExpenseRecurrenceController::class, 'resume'])->middleware('can:expense-recurrences.update')->name('expense-recurrences.resume');

    // Expense categories
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->middleware('can:expense-categories.view')->name('expense-categories.index');
    Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('can:expense-categories.create')->name('expense-categories.store');
    Route::get('expense-categories/{id}', [ExpenseCategoryController::class, 'show'])->name('expense-categories.show');
    Route::match(['put', 'patch'], 'expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
    Route::delete('expense-categories/{id}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
});
