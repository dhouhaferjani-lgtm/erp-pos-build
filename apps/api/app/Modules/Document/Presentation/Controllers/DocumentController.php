<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Identity\Domain\User;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @deprecated This controller is being phased out in favor of type-specific controllers.
 * Use QuoteController, SalesOrderController, InvoiceController, DeliveryNoteController,
 * or PurchaseOrderController for CRUD operations on specific document types.
 *
 * This class now only handles:
 * - Cross-type document search (indexAll)
 * - Generic document retrieval by ID (showAny)
 * - Document relationship chains (related)
 * - Legacy operations for document types not yet migrated (ReturnNote, CreditNote)
 *
 * Will be removed in v3.0
 */
class DocumentController extends Controller
{
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly DocumentNumberingService $numberingService,
        private readonly DocumentPostingService $postingService,
        private readonly ReturnNoteService $returnNoteService,
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
            ->with(['lines', 'vehicleContext'])
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
     * List documents of a specific type.
     *
     * @deprecated Use type-specific controllers instead (QuoteController, InvoiceController, etc.)
     * This method is only kept for ReturnNote which doesn't have a dedicated controller yet.
     */
    public function index(Request $request, DocumentType $type): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $params = $this->getPaginationParams($request);

        $query = Document::forCompany($companyId)->ofType($type);

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
     * Get a single document.
     *
     * @deprecated Use type-specific controllers instead (QuoteController, InvoiceController, etc.)
     * This method is only kept for ReturnNote which doesn't have a dedicated controller yet.
     */
    public function show(Request $request, DocumentType $type, string $document): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $documentModel = Document::forCompany($companyId)
            ->ofType($type)
            ->with(['lines', 'allocations.payment.paymentMethod', 'vehicleContext'])
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
     * Create a new document.
     *
     * @deprecated Use type-specific controllers instead (QuoteController, InvoiceController, etc.)
     * This method is only kept for ReturnNote which doesn't have a dedicated controller yet.
     */
    public function store(CreateDocumentRequest $request, DocumentType $type): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var array<int, array{description: string, quantity: string, unit_price: string, product_id?: string, tax_rate?: string, discount_percent?: string, discount_amount?: string, notes?: string}> $lines */
        $lines = $validated['lines'] ?? [];
        unset($validated['lines']);

        // Extract vehicle_context for separate handling
        /** @var array{vehicle_id: string, snapshot?: array<string, mixed>, mileage?: int, additional_data?: array<string, mixed>}|null $vehicleContext */
        $vehicleContext = $validated['vehicle_context'] ?? null;
        unset($validated['vehicle_context']);

