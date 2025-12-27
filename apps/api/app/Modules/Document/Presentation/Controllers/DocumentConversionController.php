<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Services\DocumentConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentConversionController extends Controller
{
    public function __construct(
        private readonly DocumentConversionService $conversionService
    ) {}

    /**
     * Convert a quote to a sales order
     */
    public function convertQuoteToOrder(string $id): JsonResponse
    {
        $quote = Document::findOrFail($id);

        try {
            $order = $this->conversionService->convertQuoteToOrder($quote);

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
            $invoice = $this->conversionService->convertOrderToInvoice(
                $order,
                (bool) $request->input('partial', false),
                $request->input('line_ids')
            );

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
            $delivery = $this->conversionService->convertOrderToDelivery($order);

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
            $isExpired = $this->conversionService->isQuoteExpired($quote);

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
            $isFullyInvoiced = $this->conversionService->isOrderFullyInvoiced($order);

            return response()->json([
                'data' => [
                    'fully_invoiced' => $isFullyInvoiced,
                    'invoice_ids' => $order->payload['invoice_ids'] ?? [],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
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

        /** @var array<string> $deliveryNoteIds */
        $deliveryNoteIds = $request->input('delivery_note_ids');

        // Load delivery notes with lines
        $deliveryNotes = Document::whereIn('id', $deliveryNoteIds)
            ->with('lines')
            ->get()
            ->all();

        if (count($deliveryNotes) !== count($deliveryNoteIds)) {
            return response()->json([
                'error' => [
                    'code' => 'DELIVERY_NOTES_NOT_FOUND',
                    'message' => 'One or more delivery notes were not found',
                ],
            ], 404);
        }

        try {
            $invoice = $this->conversionService->createInvoiceFromDeliveryNotes($deliveryNotes);

            return response()->json([
                'data' => $invoice->load(['lines', 'partner', 'vehicleContext']),
                'message' => 'Invoice created from delivery notes successfully',
                'meta' => [
                    'consolidated_delivery_notes' => count($deliveryNotes),
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
