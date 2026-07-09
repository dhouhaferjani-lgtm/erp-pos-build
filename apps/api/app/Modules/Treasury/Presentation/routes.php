<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Treasury\Presentation\Controllers\BankReconciliationController;
use App\Modules\Treasury\Presentation\Controllers\MultiPaymentController;
use App\Modules\Treasury\Presentation\Controllers\PaymentController;
use App\Modules\Treasury\Presentation\Controllers\PaymentInstrumentController;
use App\Modules\Treasury\Presentation\Controllers\PaymentMethodController;
use App\Modules\Treasury\Presentation\Controllers\PaymentRefundController;
use App\Modules\Treasury\Presentation\Controllers\PaymentRepositoryController;
use App\Modules\Treasury\Presentation\Controllers\RepositoryAdjustmentController;
use App\Modules\Treasury\Presentation\Controllers\SmartPaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Treasury Module API Routes
|--------------------------------------------------------------------------
|
| Payment methods, repositories, instruments, and payment operations.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function (): void {
    // Payment Methods
    Route::get('/payment-methods', [PaymentMethodController::class, 'index'])
        ->middleware('can:treasury.view')
        ->name('payment-methods.index');

    Route::get('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'show'])
        ->middleware('can:treasury.view')
        ->name('payment-methods.show');

    Route::post('/payment-methods', [PaymentMethodController::class, 'store'])
        ->middleware('can:treasury.manage')
        ->name('payment-methods.store');

    Route::patch('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])
        ->middleware('can:treasury.manage')
        ->name('payment-methods.update');

    // Payment Repositories
    Route::get('/payment-repositories', [PaymentRepositoryController::class, 'index'])
        ->middleware('can:repositories.view')
        ->name('payment-repositories.index');

    Route::get('/payment-repositories/{repository}', [PaymentRepositoryController::class, 'show'])
        ->middleware('can:repositories.view')
        ->name('payment-repositories.show');

    Route::get('/payment-repositories/{repository}/balance', [PaymentRepositoryController::class, 'balance'])
        ->middleware('can:repositories.view')
        ->name('payment-repositories.balance');

    Route::get('/payment-repositories/{repository}/transactions', [PaymentRepositoryController::class, 'transactions'])
        ->middleware('can:repositories.view')
        ->name('payment-repositories.transactions');

    Route::post('/payment-repositories', [PaymentRepositoryController::class, 'store'])
        ->middleware('can:repositories.manage')
        ->name('payment-repositories.store');

    Route::patch('/payment-repositories/{repository}', [PaymentRepositoryController::class, 'update'])
        ->middleware('can:repositories.manage')
        ->name('payment-repositories.update');

    // Gated manual repository (cash) adjustment — count-variance / correction
    // (Treasury spine Task 23).
    Route::post('/payment-repositories/{repository}/adjustments', [RepositoryAdjustmentController::class, 'store'])
        ->middleware('can:treasury.adjust')
        ->name('payment-repositories.adjustments.store');

    // Payment Instruments
    Route::get('/payment-instruments', [PaymentInstrumentController::class, 'index'])
        ->middleware('can:instruments.view')
        ->name('payment-instruments.index');

    Route::get('/payment-instruments/{instrument}', [PaymentInstrumentController::class, 'show'])
        ->middleware('can:instruments.view')
        ->name('payment-instruments.show');

    Route::post('/payment-instruments', [PaymentInstrumentController::class, 'store'])
        ->middleware('can:instruments.create')
        ->name('payment-instruments.store');

    Route::post('/payment-instruments/{instrument}/deposit', [PaymentInstrumentController::class, 'deposit'])
        ->middleware('can:instruments.transfer')
        ->name('payment-instruments.deposit');

    Route::post('/payment-instruments/{instrument}/clear', [PaymentInstrumentController::class, 'clear'])
        ->middleware('can:instruments.clear')
        ->name('payment-instruments.clear');

    Route::post('/payment-instruments/{instrument}/bounce', [PaymentInstrumentController::class, 'bounce'])
        ->middleware('can:instruments.clear')
        ->name('payment-instruments.bounce');

    Route::post('/payment-instruments/{instrument}/transfer', [PaymentInstrumentController::class, 'transfer'])
        ->middleware('can:instruments.transfer')
        ->name('payment-instruments.transfer');

    // Payments
    Route::get('/payments', [PaymentController::class, 'index'])
        ->middleware('can:payments.view')
        ->name('payments.index');

    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->middleware('can:payments.view')
        ->name('payments.show');

    Route::post('/payments', [PaymentController::class, 'store'])
        ->middleware('can:payments.create')
        ->name('payments.store');

    // Payment Refunds
    Route::post('/payments/{payment}/refund', [PaymentRefundController::class, 'refundPayment'])
        ->middleware('can:payments.refund')
        ->name('payments.refund');

    Route::post('/payments/{payment}/partial-refund', [PaymentRefundController::class, 'partialRefund'])
        ->middleware('can:payments.refund')
        ->name('payments.partial-refund');

    Route::post('/payments/{payment}/reverse', [PaymentRefundController::class, 'reversePayment'])
        ->middleware('can:payments.reverse')
        ->name('payments.reverse');

    Route::get('/payments/{payment}/can-refund', [PaymentRefundController::class, 'checkRefundable'])
        ->middleware('can:payments.view')
        ->name('payments.check-refundable');

    Route::get('/payments/{payment}/refund-history', [PaymentRefundController::class, 'getRefundHistory'])
        ->middleware('can:payments.view')
        ->name('payments.refund-history');

    // Document Prepayment Refund
    Route::post('/documents/{document}/refund-prepayment', [PaymentRefundController::class, 'refundPrepayment'])
        ->middleware('can:payments.void')
        ->name('documents.refund-prepayment');

    // Multi-Payment Operations
    Route::post('/documents/{document}/split-payment', [MultiPaymentController::class, 'createSplitPayment'])
        ->middleware('can:payments.create')
        ->name('documents.split-payment');

    Route::post('/payments/deposit', [MultiPaymentController::class, 'recordDeposit'])
        ->middleware('can:payments.create')
        ->name('payments.deposit');

    Route::post('/payments/{payment}/apply-deposit', [MultiPaymentController::class, 'applyDeposit'])
        ->middleware('can:payments.create')
        ->name('payments.apply-deposit');

    Route::get('/partners/{partner}/unallocated-balance/{currency}', [MultiPaymentController::class, 'getUnallocatedBalance'])
        ->middleware('can:payments.view')
        ->name('partners.unallocated-balance');

    Route::post('/payments/on-account', [MultiPaymentController::class, 'recordPaymentOnAccount'])
        ->middleware('can:payments.create')
        ->name('payments.on-account');

    Route::get('/partners/{partner}/account-balance/{currency}', [MultiPaymentController::class, 'getPartnerAccountBalance'])
        ->middleware('can:payments.view')
        ->name('partners.account-balance');

    Route::post('/payments/validate-split', [MultiPaymentController::class, 'validateSplit'])
        ->middleware('can:payments.view')
        ->name('payments.validate-split');

    // Smart Payment Features
    Route::get('/smart-payment/tolerance-settings', [SmartPaymentController::class, 'getToleranceSettings'])
        ->middleware('can:payments.view')
        ->name('smart-payment.tolerance-settings');

    Route::post('/smart-payment/preview-allocation', [SmartPaymentController::class, 'previewAllocation'])
        ->middleware('can:payments.view')
        ->name('smart-payment.preview-allocation');

    Route::post('/smart-payment/apply-allocation', [SmartPaymentController::class, 'applyAllocation'])
        ->middleware('can:payments.create')
        ->name('smart-payment.apply-allocation');

    // Open invoices for a partner (used by payment form)
    Route::get('/partners/{partner}/open-invoices', [SmartPaymentController::class, 'getOpenInvoices'])
        ->middleware('can:payments.view')
        ->name('partners.open-invoices');

    // Bank Reconciliation
    Route::get('/bank-reconciliations', [BankReconciliationController::class, 'index'])
        ->middleware('can:repositories.view')
        ->name('bank-reconciliations.index');

    Route::get('/bank-reconciliations/{reconciliation}', [BankReconciliationController::class, 'show'])
        ->middleware('can:repositories.view')
        ->name('bank-reconciliations.show');

    Route::get('/bank-reconciliations/{reconciliation}/summary', [BankReconciliationController::class, 'summary'])
        ->middleware('can:repositories.view')
        ->name('bank-reconciliations.summary');

    Route::post('/bank-reconciliations', [BankReconciliationController::class, 'store'])
        ->middleware('can:repositories.manage')
        ->name('bank-reconciliations.store');

    Route::post('/bank-reconciliations/{reconciliation}/match/{payment}', [BankReconciliationController::class, 'matchItem'])
        ->middleware('can:repositories.manage')
        ->name('bank-reconciliations.match');

    Route::post('/bank-reconciliations/{reconciliation}/unmatch/{payment}', [BankReconciliationController::class, 'unmatchItem'])
        ->middleware('can:repositories.manage')
        ->name('bank-reconciliations.unmatch');

    Route::post('/bank-reconciliations/{reconciliation}/complete', [BankReconciliationController::class, 'complete'])
        ->middleware('can:repositories.manage')
        ->name('bank-reconciliations.complete');

    Route::post('/bank-reconciliations/{reconciliation}/cancel', [BankReconciliationController::class, 'cancel'])
        ->middleware('can:repositories.manage')
        ->name('bank-reconciliations.cancel');
});
