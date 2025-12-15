<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DocumentAdditionalCostController;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Presentation\Controllers\CreditNoteController;
use App\Modules\Document\Presentation\Controllers\DocumentController;
use App\Modules\Document\Presentation\Controllers\DocumentConversionController;
use App\Modules\Communication\Presentation\Controllers\DocumentEmailController;
use App\Modules\Document\Presentation\Controllers\DocumentPdfController;
use App\Modules\Document\Presentation\Controllers\RefundController;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Document Module API Routes
|--------------------------------------------------------------------------
|
| Document management routes for quotes, orders, invoices, etc.
|
*/

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function (): void {
    // All documents (unified view)
    Route::get('/documents', [DocumentController::class, 'indexAll'])
        ->middleware('can:documents.view')
        ->name('documents.index');

    Route::get('/documents/{document}', [DocumentController::class, 'showAny'])
        ->middleware('can:documents.view')
        ->name('documents.show');

    // Quotes
    Route::get('/quotes', function (Request $request) {
        return app(DocumentController::class)->index($request, DocumentType::Quote);
    })->middleware('can:quotes.view')->name('quotes.index');

    Route::get('/quotes/{quote}', function (Request $request, string $quote) {
        return app(DocumentController::class)->show($request, DocumentType::Quote, $quote);
    })->middleware('can:quotes.view')->name('quotes.show');

    Route::post('/quotes', function (Request $request) {
        return app(DocumentController::class)->store(
            app(\App\Modules\Document\Presentation\Requests\CreateDocumentRequest::class),
            DocumentType::Quote
        );
    })->middleware('can:quotes.create')->name('quotes.store');

    Route::patch('/quotes/{quote}', function (Request $request, string $quote) {
        return app(DocumentController::class)->update(
            app(\App\Modules\Document\Presentation\Requests\UpdateDocumentRequest::class),
            DocumentType::Quote,
            $quote
        );
    })->middleware('can:quotes.update')->name('quotes.update');

    Route::delete('/quotes/{quote}', function (Request $request, string $quote) {
        return app(DocumentController::class)->destroy($request, DocumentType::Quote, $quote);
    })->middleware('can:quotes.delete')->name('quotes.destroy');

    Route::post('/quotes/{quote}/confirm', function (Request $request, string $quote) {
        return app(DocumentController::class)->confirm($request, DocumentType::Quote, $quote);
    })->middleware('can:quotes.update')->name('quotes.confirm');

    Route::post('/quotes/{quote}/convert-to-order', [DocumentConversionController::class, 'convertQuoteToOrder'])
        ->middleware('can:quotes.convert')
        ->name('quotes.convert-to-order');

    Route::get('/quotes/{quote}/check-expiry', [DocumentConversionController::class, 'checkQuoteExpiry'])
        ->middleware('can:quotes.view')
        ->name('quotes.check-expiry');

    // Sales Orders
    Route::get('/orders', function (Request $request) {
        return app(DocumentController::class)->index($request, DocumentType::SalesOrder);
    })->middleware('can:orders.view')->name('orders.index');

    Route::get('/orders/{order}', function (Request $request, string $order) {
        return app(DocumentController::class)->show($request, DocumentType::SalesOrder, $order);
    })->middleware('can:orders.view')->name('orders.show');

    Route::post('/orders', function (Request $request) {
        return app(DocumentController::class)->store(
            app(\App\Modules\Document\Presentation\Requests\CreateDocumentRequest::class),
            DocumentType::SalesOrder
        );
    })->middleware('can:orders.create')->name('orders.store');

    Route::patch('/orders/{order}', function (Request $request, string $order) {
        return app(DocumentController::class)->update(
            app(\App\Modules\Document\Presentation\Requests\UpdateDocumentRequest::class),
            DocumentType::SalesOrder,
            $order
        );
    })->middleware('can:orders.update')->name('orders.update');

    Route::delete('/orders/{order}', function (Request $request, string $order) {
        return app(DocumentController::class)->destroy($request, DocumentType::SalesOrder, $order);
    })->middleware('can:orders.delete')->name('orders.destroy');

    Route::post('/orders/{order}/confirm', function (Request $request, string $order) {
        return app(DocumentController::class)->confirm($request, DocumentType::SalesOrder, $order);
    })->middleware('can:orders.confirm')->name('orders.confirm');

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
    Route::get('/invoices', function (Request $request) {
        return app(DocumentController::class)->index($request, DocumentType::Invoice);
    })->middleware('can:invoices.view')->name('invoices.index');

    Route::get('/invoices/{invoice}', function (Request $request, string $invoice) {
        return app(DocumentController::class)->show($request, DocumentType::Invoice, $invoice);
    })->middleware('can:invoices.view')->name('invoices.show');

    Route::post('/invoices', function (Request $request) {
        return app(DocumentController::class)->store(
            app(\App\Modules\Document\Presentation\Requests\CreateDocumentRequest::class),
            DocumentType::Invoice
        );
    })->middleware('can:invoices.create')->name('invoices.store');

    Route::patch('/invoices/{invoice}', function (Request $request, string $invoice) {
        return app(DocumentController::class)->update(
            app(\App\Modules\Document\Presentation\Requests\UpdateDocumentRequest::class),
            DocumentType::Invoice,
            $invoice
        );
    })->middleware('can:invoices.update')->name('invoices.update');

    Route::delete('/invoices/{invoice}', function (Request $request, string $invoice) {
        return app(DocumentController::class)->destroy($request, DocumentType::Invoice, $invoice);
    })->middleware('can:invoices.delete')->name('invoices.destroy');

    Route::post('/invoices/{invoice}/confirm', function (Request $request, string $invoice) {
        return app(DocumentController::class)->confirm($request, DocumentType::Invoice, $invoice);
    })->middleware('can:invoices.update')->name('invoices.confirm');

    Route::post('/invoices/{invoice}/post', function (Request $request, string $invoice) {
        return app(DocumentController::class)->post($request, DocumentType::Invoice, $invoice);
    })->middleware('can:invoices.post')->name('invoices.post');

    Route::post('/invoices/{invoice}/create-credit-note', function (Request $request, string $invoice) {
        return app(DocumentController::class)->createCreditNote($request, $invoice);
    })->middleware('can:credit-notes.create')->name('invoices.create-credit-note');

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

    Route::post('/credit-notes/{creditNote}/confirm', function (Request $request, string $creditNote) {
        return app(DocumentController::class)->confirm($request, DocumentType::CreditNote, $creditNote);
    })->middleware('can:credit-notes.create')->name('credit-notes.confirm');

    Route::post('/credit-notes/{creditNote}/post', function (Request $request, string $creditNote) {
        return app(DocumentController::class)->post($request, DocumentType::CreditNote, $creditNote);
    })->middleware('can:credit-notes.post')->name('credit-notes.post');

    Route::post('/credit-notes/{creditNote}/cancel', [RefundController::class, 'cancelCreditNote'])
        ->middleware('can:credit-notes.cancel')
        ->name('credit-notes.cancel');

    // Purchase Orders
    Route::get('/purchase-orders', function (Request $request) {
        return app(DocumentController::class)->index($request, DocumentType::PurchaseOrder);
    })->middleware('can:purchase-orders.view')->name('purchase-orders.index');

    Route::get('/purchase-orders/{purchaseOrder}', function (Request $request, string $purchaseOrder) {
        return app(DocumentController::class)->show($request, DocumentType::PurchaseOrder, $purchaseOrder);
    })->middleware('can:purchase-orders.view')->name('purchase-orders.show');

    Route::post('/purchase-orders', function (Request $request) {
        return app(DocumentController::class)->store(
            app(\App\Modules\Document\Presentation\Requests\CreateDocumentRequest::class),
            DocumentType::PurchaseOrder
        );
    })->middleware('can:purchase-orders.create')->name('purchase-orders.store');

    Route::patch('/purchase-orders/{purchaseOrder}', function (Request $request, string $purchaseOrder) {
        return app(DocumentController::class)->update(
            app(\App\Modules\Document\Presentation\Requests\UpdateDocumentRequest::class),
            DocumentType::PurchaseOrder,
            $purchaseOrder
        );
    })->middleware('can:purchase-orders.update')->name('purchase-orders.update');

    Route::delete('/purchase-orders/{purchaseOrder}', function (Request $request, string $purchaseOrder) {
        return app(DocumentController::class)->destroy($request, DocumentType::PurchaseOrder, $purchaseOrder);
    })->middleware('can:purchase-orders.delete')->name('purchase-orders.destroy');

    Route::post('/purchase-orders/{purchaseOrder}/confirm', function (Request $request, string $purchaseOrder) {
        return app(DocumentController::class)->confirm($request, DocumentType::PurchaseOrder, $purchaseOrder);
    })->middleware('can:purchase-orders.confirm')->name('purchase-orders.confirm');

    Route::post('/purchase-orders/{purchaseOrder}/receive', function (Request $request, string $purchaseOrder) {
        return app(DocumentController::class)->receive($request, DocumentType::PurchaseOrder, $purchaseOrder);
    })->middleware('can:purchase-orders.receive')->name('purchase-orders.receive');

    Route::get('/purchase-orders/{purchaseOrder}/receipt-status', function (Request $request, string $purchaseOrder) {
        return app(DocumentController::class)->receiptStatus($request, DocumentType::PurchaseOrder, $purchaseOrder);
    })->middleware('can:purchase-orders.view')->name('purchase-orders.receipt-status');

    // Delivery Notes
    Route::get('/delivery-notes', function (Request $request) {
        return app(DocumentController::class)->index($request, DocumentType::DeliveryNote);
    })->middleware('can:deliveries.view')->name('delivery-notes.index');

    Route::get('/delivery-notes/{deliveryNote}', function (Request $request, string $deliveryNote) {
        return app(DocumentController::class)->show($request, DocumentType::DeliveryNote, $deliveryNote);
    })->middleware('can:deliveries.view')->name('delivery-notes.show');

    Route::post('/delivery-notes', function (Request $request) {
        return app(DocumentController::class)->store(
            app(\App\Modules\Document\Presentation\Requests\CreateDocumentRequest::class),
            DocumentType::DeliveryNote
        );
    })->middleware('can:deliveries.create')->name('delivery-notes.store');

    Route::post('/delivery-notes/{deliveryNote}/confirm', function (Request $request, string $deliveryNote) {
        return app(DocumentController::class)->confirm($request, DocumentType::DeliveryNote, $deliveryNote);
    })->middleware('can:deliveries.confirm')->name('delivery-notes.confirm');

    // Delivery Note Consolidation (Tunisia model) - Create invoice from multiple delivery notes
    Route::post('/delivery-notes/consolidate-to-invoice', [DocumentConversionController::class, 'createInvoiceFromDeliveryNotes'])
        ->middleware('can:invoices.create')
        ->name('delivery-notes.consolidate-to-invoice');

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

    // PDF Generation
    Route::get('/documents/{document}/pdf', [DocumentPdfController::class, 'download'])
        ->middleware('can:documents.view')
        ->name('documents.pdf.download');

    Route::get('/documents/{document}/pdf/preview', [DocumentPdfController::class, 'preview'])
        ->middleware('can:documents.view')
        ->name('documents.pdf.preview');

    // Email
    Route::post('/documents/{document}/email', [DocumentEmailController::class, 'send'])
        ->middleware('can:documents.view')
        ->name('documents.email.send');

    Route::post('/documents/{document}/email/queue', [DocumentEmailController::class, 'queue'])
        ->middleware('can:documents.view')
        ->name('documents.email.queue');
});
