<?php

declare(strict_types=1);

use App\Modules\Accounting\Presentation\Controllers\AccountController;
use App\Modules\Accounting\Presentation\Controllers\AccountPurposeController;
use App\Modules\Accounting\Presentation\Controllers\JournalEntryController;
use App\Modules\Accounting\Presentation\Controllers\LedgerController;
use App\Modules\Accounting\Presentation\Controllers\OpeningBalanceBatchController;
use App\Modules\Accounting\Presentation\Controllers\PartnerBalanceController;
use App\Modules\Accounting\Presentation\Controllers\ReportsController;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Accounting Module API Routes
|--------------------------------------------------------------------------
|
| Chart of accounts and journal entry management.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function (): void {
    // Chart of Accounts
    Route::get('/accounts', [AccountController::class, 'index'])
        ->middleware('can:accounts.view')
        ->name('accounts.index');

    Route::get('/accounts/{account}', [AccountController::class, 'show'])
        ->middleware('can:accounts.view')
        ->name('accounts.show');

    Route::post('/accounts', [AccountController::class, 'store'])
        ->middleware('can:accounts.manage')
        ->name('accounts.store');

    Route::patch('/accounts/{account}', [AccountController::class, 'update'])
        ->middleware('can:accounts.manage')
        ->name('accounts.update');

    // Journal Entries
    Route::get('/journal-entries', [JournalEntryController::class, 'index'])
        ->middleware('can:journal.view')
        ->name('journal-entries.index');

    Route::get('/journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])
        ->middleware('can:journal.view')
        ->name('journal-entries.show');

    Route::post('/journal-entries', [JournalEntryController::class, 'store'])
        ->middleware('can:journal.create')
        ->name('journal-entries.store');

    Route::post('/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])
        ->middleware('can:journal.post')
        ->name('journal-entries.post');

    // Account Purposes - Company-scoped
    Route::get('/companies/{companyId}/accounts/purposes', [AccountPurposeController::class, 'index'])
        ->middleware('can:accounts.manage')
        ->name('accounts.purposes.index');

    Route::get('/companies/{companyId}/accounts/purposes/validate', [AccountPurposeController::class, 'validate'])
        ->middleware('can:accounts.manage')
        ->name('accounts.purposes.validate');

    Route::put('/companies/{companyId}/accounts/{accountId}/purpose', [AccountPurposeController::class, 'assignPurpose'])
        ->middleware('can:accounts.manage')
        ->name('accounts.purposes.assign');

    Route::delete('/companies/{companyId}/accounts/{accountId}/purpose', [AccountPurposeController::class, 'removePurpose'])
        ->middleware('can:accounts.manage')
        ->name('accounts.purposes.remove');

    // Partner Balance Routes
    Route::get('/companies/{companyId}/partners/{partnerId}/balance', [PartnerBalanceController::class, 'show'])
        ->middleware('can:accounts.view')
        ->name('partners.balance.show');

    Route::get('/companies/{companyId}/partners/{partnerId}/statement', [PartnerBalanceController::class, 'statement'])
        ->middleware('can:accounts.view')
        ->name('partners.balance.statement');

    Route::post('/companies/{companyId}/partners/{partnerId}/balance/refresh', [PartnerBalanceController::class, 'refresh'])
        ->middleware('can:accounts.manage')
        ->name('partners.balance.refresh');

    Route::post('/companies/{companyId}/partners/balance/refresh-all', [PartnerBalanceController::class, 'refreshAll'])
        ->middleware('can:accounts.manage')
        ->name('partners.balance.refresh-all');

    // Subledger Reports
    Route::get('/companies/{companyId}/subledger/receivables', [PartnerBalanceController::class, 'receivables'])
        ->middleware('can:accounts.view')
        ->name('subledger.receivables');

    Route::get('/companies/{companyId}/subledger/payables', [PartnerBalanceController::class, 'payables'])
        ->middleware('can:accounts.view')
        ->name('subledger.payables');

    Route::get('/companies/{companyId}/subledger/reconcile/{purpose}', [PartnerBalanceController::class, 'reconcile'])
        ->middleware('can:accounts.manage')
        ->name('subledger.reconcile');

    // Opening Balance Batches
    Route::get('/opening-batches/types', [OpeningBalanceBatchController::class, 'types'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.types');

    Route::get('/companies/{companyId}/opening-batches', [OpeningBalanceBatchController::class, 'index'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.index');

    Route::get('/companies/{companyId}/opening-batches/status', [OpeningBalanceBatchController::class, 'status'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.status');

    Route::post('/companies/{companyId}/opening-batches', [OpeningBalanceBatchController::class, 'store'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.store');

    Route::get('/companies/{companyId}/opening-batches/{batchId}', [OpeningBalanceBatchController::class, 'show'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.show');

    Route::delete('/companies/{companyId}/opening-batches/{batchId}', [OpeningBalanceBatchController::class, 'destroy'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.destroy');

    Route::get('/companies/{companyId}/opening-batches/{batchId}/rows', [OpeningBalanceBatchController::class, 'rows'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.rows');

    Route::post('/companies/{companyId}/opening-batches/{batchId}/lock', [OpeningBalanceBatchController::class, 'lock'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.lock');

    Route::post('/companies/{companyId}/opening-batches/{batchId}/import', [OpeningBalanceBatchController::class, 'import'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.import');

    Route::post('/companies/{companyId}/opening-batches/{batchId}/validate', [OpeningBalanceBatchController::class, 'validateBatch'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.validate');

    Route::get('/companies/{companyId}/opening-batches/{batchId}/preview', [OpeningBalanceBatchController::class, 'preview'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.preview');

    Route::post('/companies/{companyId}/opening-batches/{batchId}/post', [OpeningBalanceBatchController::class, 'post'])
        ->middleware('can:accounts.manage')
        ->name('opening-batches.post');

    // General Ledger
    Route::get('/ledger', [LedgerController::class, 'index'])
        ->middleware('can:ledger.view')
        ->name('ledger.index');

    // Financial Reports
    Route::get('/reports/trial-balance', [ReportsController::class, 'trialBalance'])
        ->middleware('can:reports.view')
        ->name('reports.trial-balance');

    Route::get('/reports/profit-loss', [ReportsController::class, 'profitLoss'])
        ->middleware('can:reports.view')
        ->name('reports.profit-loss');

    Route::get('/reports/balance-sheet', [ReportsController::class, 'balanceSheet'])
        ->middleware('can:reports.view')
        ->name('reports.balance-sheet');
});
