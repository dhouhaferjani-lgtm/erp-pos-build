<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentConversionController extends Controller
{
    public function __construct(
        private readonly DocumentConverterRegistry $converterRegistry
    ) {}

    /**
     * Convert a quote to a sales order
     */
    public function convertQuoteToOrder(string $id): JsonResponse
    {
        $quote = Document::findOrFail($id);

        try {
            $order = $this->converterRegistry->convert($quote, DocumentType::SalesOrder);

            return response()->json([
                'data' => $order->load(['lines', 'partner', 'vehicleContext']),
                'message' => 'Quote converted to sales order successfully',
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'QUOTE_NOT_CONFIRMED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Convert a sales order to an invoice
     */
    public function convertOrderToInvoice(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'partial' => 'sometimes|boolean',
            'line_ids' => 'sometimes|array',
            'line_ids.*' => 'exists:document_lines,id',
        ]);

        $order = Document::findOrFail($id);

        try {
            $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice, [
                'partial' => (bool) $request->input('partial', false),
                'line_ids' => $request->input('line_ids'),
            ]);

            return response()->json([
                'data' => $invoice->load(['lines', 'partner', 'vehicleContext']),
                'message' => 'Sales order converted to invoice successfully',
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'ORDER_NOT_CONFIRMED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Convert a sales order to a delivery note
     */
    public function convertOrderToDelivery(string $id): JsonResponse
    {
        $order = Document::findOrFail($id);

        try {
            $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);

            return response()->json([
                'data' => $delivery->load(['lines', 'partner', 'vehicleContext']),
                'message' => 'Sales order converted to delivery note successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if a quote has expired
     */
    public function checkQuoteExpiry(string $id): JsonResponse
    {
        $quote = Document::findOrFail($id);

        try {
            if ($quote->type !== DocumentType::Quote) {
                throw new \InvalidArgumentException('Document must be a quote');
            }

            $isExpired = $quote->valid_until !== null && $quote->valid_until->isPast();

            return response()->json([
                'data' => [
                    'is_expired' => $isExpired,
                    'valid_until' => $quote->valid_until?->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if an order has been fully invoiced
     */
    public function checkOrderInvoiceStatus(string $id): JsonResponse
    {
        $order = Document::findOrFail($id);

        try {
            if ($order->type !== DocumentType::SalesOrder) {
                throw new \InvalidArgumentException('Document must be a sales order');
            }

            $payload = $order->payload ?? [];
            $isFullyInvoiced = $payload['fully_invoiced'] ?? false;

            return response()->json([
                'data' => [
                    'fully_invoiced' => $isFullyInvoiced,
                    'invoice_ids' => $payload['invoice_ids'] ?? [],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Convert an invoice to a credit note
     *
     * Supports two modes:
     * 1. Amount-based: Credit a fixed amount from an invoice
     * 2. Line-based: Credit specific line items with quantities
     */
    public function convertInvoiceToCreditNote(Request $request, string $id): JsonResponse
    {
        // Determine conversion mode
        $isLineBased = $request->has('lines') && is_array($request->input('lines'));

        $rules = [
            'reason' => 'required|string',
            'notes' => 'nullable|string|max:1000',
        ];

        if ($isLineBased) {
            $rules['lines'] = 'required|array|min:1';
            $rules['lines.*.line_id'] = 'required|string|exists:document_lines,id';
            $rules['lines.*.quantity'] = 'required|numeric|gt:0';
        } else {
            $rules['amount'] = 'required|string|regex:/^\d+(\.\d{1,4})?$/';
        }

        $request->validate($rules);

        $invoice = Document::findOrFail($id);

        try {
            $options = [
                'reason' => $request->input('reason'),
                'notes' => $request->input('notes'),
            ];

            if ($isLineBased) {
                $options['lines'] = $request->input('lines');
            } else {
                $options['amount'] = $request->input('amount');
            }

            $creditNote = $this->converterRegistry->convert($invoice, DocumentType::CreditNote, $options);

            return response()->json([
                'data' => $creditNote->load(['lines', 'partner', 'sourceDocument']),
                'message' => 'Credit note created from invoice successfully',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CONVERSION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Create an invoice from one or more delivery notes (Tunisia consolidation model).
     *
     * Consolidates multiple confirmed delivery notes into a single invoice.
     * All DNs must belong to the same partner, company, and currency.
     */
    public function createInvoiceFromDeliveryNotes(Request $request): JsonResponse
    {
        $request->validate([
            'delivery_note_ids' => 'required|array|min:1',
            'delivery_note_ids.*' => 'uuid|exists:documents,id',
        ]);

        /** @var array<int, string> $deliveryNoteIds */
        $deliveryNoteIds = array_values($request->input('delivery_note_ids'));

        // Load first delivery note to use as source
        $firstDn = Document::with('lines')->find($deliveryNoteIds[0]);

        if ($firstDn === null) {
            return response()->json([
                'error' => [
                    'code' => 'DELIVERY_NOTES_NOT_FOUND',
                    'message' => 'First delivery note was not found',
                ],
            ], 404);
        }

        try {
            // Use registry to convert DN(s) to invoice with consolidation option
            $invoice = $this->converterRegistry->convert($firstDn, DocumentType::Invoice, [
                'delivery_note_ids' => $deliveryNoteIds,
            ]);

            return response()->json([
                'data' => $invoice->load(['lines', 'partner', 'vehicleContext']),
                'message' => 'Invoice created from delivery notes successfully',
                'meta' => [
                    'consolidated_delivery_notes' => count($deliveryNoteIds),
                    'source_delivery_note_ids' => $deliveryNoteIds,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_DELIVERY_NOTES',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CONSOLIDATION_VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'DELIVERY_NOTE_ALREADY_INVOICED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
