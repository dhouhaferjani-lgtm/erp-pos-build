<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DocumentAdditionalCostController;
use App\Modules\Communication\Presentation\Controllers\DocumentEmailController;
use App\Modules\Document\Presentation\Controllers\CreditNoteController;
use App\Modules\Document\Presentation\Controllers\DeliveryNoteController;
use App\Modules\Document\Presentation\Controllers\DocumentController;
use App\Modules\Document\Presentation\Controllers\DocumentConversionController;
use App\Modules\Document\Presentation\Controllers\DocumentPdfController;
use App\Modules\Document\Presentation\Controllers\DraftController;
use App\Modules\Document\Presentation\Controllers\InvoiceController;
use App\Modules\Document\Presentation\Controllers\PurchaseOrderController;
use App\Modules\Document\Presentation\Controllers\QuoteController;
use App\Modules\Document\Presentation\Controllers\RefundController;
use App\Modules\Document\Presentation\Controllers\ReportsController;
use App\Modules\Document\Presentation\Controllers\ReturnNoteController;
use App\Modules\Document\Presentation\Controllers\SalesOrderController;
use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Document Module API Routes
|--------------------------------------------------------------------------
|
| Document management routes for quotes, orders, invoices, etc.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function (): void {
    // Draft auto-save (no permissions required - fraud detection)
    Route::post('/documents/auto-save', [DraftController::class, 'autoSave'])
        ->name('documents.auto-save');

    // All documents (unified view)
    Route::get('/documents', [DocumentController::class, 'indexAll'])
        ->middleware('can:documents.view')
        ->name('documents.index');

    Route::post('/documents/{document}/revert', [DocumentController::class, 'revert'])
        ->whereUuid('document')
        ->middleware('can:documents.update')
        ->name('documents.revert');

    Route::get('/documents/{document}', [DocumentController::class, 'showAny'])
        ->middleware('can:documents.view')
        ->name('documents.show');

    // Quotes
    Route::get('/quotes', [QuoteController::class, 'index'])
        ->middleware('can:quotes.view')
        ->name('quotes.index');

    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])
        ->middleware('can:quotes.view')
        ->name('quotes.show');

    Route::post('/quotes', [QuoteController::class, 'store'])
        ->middleware('can:quotes.create')
        ->name('quotes.store');

    Route::patch('/quotes/{quote}', [QuoteController::class, 'update'])
        ->middleware('can:quotes.update')
        ->name('quotes.update');

    Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])
        ->middleware('can:quotes.delete')
        ->name('quotes.destroy');

    Route::post('/quotes/{quote}/confirm', [QuoteController::class, 'confirm'])
        ->middleware('can:quotes.update')
        ->name('quotes.confirm');

    Route::post('/quotes/{quote}/convert-to-order', [DocumentConversionController::class, 'convertQuoteToOrder'])
        ->middleware('can:quotes.convert')
        ->name('quotes.convert-to-order');

    Route::get('/quotes/{quote}/check-expiry', [DocumentConversionController::class, 'checkQuoteExpiry'])
        ->middleware('can:quotes.view')
        ->name('quotes.check-expiry');

    // Sales Orders
    Route::get('/orders', [SalesOrderController::class, 'index'])
        ->middleware('can:orders.view')
        ->name('orders.index');

    Route::get('/orders/{order}', [SalesOrderController::class, 'show'])
        ->middleware('can:orders.view')
        ->name('orders.show');

    Route::post('/orders', [SalesOrderController::class, 'store'])
        ->middleware('can:orders.create')
        ->name('orders.store');

    Route::patch('/orders/{order}', [SalesOrderController::class, 'update'])
        ->middleware('can:orders.update')
        ->name('orders.update');

    Route::delete('/orders/{order}', [SalesOrderController::class, 'destroy'])
        ->middleware('can:orders.delete')
        ->name('orders.destroy');

    Route::post('/orders/{order}/confirm', [SalesOrderController::class, 'confirm'])
        ->middleware('can:orders.confirm')
        ->name('orders.confirm');

    Route::post('/orders/{order}/convert-to-invoice', [DocumentConversionController::class, 'convertOrderToInvoice'])
        ->middleware('can:invoices.create')
        ->name('orders.convert-to-invoice');

    Route::post('/orders/{order}/convert-to-delivery', [DocumentConversionController::class, 'convertOrderToDelivery'])
        ->middleware('can:deliveries.create')
        ->name('orders.convert-to-delivery');

    Route::get('/orders/{order}/invoice-status', [DocumentConversionController::class, 'checkOrderInvoiceStatus'])
        ->middleware('can:orders.view')
        ->name('orders.invoice-status');

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->middleware('can:invoices.view')
        ->name('invoices.index');

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->middleware('can:invoices.view')
        ->name('invoices.show');

    Route::post('/invoices', [InvoiceController::class, 'store'])
        ->middleware('can:invoices.create')
        ->name('invoices.store');

    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])
        ->middleware('can:invoices.update')
        ->name('invoices.update');

    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->middleware('can:invoices.delete')
        ->name('invoices.destroy');

    Route::post('/invoices/{invoice}/confirm', [InvoiceController::class, 'confirm'])
        ->middleware('can:invoices.update')
        ->name('invoices.confirm');

    Route::post('/invoices/{invoice}/post', [InvoiceController::class, 'post'])
        ->middleware('can:invoices.post')
        ->name('invoices.post');

    Route::post('/invoices/{invoice}/confirm-deliveries-and-post', [InvoiceController::class, 'confirmDeliveriesAndPost'])
        ->middleware('can:invoices.post')
        ->name('invoices.confirmDeliveriesAndPost');

    // Wave 3 T25c / D-30 — the REQUIRED path for a standalone goods invoice under
    // `require_delivery_first`. A sibling of confirm-deliveries-and-post rather
    // than a widening of it: that endpoint CONFIRMS delivery notes an order
    // already has (and hard-refuses NO_SOURCE_ORDER); this one CREATES one from
    // the invoice's own physical lines, writes the linkage the resolver reads,
    // confirms it and posts — in one transaction.
    //
    // 📌 D-28 REGISTRATION IS PENDING (3C) — candidate C-5. An earlier comment
    // here claimed "Registered as D-28 composite C-3", which was false twice
    // over: C-3 is TAKEN (DeliveryNoteController::confirm, the final-gate
    // convergent Critical), and nothing was registered at all — the D-28
    // register is deferred to 3C by ruling.
    Route::post('/invoices/{invoice}/create-delivery-and-post', [InvoiceController::class, 'createDeliveryAndPost'])
        ->middleware('can:invoices.post')
        ->name('invoices.createDeliveryAndPost');

    // Close invoice with payment-tolerance write-off (Phase 3 / A2)
    Route::post('/invoices/{invoice}/close-with-tolerance', [InvoiceController::class, 'closeWithTolerance'])
        ->middleware('can:payments.allocate')
        ->name('invoices.close-with-tolerance');

    // Credit note creation from invoice
    Route::post('/invoices/{id}/create-credit-note', [DocumentConversionController::class, 'convertInvoiceToCreditNote'])
        ->middleware('can:credit-notes.create')
        ->name('invoices.create-credit-note');

    // Refund and cancellation routes
    Route::post('/invoices/{invoice}/cancel', [RefundController::class, 'cancelInvoice'])
        ->middleware('can:invoices.cancel')
        ->name('invoices.cancel');

    Route::post('/invoices/{invoice}/credit-full', [RefundController::class, 'createFullCreditNote'])
        ->middleware('can:credit-notes.create')
        ->name('invoices.credit-full');

    Route::post('/invoices/{invoice}/credit-partial', [RefundController::class, 'createPartialCreditNote'])
        ->middleware('can:credit-notes.create')
        ->name('invoices.credit-partial');

    Route::get('/invoices/{invoice}/can-cancel', [RefundController::class, 'checkCancellable'])
        ->middleware('can:invoices.view')
        ->name('invoices.check-cancellable');

    Route::get('/invoices/{invoice}/can-credit', [RefundController::class, 'checkCreditable'])
        ->middleware('can:invoices.view')
        ->name('invoices.check-creditable');

    Route::get('/invoices/{invoice}/credit-summary', [RefundController::class, 'getCreditNoteSummary'])
        ->middleware('can:invoices.view')
        ->name('invoices.credit-summary');

    // Credit Notes (Smart Payment - uses specialized CreditNoteService)
    Route::get('/credit-notes', [CreditNoteController::class, 'index'])
        ->middleware('can:credit-notes.view')
        ->name('credit-notes.index');

    Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show'])
        ->middleware('can:credit-notes.view')
        ->name('credit-notes.show');

    Route::post('/credit-notes', [CreditNoteController::class, 'store'])
        ->middleware('can:credit-notes.create')
        ->name('credit-notes.store');

    Route::post('/credit-notes/{id}/confirm', [CreditNoteController::class, 'confirm'])
        ->middleware('can:credit-notes.create')
        ->name('credit-notes.confirm');

    Route::post('/credit-notes/{id}/post', [CreditNoteController::class, 'post'])
        ->middleware('can:credit-notes.post')
        ->name('credit-notes.post');

    Route::post('/credit-notes/{creditNote}/cancel', [RefundController::class, 'cancelCreditNote'])
        ->middleware('can:credit-notes.cancel')
        ->name('credit-notes.cancel');

    // Purchase Orders
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
        ->middleware('can:purchase-orders.view')
        ->name('purchase-orders.index');

    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
        ->middleware('can:purchase-orders.view')
        ->name('purchase-orders.show');

    Route::get('/purchase-orders/{purchaseOrder}/receipt-lines', [PurchaseOrderController::class, 'receiptLines'])
        ->middleware('can:documents.view')
        ->whereUuid('purchaseOrder')
        ->name('purchase-orders.receipt-lines');

    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->middleware('can:purchase-orders.create')
        ->name('purchase-orders.store');

    Route::patch('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
        ->middleware('can:purchase-orders.update')
        ->name('purchase-orders.update');

    Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])
        ->middleware('can:purchase-orders.delete')
        ->name('purchase-orders.destroy');

    Route::post('/purchase-orders/{purchaseOrder}/confirm', [PurchaseOrderController::class, 'confirm'])
        ->middleware('can:purchase-orders.confirm')
        ->name('purchase-orders.confirm');

    Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])
        ->middleware('can:purchase-orders.receive')
        ->name('purchase-orders.receive');

    Route::get('/purchase-orders/{purchaseOrder}/receipt-status', [PurchaseOrderController::class, 'receiptStatus'])
        ->middleware('can:purchase-orders.view')
        ->name('purchase-orders.receipt-status');

    // Delivery Notes
    Route::get('/delivery-notes', [DeliveryNoteController::class, 'index'])
        ->middleware('can:deliveries.view')
        ->name('delivery-notes.index');

    Route::get('/delivery-notes/uninvoiced', [DeliveryNoteController::class, 'uninvoiced'])
        ->middleware(['module:Sales', 'can:deliveries.view'])
        ->name('delivery-notes.uninvoiced');

    Route::get('/delivery-notes/{deliveryNote}', [DeliveryNoteController::class, 'show'])
        ->whereUuid('deliveryNote')
        ->middleware('can:deliveries.view')
        ->name('delivery-notes.show');

    Route::post('/delivery-notes', [DeliveryNoteController::class, 'store'])
        ->middleware('can:deliveries.create')
        ->name('delivery-notes.store');

    Route::post('/delivery-notes/{deliveryNote}/confirm', [DeliveryNoteController::class, 'confirm'])
        ->middleware('can:deliveries.confirm')
        ->name('delivery-notes.confirm');

    // Delivery Note Consolidation (Tunisia model) - Create invoice from multiple delivery notes
    Route::post('/delivery-notes/consolidate-to-invoice', [DocumentConversionController::class, 'createInvoiceFromDeliveryNotes'])
        ->middleware(['module:Sales', 'can:invoices.create'])
        ->name('delivery-notes.consolidate-to-invoice');

    // Return Notes
    Route::get('/return-notes', [ReturnNoteController::class, 'index'])
        ->middleware('can:deliveries.view')
        ->name('return-notes.index');

    Route::get('/return-notes/{returnNote}', [ReturnNoteController::class, 'show'])
        ->middleware('can:deliveries.view')
        ->name('return-notes.show');

    Route::post('/return-notes', [ReturnNoteController::class, 'store'])
        ->middleware('can:deliveries.create')
        ->name('return-notes.store');

    Route::patch('/return-notes/{returnNote}', [ReturnNoteController::class, 'update'])
        ->middleware('can:deliveries.edit')
        ->name('return-notes.update');

    Route::delete('/return-notes/{returnNote}', [ReturnNoteController::class, 'destroy'])
        ->middleware('can:deliveries.delete')
        ->name('return-notes.destroy');

    Route::post('/return-notes/{returnNote}/confirm', [ReturnNoteController::class, 'confirm'])
        ->middleware('can:deliveries.confirm')
        ->name('return-notes.confirm');

    // Document Additional Costs
    Route::get('/documents/{document}/additional-costs', [DocumentAdditionalCostController::class, 'index'])
        ->middleware('can:documents.view')
        ->name('documents.additional-costs.index');

    Route::post('/documents/{document}/additional-costs', [DocumentAdditionalCostController::class, 'store'])
        ->middleware('can:purchase-orders.update')
        ->name('documents.additional-costs.store');

    Route::patch('/documents/{document}/additional-costs/{cost}', [DocumentAdditionalCostController::class, 'update'])
        ->middleware('can:purchase-orders.update')
        ->name('documents.additional-costs.update');

    Route::delete('/documents/{document}/additional-costs/{cost}', [DocumentAdditionalCostController::class, 'destroy'])
        ->middleware('can:purchase-orders.update')
        ->name('documents.additional-costs.destroy');

    Route::get('/documents/{document}/landed-cost-breakdown', [DocumentAdditionalCostController::class, 'landedCostBreakdown'])
        ->middleware('can:documents.view')
        ->name('documents.landed-cost-breakdown');

    // Related Documents (document chain)
    Route::get('/documents/{document}/related', [DocumentController::class, 'related'])
        ->middleware('can:documents.view')
        ->name('documents.related');

    // Tax Breakdown
    Route::get('/documents/{document}/tax-breakdown', [DocumentController::class, 'taxBreakdown'])
        ->middleware('can:documents.view')
        ->name('documents.tax-breakdown');

    // Payment History
    Route::get('/documents/{document}/payments', [DocumentController::class, 'payments'])
        ->middleware('can:documents.view')
        ->name('documents.payments');

    // Credit Note Allocations
    Route::get('/documents/{document}/credit-allocations', [DocumentController::class, 'creditAllocations'])
        ->middleware('can:documents.view')
        ->name('documents.credit-allocations');

    // PDF Generation
    Route::get('/documents/{document}/pdf', [DocumentPdfController::class, 'download'])
        ->middleware('can:documents.view')
        ->name('documents.pdf.download');

    Route::get('/documents/{document}/pdf/preview', [DocumentPdfController::class, 'preview'])
        ->middleware('can:documents.view')
        ->name('documents.pdf.preview');

    // Email
    Route::post('/documents/{document}/email', [DocumentEmailController::class, 'send'])
        ->middleware(['can:documents.view', 'throttle:document-email'])
        ->name('documents.email.send');

    Route::post('/documents/{document}/email/queue', [DocumentEmailController::class, 'queue'])
        ->middleware(['can:documents.view', 'throttle:document-email'])
        ->name('documents.email.queue');

    // Financial Reports
    //
    // `reports/aged-receivables` deliberately NOT registered here: it collided
    // with the identical route in `Accounting/Presentation/routes.php` (same
    // method + URI, so Laravel's RouteCollection had the last-booted provider
    // silently overwrite this one — Accounting's D2/D4-fixed controller was
    // always served). This Document-module `ReportsController::agedReceivables`
    // still carries both defects and a different bucket contract; deleting the
    // dead registration here removes the collision risk without touching the
    // controller method itself, since `overdueSummary`/`customerStatement`
    // below are still served from it.
    // docs/superpowers/reviews/2026-08-06-l2-treasury-gate.md

    // W-6 D5 owner ruling (2026-08-05, "Option B split"): customer statement
    // and overdue summary are operational reports.
    Route::get('/reports/customer-statement/{partnerId}', [ReportsController::class, 'customerStatement'])
        ->middleware('can:reports.operational')
        ->name('reports.customer-statement');

    Route::get('/reports/overdue-summary', [ReportsController::class, 'overdueSummary'])
        ->middleware('can:reports.operational')
        ->name('reports.overdue-summary');
});
