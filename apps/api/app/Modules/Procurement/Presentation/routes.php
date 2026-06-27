<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
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

    // List supplier invoices (can:documents.view)
    Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index'])
        ->middleware('can:documents.view')
        ->name('supplier-invoices.index');

    // Show single supplier invoice (can:documents.view)
    Route::get('/supplier-invoices/{id}', [SupplierInvoiceController::class, 'show'])
        ->middleware('can:documents.view')
        ->name('supplier-invoices.show');

    // Create supplier invoice + auto-match (can:documents.update)
    Route::post('/supplier-invoices', [SupplierInvoiceController::class, 'store'])
        ->middleware('can:documents.update')
        ->name('supplier-invoices.store');

    // Re-run matcher (can:documents.update)
    Route::post('/supplier-invoices/{id}/match', [SupplierInvoiceController::class, 'match'])
        ->middleware('can:documents.update')
        ->name('supplier-invoices.match');

    // Post supplier invoice (can:documents.update)
    Route::post('/supplier-invoices/{id}/post', [SupplierInvoiceController::class, 'post'])
        ->middleware('can:documents.update')
        ->name('supplier-invoices.post');
});
