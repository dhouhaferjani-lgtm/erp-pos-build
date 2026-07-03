<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Income\Presentation\Controllers\IncomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Income Module API Routes
|--------------------------------------------------------------------------
|
| Routes for recording business income (money received into cash/bank
| repositories against class-7 revenue accounts).
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function (): void {
    Route::get('income', [IncomeController::class, 'index'])->middleware('can:income.view')->name('income.index');
    Route::post('income', [IncomeController::class, 'store'])->middleware('can:income.create')->name('income.store');
    Route::get('income/{id}', [IncomeController::class, 'show'])->middleware('can:income.view')->name('income.show');
    Route::match(['put', 'patch'], 'income/{id}', [IncomeController::class, 'update'])->middleware('can:income.update')->name('income.update');
    Route::delete('income/{id}', [IncomeController::class, 'destroy'])->middleware('can:income.delete')->name('income.destroy');
    Route::post('income/{id}/post', [IncomeController::class, 'post'])->middleware('can:income.post')->name('income.post');
});
