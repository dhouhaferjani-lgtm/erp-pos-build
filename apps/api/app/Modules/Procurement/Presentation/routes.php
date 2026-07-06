<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Procurement\Presentation\Controllers\ProcurementPolicyController;
use App\Modules\Procurement\Presentation\Controllers\PurchaseQuoteRequestController;
use App\Modules\Procurement\Presentation\Controllers\StandaloneReceiptController;
use App\Modules\Procurement\Presentation\Controllers\SupplierInvoiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Procurement Module API Routes
|--------------------------------------------------------------------------
|
| Supplier-invoice create / match / post + list + detail.
|
| Middleware mirrors the Document module route group (purchase-order routes are
| in that group without a module gate). No 'module:Procurement' is added because
| 'Procurement' is not in the ModuleName enum and no vertical enables it by name.
| Access is governed by per-route 'can:' permissions.
|
*/

Route::prefix('api/v1')->middleware([
    'api',
    'auth:sanctum',
    SetPermissionsTeam::class,
    EnforceTokenTenantClaim::class,
])->group(function (): void {
    Route::get('/procurement-policies', [ProcurementPolicyController::class, 'show'])
        ->middleware('can:settings.view')
        ->name('procurement-policies.show');
    Route::put('/procurement-policies', [ProcurementPolicyController::class, 'update'])
        ->middleware('can:settings.update')
        ->name('procurement-policies.update');

    Route::post('/goods-receipts/standalone', [StandaloneReceiptController::class, 'store'])
        ->middleware('can:goods-receipt.create-standalone')
        ->name('goods-receipts.standalone.store');

    Route::get('/purchase-quote-requests', [PurchaseQuoteRequestController::class, 'index'])
        ->middleware('can:purchase-quote-requests.view')
        ->name('purchase-quote-requests.index');
    Route::get('/purchase-quote-requests/groups/{groupId}', [PurchaseQuoteRequestController::class, 'group'])
        ->middleware('can:purchase-quote-requests.view')
        ->whereUuid('groupId')
        ->name('purchase-quote-requests.groups.show');
    Route::post('/purchase-quote-requests', [PurchaseQuoteRequestController::class, 'store'])
        ->middleware('can:purchase-quote-requests.create')
        ->name('purchase-quote-requests.store');
    Route::post('/purchase-quote-requests/groups/{groupId}/reopen', [PurchaseQuoteRequestController::class, 'reopen'])
        ->middleware('can:purchase-quote-requests.convert')
        ->whereUuid('groupId')
        ->name('purchase-quote-requests.groups.reopen');
    Route::get('/purchase-quote-requests/{id}', [PurchaseQuoteRequestController::class, 'show'])
        ->middleware('can:purchase-quote-requests.view')
        ->whereUuid('id')
        ->name('purchase-quote-requests.show');
    Route::put('/purchase-quote-requests/{id}', [PurchaseQuoteRequestController::class, 'update'])
        ->middleware('can:purchase-quote-requests.update')
        ->whereUuid('id')
        ->name('purchase-quote-requests.update');
    Route::post('/purchase-quote-requests/{id}/send', [PurchaseQuoteRequestController::class, 'send'])
        ->middleware('can:purchase-quote-requests.update')
        ->whereUuid('id')
        ->name('purchase-quote-requests.send');
    Route::post('/purchase-quote-requests/{id}/convert-to-po', [PurchaseQuoteRequestController::class, 'convertToPo'])
        ->middleware('can:purchase-quote-requests.convert')
        ->whereUuid('id')
        ->name('purchase-quote-requests.convert-to-po');
});

Route::prefix('api/v1')->middleware([
    'api',
    'auth:sanctum',
    SetPermissionsTeam::class,
    EnforceTokenTenantClaim::class,
])->group(function (): void {

    // List supplier invoices (can:documents.view)
    Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index'])
        ->middleware('can:documents.view')
        ->name('supplier-invoices.index');

    // Check duplicate supplier reference (can:documents.view)
    Route::get('/supplier-invoices/duplicate-reference', [SupplierInvoiceController::class, 'duplicateReference'])
        ->middleware('can:documents.view')
        ->name('supplier-invoices.duplicate-reference');

    // Show single supplier invoice (can:documents.view)
    Route::get('/supplier-invoices/{id}', [SupplierInvoiceController::class, 'show'])
        ->middleware('can:documents.view')
        ->whereUuid('id')
        ->name('supplier-invoices.show');

    // Create supplier invoice + auto-match (can:documents.update)
    Route::post('/supplier-invoices', [SupplierInvoiceController::class, 'store'])
        ->middleware('can:documents.update')
        ->name('supplier-invoices.store');

    // Re-run matcher (can:documents.update)
    Route::post('/supplier-invoices/{id}/match', [SupplierInvoiceController::class, 'match'])
        ->middleware('can:documents.update')
        ->whereUuid('id')
        ->name('supplier-invoices.match');

    // Link pending supplier invoice lines to posted receipt lines
    Route::post('/supplier-invoices/{id}/link-receipts', [SupplierInvoiceController::class, 'linkReceipts'])
        ->middleware('can:supplier-invoices.link-receipts')
        ->whereUuid('id')
        ->name('supplier-invoices.link-receipts');

    // Post supplier invoice (can:documents.update)
    Route::post('/supplier-invoices/{id}/post', [SupplierInvoiceController::class, 'post'])
        ->middleware('can:documents.update')
        ->whereUuid('id')
        ->name('supplier-invoices.post');
});