        // Normalize issue_date to document_date (frontend sends issue_date)
        if (isset($validated['issue_date']) && ! isset($validated['document_date'])) {
            $validated['document_date'] = $validated['issue_date'];
            unset($validated['issue_date']);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        return DB::transaction(function () use ($tenantId, $companyId, $type, $validated, $lines, $vehicleContext): JsonResponse {
            // Generate document number
            $documentNumber = $this->numberingService->generateNumber($tenantId, $companyId, $type);

            // Calculate totals from lines
            $subtotal = '0.00';
            $taxAmount = '0.00';

            foreach ($lines as $line) {
                /** @var numeric-string $quantity */
                $quantity = (string) $line['quantity'];
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $line['unit_price'];
                /** @var numeric-string $taxRate */
                $taxRate = (string) ($line['tax_rate'] ?? '0');

                $lineSubtotal = bcmul($quantity, $unitPrice, 2);
                $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 4), 2);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $taxAmount = bcadd($taxAmount, $lineTax, 2);
            }

            $total = bcadd($subtotal, $taxAmount, 2);

            // Create document
            $document = Document::create([
                ...$validated,
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'type' => $type,
                'status' => DocumentStatus::Draft,
                'document_number' => $documentNumber,
                'currency' => $validated['currency'] ?? 'EUR',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);

            // Create lines
            foreach ($lines as $index => $lineData) {
                /** @var numeric-string $quantity */
                $quantity = (string) $lineData['quantity'];
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $lineData['unit_price'];
                $lineTotal = bcmul($quantity, $unitPrice, 2);

                DocumentLine::create([
                    'document_id' => $document->id,
                    'product_id' => $lineData['product_id'] ?? null,
                    'line_number' => $index + 1,
                    'description' => $lineData['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_percent' => isset($lineData['discount_percent']) ? (string) $lineData['discount_percent'] : null,
                    'discount_amount' => isset($lineData['discount_amount']) ? (string) $lineData['discount_amount'] : null,
                    'tax_rate' => isset($lineData['tax_rate']) ? (string) $lineData['tax_rate'] : null,
                    'line_total' => $lineTotal,
                    'notes' => $lineData['notes'] ?? null,
                ]);
            }

            // Create vehicle context if vehicle_context provided
            if ($vehicleContext !== null) {
                $vehicleContextBuilder = app(\App\Modules\Vehicle\Application\Services\VehicleContextBuilder::class);

                // Build authoritative context from vehicle_id
                $builtContext = $vehicleContextBuilder->buildFromVehicleId(
                    vehicleId: $vehicleContext['vehicle_id'],
                    tenantId: $tenantId,
                    companyId: $companyId,
                    mileage: $vehicleContext['mileage'] ?? null
                );

                DocumentVehicleContext::create([
                    'document_id' => $document->id,
                    'vehicle_id' => $builtContext['vehicle_id'],
                    'vehicle_snapshot' => $builtContext['snapshot'],
                    'mileage_at_service' => $builtContext['mileage'],
                    'context_data' => $builtContext['additional_data'],
                ]);
            }

            /** @var Document $freshDocument */
            $freshDocument = $document->fresh(['lines', 'vehicleContext']);

            return response()->json([
                'data' => DocumentData::fromModel($freshDocument),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 201);
        });
    }

    /**
     * Confirm a document (Draft -> Confirmed).
     *
     * @deprecated Use type-specific controllers instead (QuoteController::confirm, InvoiceController::confirm, etc.)
     * This method is only kept for CreditNote and ReturnNote which don't have dedicated confirm methods yet.
     */
    public function confirm(Request $request, DocumentType $type, string $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $companyId = $this->companyContext->requireCompanyId();

        // Initial existence check (without lock - for fast 404 response)
        $exists = Document::forCompany($companyId)
            ->ofType($type)
            ->where('id', $document)
            ->exists();

        if (! $exists) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Document not found',
                ],
            ], 404);
        }

        try {
            $documentModel = DB::transaction(function () use ($companyId, $type, $document): Document {
                // Re-fetch with pessimistic lock inside transaction to prevent race conditions
                $lockedDocument = Document::forCompany($companyId)
                    ->ofType($type)
                    ->lockForUpdate()
                    ->find($document);

                if ($lockedDocument === null) {
                    throw new \DomainException('Document not found');
                }

                // Check status inside the lock - this is the idempotency check
                if (! $lockedDocument->isDraft()) {
                    // Already confirmed - return silently (idempotent)
                    if ($lockedDocument->status === DocumentStatus::Confirmed) {
                        return $lockedDocument;
                    }
                    throw new \DomainException('Only draft documents can be confirmed');
                }

                // Dispatch to type-specific domain service for proper lifecycle management
                // Note: Most types now have dedicated controllers. This only handles
                // CreditNote and ReturnNote which still use this generic controller.
                match ($type) {
                    DocumentType::ReturnNote => $this->returnNoteService->confirm($lockedDocument),
                    default => $this->confirmDefault($lockedDocument),
                };

                return $lockedDocument;
            });
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS_TRANSITION',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        /** @var Document $freshDocument */
        $freshDocument = $documentModel->fresh(['lines', 'vehicleContext']);

        return response()->json([
            'data' => DocumentData::fromModel($freshDocument),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Post a document (Confirmed -> Posted).
     *
     * @deprecated Use InvoiceController::post for invoices.
     * This method is only kept for CreditNote which doesn't have a dedicated post method yet.
     */
    public function post(Request $request, DocumentType $type, string $document): JsonResponse
    {
        $documentModel = Document::forCompany($this->companyContext->requireCompanyId())
            ->ofType($type)
            ->find($document);

        if ($documentModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Document not found',
                ],
            ], 404);
        }

        if (! $documentModel->isConfirmed()) {
            $errorCode = match ($type) {
                DocumentType::Invoice => 'INVOICE_NOT_CONFIRMED',
                DocumentType::CreditNote => 'CREDIT_NOTE_NOT_CONFIRMED',
                default => 'DOCUMENT_NOT_CONFIRMED',
            };

            return response()->json([
                'error' => [
                    'code' => $errorCode,
                    'message' => 'Only confirmed documents can be posted',
                ],
            ], 422);
        }

        try {
            $freshDocument = $this->postingService->post($documentModel);

            return response()->json([
                'data' => DocumentData::fromModel($freshDocument),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'fiscal_hash' => $freshDocument->fiscal_hash,
                    'chain_sequence' => $freshDocument->chain_sequence,
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'POSTING_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Create a credit note from a posted invoice.
     *
     * @deprecated Will be moved to InvoiceController or CreditNoteController in v3.0
     */
    public function createCreditNote(Request $request, string $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $invoiceModel = Document::forCompany($this->companyContext->requireCompanyId())
            ->ofType(DocumentType::Invoice)
            ->with(['lines', 'vehicleContext'])
            ->find($invoice);

        if ($invoiceModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Invoice not found',
                ],
            ], 404);
        }

        if (! $invoiceModel->isPosted()) {
            return response()->json([
                'error' => [
                    'code' => 'INVOICE_NOT_POSTED',
                    'message' => 'Credit notes can only be created from posted invoices',
                ],
            ], 422);
        }

        return DB::transaction(function () use ($invoiceModel): JsonResponse {
            $creditNoteNumber = $this->numberingService->generateNumber($invoiceModel->tenant_id, $invoiceModel->company_id, DocumentType::CreditNote);

            // Create the credit note (inherits tenant and company from source document)
            $creditNote = Document::create([
                'tenant_id' => $invoiceModel->tenant_id,
                'company_id' => $invoiceModel->company_id,
                'partner_id' => $invoiceModel->partner_id,
                'type' => DocumentType::CreditNote,
                'status' => DocumentStatus::Draft,
                'document_number' => $creditNoteNumber,
                'document_date' => now()->toDateString(),
                'currency' => $invoiceModel->currency,
                'subtotal' => $invoiceModel->subtotal,
                'discount_amount' => $invoiceModel->discount_amount,
                'tax_amount' => $invoiceModel->tax_amount,
                'total' => $invoiceModel->total,
                'notes' => 'Credit note for '.$invoiceModel->document_number,
                'source_document_id' => $invoiceModel->id,
            ]);

            // Copy vehicle context if exists
            if ($invoiceModel->vehicleContext !== null) {
                DocumentVehicleContext::create([
                    'document_id' => $creditNote->id,
                    'vehicle_id' => $invoiceModel->vehicleContext->vehicle_id,
                    'vehicle_snapshot' => $invoiceModel->vehicleContext->vehicle_snapshot,
                    'mileage_at_service' => $invoiceModel->vehicleContext->mileage_at_service,
                    'context_data' => $invoiceModel->vehicleContext->context_data,
                ]);
            }

            // Copy lines
            foreach ($invoiceModel->lines as $line) {
                DocumentLine::create([
                    'document_id' => $creditNote->id,
                    'product_id' => $line->product_id,
                    'line_number' => $line->line_number,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'discount_amount' => $line->discount_amount,
                    'tax_rate' => $line->tax_rate,
                    'line_total' => $line->line_total,
                    'notes' => $line->notes,
                ]);
            }

            /** @var Document $freshCreditNote */
            $freshCreditNote = $creditNote->fresh(['lines', 'vehicleContext']);

            return response()->json([
                'data' => DocumentData::fromModel($freshCreditNote),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 201);
        });
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
     * Default confirmation for document types without specialized services.
     *
     * This is used for document types that don't have complex lifecycle requirements:
     * - Quote: Simple status change (now handled by QuoteController)
     * - Invoice: Just status update (now handled by InvoiceController)
     * - CreditNote: Just status update (posting creates GL entries)
     * - Expense: Simple status change
     */
    private function confirmDefault(Document $document): void
    {
        $document->update([
            'status' => DocumentStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by' => auth()->id(),
        ]);
    }
}
