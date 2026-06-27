<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Treasury\Domain\Payment;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic Document Controller for cross-cutting operations.
 *
 * This controller only handles operations that span multiple document types:
 * - Cross-type document search (indexAll)
 * - Generic document retrieval by ID when type is unknown (showAny)
 * - Document relationship chains (related)
 *
 * For type-specific CRUD operations, use:
 * - QuoteController for quotes
 * - SalesOrderController for sales orders
 * - InvoiceController for invoices
 * - DeliveryNoteController for delivery notes
 * - PurchaseOrderController for purchase orders
 * - CreditNoteController for credit notes
 * - ReturnNoteController for return notes
 *
 * For document conversions, use DocumentConversionController.
 */
class DocumentController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxCalculationService $taxCalculationService,
    ) {}

    /**
     * List all documents regardless of type.
     *
     * This is used for cross-type document search (e.g., find all documents for a partner).
     *
     * GET /api/v1/documents
     */
    public function indexAll(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $params = $this->getPaginationParams($request);

        $query = Document::forCompany($companyId);

        // Filter by type
        $typeParam = $request->query('type');
        if (is_string($typeParam) && $typeParam !== '') {
            $typeEnum = DocumentType::tryFrom($typeParam);
            if ($typeEnum !== null) {
                $query->ofType($typeEnum);
            }
        }

        // Filter by status
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $statusEnum = DocumentStatus::tryFrom($status);
            if ($statusEnum !== null) {
                $query->inStatus($statusEnum);
            }
        }

        // Filter by partner
        $partnerId = $request->query('partner_id');
        if (is_string($partnerId) && $partnerId !== '') {
            $query->where('partner_id', $partnerId);
        }

        // Search by document number
        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where('document_number', 'like', "%{$search}%");
        }

        // Filter by product (returns documents with lines containing this product)
        $productId = $request->query('product_id');
        if (is_string($productId) && $productId !== '') {
            $query->whereHas('lines', function ($q) use ($productId): void {
                /** @phpstan-ignore argument.type */
                $q->where('product_id', $productId);
            });
        }

        // Handle limit parameter for backwards compatibility
        $limit = $request->query('limit');
        if (is_string($limit) && is_numeric($limit)) {
            $documents = $query->with('vehicleContext')->orderBy('created_at', 'desc')->take((int) $limit)->get();

            return response()->json([
                'data' => $documents->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false)),
                'meta' => [
                    'total' => $documents->count(),
                ],
            ]);
        }

        // Order by created_at desc and id for consistent cursor pagination (in case created_at is the same)
        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        // Use cursor pagination with vehicleContext eager loaded
        $paginator = $query->with('vehicleContext')->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        // Transform items
        $items = collect($paginator->items())->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false))->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'links' => [
                'next' => $paginator->nextCursor()?->encode(),
                'prev' => $paginator->previousCursor()?->encode(),
            ],
        ]);
    }

    /**
     * Get a single document by ID (any type).
     *
     * This is used when you have a document ID but don't know its type.
     *
     * GET /api/v1/documents/{document}
     */
    public function showAny(Request $request, string $document): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $documentModel = Document::forCompany($companyId)
            ->with(['lines', 'lines.product.unitOfMeasure', 'vehicleContext'])
            ->find($document);

        if ($documentModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Document not found',
                ],
            ], 404);
        }

        return response()->json([
            'data' => DocumentData::fromModel($documentModel),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get related documents (ancestors and descendants) for a document.
     *
     * Returns the full document chain showing:
     * - ancestors: Documents that led to this one (e.g., Quote -> Order -> Invoice)
     * - current: The requested document
     * - descendants: Documents derived from this one (e.g., Delivery Notes, Credit Notes)
     *
     * GET /api/v1/documents/{document}/related
     */
    public function related(Request $request, string $document): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $documentModel = Document::forCompany($companyId)
            ->with(['sourceDocument', 'childDocuments'])
            ->find($document);

        if ($documentModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Document not found',
                ],
            ], 404);
        }

        $chain = $documentModel->getDocumentChain();

        return response()->json([
            'data' => [
                'ancestors' => $chain['ancestors']->map(fn (Document $doc): array => [
                    'id' => $doc->id,
                    'type' => $doc->type->value,
                    'document_number' => $doc->document_number,
                    'document_date' => $doc->document_date->format('Y-m-d'),
                    'status' => $doc->status->value,
                    'total' => $doc->total,
                    'currency' => $doc->currency,
                ])->values()->toArray(),
                'current' => [
                    'id' => $documentModel->id,
                    'type' => $documentModel->type->value,
                    'document_number' => $documentModel->document_number,
                    'document_date' => $documentModel->document_date->format('Y-m-d'),
                    'status' => $documentModel->status->value,
                    'total' => $documentModel->total,
                    'currency' => $documentModel->currency,
                ],
                'siblings' => $chain['siblings']->map(fn (Document $doc): array => [
                    'id' => $doc->id,
                    'type' => $doc->type->value,
                    'document_number' => $doc->document_number,
                    'document_date' => $doc->document_date->format('Y-m-d'),
                    'status' => $doc->status->value,
                    'total' => $doc->total,
                    'currency' => $doc->currency,
                ])->values()->toArray(),
                'descendants' => $chain['descendants']->map(fn (Document $doc): array => [
                    'id' => $doc->id,
                    'type' => $doc->type->value,
                    'document_number' => $doc->document_number,
                    'document_date' => $doc->document_date->format('Y-m-d'),
                    'status' => $doc->status->value,
                    'total' => $doc->total,
                    'currency' => $doc->currency,
                ])->values()->toArray(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get tax breakdown for a document
     *
     * GET /api/v1/documents/{id}/tax-breakdown
     */
    public function taxBreakdown(string $id): JsonResponse
    {
        // api.document.030: tenant+company scoped lookup so a cross-tenant
        // document id surfaces as a 404 BEFORE any data is loaded — closes
        // the load-then-check timing leak the prior post-condition guard left.
        $company = $this->companyContext->requireCompany();
        $document = Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['lines', 'company'])
            ->findOrFail($id);

        $taxResult = $this->taxCalculationService->calculateDocumentTaxes($document);

        return response()->json([
            'data' => [
                'subtotal' => $document->subtotal ?? '0.00',
                'discount' => $document->discount_amount ?? '0.00',
                'line_tax_amount' => $taxResult->lineItemsTaxTotal,
                'stamp_duty_amount' => $taxResult->documentTaxTotal,
                'total_tax_amount' => $taxResult->totalTax,
                'total' => $taxResult->total,
                'tax_details' => array_map(function ($detail) {
                    return [
                        'tax_type' => $detail->type->value,
                        'tax_name' => $detail->name,
                        'tax_base' => $detail->base,
                        'tax_rate' => $detail->rate,
                        'tax_amount' => $detail->amount,
                        'is_stamp_duty' => $detail->isStampDuty,
                    ];
                }, $taxResult->taxes),
            ],
        ]);
    }

    /**
     * Get payment history for a document
     *
     * Returns all payment allocations for this document, including payment details
     * like payment method, date, reference, and amount allocated.
     *
     * GET /api/v1/documents/{id}/payments
     */
    public function payments(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $document = Document::forCompany($companyId)->findOrFail($id);

        // Filter out tolerance-only writeoff allocations (payment_id IS NULL): they
        // are not real money movements and don't belong in the payment-history view.
        $allocations = $document->allocations()
            ->whereNotNull('payment_id')
            ->with(['payment.partner', 'payment.paymentMethod'])
            ->orderByDesc('created_at')
            ->get();

        // Also fetch credit note allocations
        $creditAllocations = $document->creditNoteAllocations()
            ->with(['creditNote', 'allocatedBy'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
                'total' => $document->total,
                'balance_due' => $document->balance_due,
                'payment_status' => $document->getPaymentStatus()->value,
                'outstanding_amount' => $document->getOutstandingAmount(),
                'payment_allocations' => $allocations->map(function ($allocation): array {
                    /** @var Payment $payment */
                    $payment = $allocation->payment;  // non-null by whereNotNull('payment_id') above

                    return [
                        'id' => $allocation->id,
                        'payment_id' => $allocation->payment_id,
                        'payment_reference' => $payment->reference,
                        'payment_date' => $payment->payment_date->toDateString(),
                        'payment_method' => $payment->paymentMethod?->name,
                        'amount' => $allocation->amount,
                        'created_at' => $allocation->created_at?->toIso8601String(),
                    ];
                })->toArray(),
                'credit_note_allocations' => $creditAllocations->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'credit_note_id' => $allocation->credit_note_id,
                    'credit_note_number' => $allocation->creditNote->document_number,
                    'amount' => $allocation->amount,
                    'allocated_by' => $allocation->allocatedBy ? [
                        'id' => $allocation->allocatedBy->id,
                        'name' => $allocation->allocatedBy->name,
                        'email' => $allocation->allocatedBy->email,
                    ] : null,
                    'created_at' => $allocation->created_at?->toIso8601String(),
                ])->toArray(),
            ],
        ]);
    }

    /**
     * Get credit note allocations for a document
     *
     * Returns all credit note allocations that reduce this invoice's balance.
     *
     * GET /api/v1/documents/{id}/credit-allocations
     */
    public function creditAllocations(string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $document = Document::forCompany($companyId)->findOrFail($id);

        if ($document->type !== DocumentType::Invoice) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_DOCUMENT_TYPE',
                    'message' => 'Only invoices can have credit note allocations',
                ],
            ], 400);
        }

        $allocations = $document->creditNoteAllocations()
            ->with(['creditNote', 'allocatedBy'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => [
                'document_id' => $document->id,
                'document_number' => $document->document_number,
                'total' => $document->total,
                'balance_due' => $document->balance_due,
                'payment_status' => $document->getPaymentStatus()->value,
                'outstanding_amount' => $document->getOutstandingAmount(),
                'allocations' => $allocations->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'credit_note_id' => $allocation->credit_note_id,
                    'credit_note_number' => $allocation->creditNote->document_number,
                    'credit_note_date' => $allocation->creditNote->document_date->toDateString(),
                    'credit_note_reason' => $allocation->creditNote->credit_note_reason,
                    'amount' => $allocation->amount,
                    'allocated_by' => $allocation->allocatedBy ? [
                        'id' => $allocation->allocatedBy->id,
                        'name' => $allocation->allocatedBy->name,
                        'email' => $allocation->allocatedBy->email,
                    ] : null,
                    'created_at' => $allocation->created_at?->toIso8601String(),
                ])->toArray(),
            ],
        ]);
    }
}
