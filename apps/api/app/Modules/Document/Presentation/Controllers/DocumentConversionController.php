<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteBatchValidationException;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentConversionController extends Controller
{
    public function __construct(
        private readonly DocumentConverterRegistry $converterRegistry,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Convert a quote to a sales order
     */
    public function convertQuoteToOrder(string $id): JsonResponse
    {
        // api.document.022: tenant+company scope.
        $quote = $this->scopedQuery()->findOrFail($id);

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

        // api.document.023: tenant+company scope.
        $order = $this->scopedQuery()->findOrFail($id);

        try {
            $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice, [
                'partial' => (bool) $request->input('partial', false),
                'line_ids' => $request->input('line_ids'),
                'actor_user_id' => $request->user()?->id !== null ? (string) $request->user()->id : null,
            ]);

            return response()->json([
                'data' => $invoice->load(['lines', 'partner', 'vehicleContext']),
                'message' => 'Sales order converted to invoice successfully',
            ], 201);
        } catch (DeliveryNoteBatchValidationException $e) {
            $containsAlreadyInvoiced = $e->containsAlreadyInvoiced();

            return response()->json([
                'error' => [
                    'code' => $containsAlreadyInvoiced
                        ? 'DELIVERY_NOTE_ALREADY_INVOICED'
                        : 'PARTIAL_DELIVERY_NOTE_SELECTION_INCOMPLETE',
                    'message' => $e->getMessage(),
                    'details' => $this->deliveryNoteFailureDetails(
                        $e->documents,
                        $containsAlreadyInvoiced ? $id : null,
                    ),
                ],
            ], 422);
        } catch (DeliveryNoteAlreadyClaimedException $e) {
            return response()->json([
                'error' => [
                    'code' => 'DELIVERY_NOTE_ALREADY_INVOICED',
                    'message' => $e->getMessage(),
                    'details' => $this->deliveryNoteFailureDetails([[
                        'id' => $e->deliveryNoteId,
                        'document_number' => $e->deliveryNoteNumber,
                        'reason' => 'claim_lost',
                    ]], $id),
                ],
            ], 422);
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
        // api.document.024: tenant+company scope.
        $order = $this->scopedQuery()->findOrFail($id);

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
        // api.document.025: tenant+company scope.
        $quote = $this->scopedQuery()->findOrFail($id);

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
        // api.document.026: tenant+company scope.
        $order = $this->scopedQuery()->findOrFail($id);

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

        // api.document.027: tenant+company scope.
        $invoice = $this->scopedQuery()->findOrFail($id);

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
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'delivery_note_ids' => 'required|array|min:1',
            // api.document.041: tenant+company scoped exists.
            'delivery_note_ids.*' => ['uuid', ScopedExists::tenantAndCompany('documents', $company->tenant_id, $company->id)],
        ]);

        /** @var array<int, string> $deliveryNoteIds */
        $deliveryNoteIds = array_values($request->input('delivery_note_ids'));

        // Load first delivery note to use as source.
        // api.document.028: tenant+company scope.
        $firstDn = $this->scopedQuery()->with('lines')->find($deliveryNoteIds[0]);

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
        } catch (DeliveryNoteBatchValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->containsAlreadyInvoiced()
                        ? 'DELIVERY_NOTE_ALREADY_INVOICED'
                        : 'CONSOLIDATION_VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                    'details' => $this->deliveryNoteFailureDetails($e->documents),
                ],
            ], 422);
        } catch (DeliveryNoteAlreadyClaimedException $e) {
            return response()->json([
                'error' => [
                    'code' => 'DELIVERY_NOTE_ALREADY_INVOICED',
                    'message' => $e->getMessage(),
                    'details' => $this->deliveryNoteFailureDetails([[
                        'id' => $e->deliveryNoteId,
                        'document_number' => $e->deliveryNoteNumber,
                        'reason' => 'claim_lost',
                    ]]),
                ],
            ], 422);
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
        }
    }

    /**
     * Receive goods for a purchase order (update PurchaseOrder with received quantities).
     *
     * This endpoint uses the converter pattern to wrap the goods receipt functionality.
     * Unlike other converters, this doesn't create a new document - it updates the
     * PurchaseOrder with received quantities and changes status to Received when complete.
     *
     * Supports partial receipts via the `quantities` request body:
     * - If `quantities` is provided: receive specified quantities per line
     * - If `quantities` is empty/missing: receive all remaining quantities
     *
     * Request body (optional):
     * {
     *   "quantities": {
     *     "line_uuid_1": "10.00",
     *     "line_uuid_2": "5.00"
     *   }
     * }
     */
    public function receivePurchaseOrderGoods(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'quantities' => 'sometimes|array',
            'quantities.*' => 'required|string|regex:/^\d+(\.\d{1,4})?$/',
        ]);

        // api.document.029: tenant+company scope.
        $purchaseOrder = $this->scopedQuery()->with('lines')->findOrFail($id);

        try {
            /** @var array<string, string>|null $quantities */
            $quantities = $request->input('quantities');

            // Convert purchase order to received status using converter
            $updatedPurchaseOrder = $this->converterRegistry->convert(
                $purchaseOrder,
                DocumentType::PurchaseOrder,  // Target is same type (updates existing doc)
                [
                    'received_quantities' => $quantities,
                    'actor_user_id' => $request->user()?->id !== null ? (string) $request->user()->id : null,
                ]
            );

            return response()->json([
                'data' => $updatedPurchaseOrder->load(['lines', 'partner', 'vehicleContext']),
                'message' => 'Goods received for purchase order successfully',
            ]);
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
                    'code' => 'GOODS_RECEIPT_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'GOODS_RECEIPT_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Tenant+company scoped Document base query for route-anchored lookups.
     *
     * @return Builder<Document>
     */
    private function scopedQuery(): Builder
    {
        $company = $this->companyContext->requireCompany();

        return Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id);
    }

    /**
     * Re-read attribution only after the converter's outer transaction has
     * rolled back. A claim loser must never build its 422 from transaction-local
     * marker state that no longer exists.
     *
     * @param list<array{
     *     id: string,
     *     document_number: string,
     *     reason: 'already_invoiced'|'wrong_partner'|'wrong_currency'|'not_confirmed'|'cancelled'|'no_lines'|'claim_lost'|'partial_selection_incomplete'
     * }> $failures
     * @return array{
     *     documents: list<array{
     *         id: string,
     *         document_number: string,
     *         reason: string,
     *         invoice_id: string|null,
     *         invoice_number: string|null,
     *         invoice_date: string|null,
     *         invoiced_via: string|null
     *     }>,
     *     billed_order_line_ids?: list<string>
     * }
     */
    private function deliveryNoteFailureDetails(array $failures, ?string $sourceOrderId = null): array
    {
        // The converter has already unwound its outer transaction. Start a new
        // read context so attribution can only describe committed winners, never
        // transaction-local reservation state from the losing attempt.
        return DB::transaction(function () use ($failures, $sourceOrderId): array {
            $ids = array_values(array_unique(array_column($failures, 'id')));
            sort($ids, SORT_STRING);
            $documents = $this->scopedQuery()
                ->where('type', DocumentType::DeliveryNote->value)
                ->whereIn('id', $ids)
                ->get(['id', 'document_number'])
                ->keyBy('id');
            $markers = DB::table('delivery_note_billing_marks')
                ->where('company_id', $this->companyContext->requireCompanyId())
                ->whereIn('delivery_note_id', $ids)
                ->get(['delivery_note_id', 'invoice_id', 'invoiced_via'])
                ->keyBy('delivery_note_id');
            $invoiceIds = $markers->pluck('invoice_id')->filter()->values()->all();
            $invoices = $this->scopedQuery()
                ->where('type', DocumentType::Invoice->value)
                ->whereIn('id', $invoiceIds)
                ->get(['id', 'document_number', 'document_date'])
                ->keyBy('id');

            $documentDetails = array_map(static function (array $failure) use (
                $documents,
                $markers,
                $invoices,
            ): array {
                $marker = $markers->get($failure['id']);
                $invoiceId = $marker?->invoice_id !== null ? (string) $marker->invoice_id : null;

                return [
                    'id' => $failure['id'],
                    'document_number' => $documents->get($failure['id'])->document_number
                        ?? $failure['document_number'],
                    'reason' => $failure['reason'],
                    'invoice_id' => $invoiceId,
                    'invoice_number' => $invoiceId !== null
                        ? $invoices->get($invoiceId)?->document_number
                        : null,
                    'invoice_date' => $invoiceId !== null
                        ? $invoices->get($invoiceId)?->document_date?->toDateString()
                        : null,
                    'invoiced_via' => $marker?->invoiced_via !== null
                        ? (string) $marker->invoiced_via
                        : null,
                ];
            }, $failures);

            $details = ['documents' => $documentDetails];
            if ($sourceOrderId === null) {
                return $details;
            }

            $sourceOrder = $this->scopedQuery()
                ->where('type', DocumentType::SalesOrder->value)
                ->find($sourceOrderId, ['id', 'payload']);
            if (! $sourceOrder instanceof Document || $documents->count() !== count($ids)) {
                return $details;
            }

            $orderLineIds = DocumentLine::query()
                ->where('document_id', $sourceOrderId)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->flip();
            $sourcePayload = $sourceOrder->payload ?? [];
            $rawPriorInvoiceIds = $sourcePayload['invoice_ids'] ?? [];
            if (! is_array($rawPriorInvoiceIds)) {
                return $details;
            }

            $priorInvoiceIds = array_values(array_unique(array_map('strval', $rawPriorInvoiceIds)));
            sort($priorInvoiceIds, SORT_STRING);
            $priorInvoices = $this->scopedQuery()
                ->where('type', DocumentType::Invoice->value)
                ->whereIn('id', $priorInvoiceIds)
                ->get(['id', 'source_document_id'])
                ->keyBy('id');
            if ($priorInvoices->count() !== count($priorInvoiceIds)
                || $priorInvoices->contains(static fn (Document $invoice): bool => $invoice->source_document_id !== $sourceOrderId)) {
                return $details;
            }

            $referencedDocumentIds = array_values(array_unique([...$ids, ...$priorInvoiceIds]));
            $referencedLines = DocumentLine::query()
                ->whereIn('document_id', $referencedDocumentIds)
                ->get(['document_id', 'source_line_id'])
                ->groupBy('document_id');
            $billedOrderLineIds = [];

            foreach ($referencedDocumentIds as $documentId) {
                $lines = $referencedLines->get($documentId, collect());
                if ($lines->isEmpty()) {
                    return $details;
                }

                foreach ($lines as $line) {
                    if ($line->source_line_id === null || ! $orderLineIds->has((string) $line->source_line_id)) {
                        return $details;
                    }

                    $billedOrderLineIds[] = (string) $line->source_line_id;
                }
            }

            $billedOrderLineIds = array_values(array_unique($billedOrderLineIds));
            sort($billedOrderLineIds, SORT_STRING);
            $details['billed_order_line_ids'] = $billedOrderLineIds;

            return $details;
        });
    }
}
