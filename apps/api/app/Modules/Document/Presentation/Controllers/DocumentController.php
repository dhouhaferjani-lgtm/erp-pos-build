<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Document\Presentation\Requests\UpdateDocumentRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Application\Services\LandedCostService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly CompanyContext $companyContext,
        private readonly LandedCostService $landedCostService,
        private readonly WeightedAverageCostService $wacService,
        private readonly DocumentPostingService $postingService,
        private readonly GoodsReceiptService $goodsReceiptService,
    ) {}

    /**
     * List all documents regardless of type
     */
    public function indexAll(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

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

        // Handle limit parameter
        $limit = $request->query('limit');
        if (is_string($limit) && is_numeric($limit)) {
            $documents = $query->orderBy('created_at', 'desc')->take((int) $limit)->get();

            return response()->json([
                'data' => $documents->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false)),
                'meta' => [
                    'total' => $documents->count(),
                ],
            ]);
        }

        $documents = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'data' => $documents->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false)),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    /**
     * Get a single document by ID (any type)
     */
    public function showAny(Request $request, string $document): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $documentModel = Document::forCompany($companyId)
            ->with('lines')
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
     * List documents of a specific type
     */
    public function index(Request $request, DocumentType $type): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

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

        $documents = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'data' => $documents->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false)),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    /**
     * Get a single document
     */
    public function show(Request $request, DocumentType $type, string $document): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $documentModel = Document::forCompany($companyId)
            ->ofType($type)
            ->with(['lines', 'allocations.payment.paymentMethod'])
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
     * Create a new document
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

        // Normalize issue_date to document_date (frontend sends issue_date)
        if (isset($validated['issue_date']) && ! isset($validated['document_date'])) {
            $validated['document_date'] = $validated['issue_date'];
            unset($validated['issue_date']);
        }

        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        return DB::transaction(function () use ($tenantId, $companyId, $type, $validated, $lines): JsonResponse {
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

            /** @var Document $freshDocument */
            $freshDocument = $document->fresh(['lines']);

            return response()->json([
                'data' => DocumentData::fromModel($freshDocument),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 201);
        });
    }

    /**
     * Update an existing document
     */
    public function update(UpdateDocumentRequest $request, DocumentType $type, string $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

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

        if ($documentModel->isFiscallyImmutable()) {
            return response()->json([
                'error' => [
                    'code' => 'DOCUMENT_SEALED',
                    'message' => 'Sealed fiscal documents cannot be modified. Use credit notes for corrections.',
                ],
            ], 422);
        }

        if (! $documentModel->isEditable()) {
            return response()->json([
                'error' => [
                    'code' => 'DOCUMENT_NOT_EDITABLE',
                    'message' => 'Posted documents cannot be modified',
                ],
            ], 422);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var array<int, array{description: string, quantity: string, unit_price: string, product_id?: string, tax_rate?: string, discount_percent?: string, discount_amount?: string, notes?: string}>|null $lines */
        $lines = $validated['lines'] ?? null;
        unset($validated['lines']);

        // Normalize issue_date to document_date (frontend sends issue_date)
        if (isset($validated['issue_date']) && ! isset($validated['document_date'])) {
            $validated['document_date'] = $validated['issue_date'];
            unset($validated['issue_date']);
        }

        return DB::transaction(function () use ($documentModel, $validated, $lines): JsonResponse {
            // Update document fields (excluding lines)
            $documentModel->update($validated);

            // If lines are provided, replace all lines
            if ($lines !== null) {
                // Delete existing lines
                $documentModel->lines()->delete();

                // Calculate totals from new lines
                $subtotal = '0.00';
                $taxAmount = '0.00';

                foreach ($lines as $index => $lineData) {
                    /** @var numeric-string $quantity */
                    $quantity = (string) $lineData['quantity'];
                    /** @var numeric-string $unitPrice */
                    $unitPrice = (string) $lineData['unit_price'];
                    /** @var numeric-string $taxRate */
                    $taxRate = (string) ($lineData['tax_rate'] ?? '0');

                    $lineSubtotal = bcmul($quantity, $unitPrice, 2);
                    $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 4), 2);

                    $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                    $taxAmount = bcadd($taxAmount, $lineTax, 2);

                    DocumentLine::create([
                        'document_id' => $documentModel->id,
                        'product_id' => $lineData['product_id'] ?? null,
                        'line_number' => $index + 1,
                        'description' => $lineData['description'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount_percent' => isset($lineData['discount_percent']) ? (string) $lineData['discount_percent'] : null,
                        'discount_amount' => isset($lineData['discount_amount']) ? (string) $lineData['discount_amount'] : null,
                        'tax_rate' => $taxRate,
                        'line_total' => $lineSubtotal,
                        'notes' => $lineData['notes'] ?? null,
                    ]);
                }

                $total = bcadd($subtotal, $taxAmount, 2);

                $documentModel->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                ]);
            }

            /** @var Document $freshDocument */
            $freshDocument = $documentModel->fresh(['lines']);

            return response()->json([
                'data' => DocumentData::fromModel($freshDocument),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * Delete a document (soft delete)
     */
    public function destroy(Request $request, DocumentType $type, string $document): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

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

        if ($documentModel->isFiscallyImmutable()) {
            return response()->json([
                'error' => [
                    'code' => 'FISCAL_DOCUMENT_NOT_DELETABLE',
                    'message' => 'Sealed fiscal documents cannot be deleted. Use credit notes for corrections.',
                ],
            ], 422);
        }

        if (! $documentModel->isDeletable()) {
            return response()->json([
                'error' => [
                    'code' => 'DOCUMENT_NOT_DELETABLE',
                    'message' => 'Posted documents cannot be deleted. Use cancellation instead.',
                ],
            ], 422);
        }

        $documentModel->delete();

        return response()->noContent();
    }

    /**
     * Confirm a document (Draft → Confirmed)
     *
     * Uses pessimistic locking inside the transaction to prevent race conditions
     * when two requests try to confirm the same document simultaneously.
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

                $lockedDocument->update(['status' => DocumentStatus::Confirmed]);

                // Integration Hook: For Purchase Orders, allocate landed costs after confirmation
                if ($type === DocumentType::PurchaseOrder) {
                    $this->landedCostService->allocateCosts($lockedDocument);
                }

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
        $freshDocument = $documentModel->fresh(['lines']);

        return response()->json([
            'data' => DocumentData::fromModel($freshDocument),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Post a document (Confirmed → Posted) - Makes it final/immutable.
     *
     * For fiscal documents (Invoice, CreditNote), this creates an entry in the
     * hash chain for NF525 compliance. The document becomes immutable once posted.
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
     * Cancel a posted document.
     *
     * For fiscal documents, the cancellation is recorded in the audit trail
     * while preserving the original hash chain entry.
     */
    public function cancel(Request $request, DocumentType $type, string $document): JsonResponse
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

        if (! $documentModel->isPosted()) {
            return response()->json([
                'error' => [
                    'code' => 'DOCUMENT_NOT_POSTED',
                    'message' => 'Only posted documents can be cancelled',
                ],
            ], 422);
        }

        try {
            $freshDocument = $this->postingService->cancel($documentModel);

            return response()->json([
                'data' => DocumentData::fromModel($freshDocument),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CANCELLATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Convert a quote to a sales order
     */
    public function convertQuoteToOrder(Request $request, string $quote): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $quoteModel = Document::forCompany($this->companyContext->requireCompanyId())
            ->ofType(DocumentType::Quote)
            ->with('lines')
            ->find($quote);

        if ($quoteModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Quote not found',
                ],
            ], 404);
        }

        if (! $quoteModel->isConfirmed()) {
            return response()->json([
                'error' => [
                    'code' => 'QUOTE_NOT_CONFIRMED',
                    'message' => 'Only confirmed quotes can be converted to orders',
                ],
            ], 422);
        }

        // Check if quote has already been converted
        $payload = $quoteModel->payload ?? [];
        if (isset($payload['converted_to_order_id'])) {
            return response()->json([
                'error' => [
                    'code' => 'QUOTE_ALREADY_CONVERTED',
                    'message' => 'This quote has already been converted to an order',
                    'details' => [
                        'order_id' => $payload['converted_to_order_id'],
                    ],
                ],
            ], 422);
        }

        return DB::transaction(function () use ($quoteModel): JsonResponse {
            $orderNumber = $this->numberingService->generateNumber($quoteModel->tenant_id, $quoteModel->company_id, DocumentType::SalesOrder);

            // Create the sales order (inherits tenant and company from source document)
            $order = Document::create([
                'tenant_id' => $quoteModel->tenant_id,
                'company_id' => $quoteModel->company_id,
                'partner_id' => $quoteModel->partner_id,
                'vehicle_id' => $quoteModel->vehicle_id,
                'type' => DocumentType::SalesOrder,
                'status' => DocumentStatus::Draft,
                'document_number' => $orderNumber,
                'document_date' => now()->toDateString(),
                'currency' => $quoteModel->currency,
                'subtotal' => $quoteModel->subtotal,
                'discount_amount' => $quoteModel->discount_amount,
                'tax_amount' => $quoteModel->tax_amount,
                'total' => $quoteModel->total,
                'notes' => $quoteModel->notes,
                'reference' => $quoteModel->reference,
                'source_document_id' => $quoteModel->id,
            ]);

            // Copy lines
            foreach ($quoteModel->lines as $line) {
                DocumentLine::create([
                    'document_id' => $order->id,
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

            // Mark quote as converted
            $quoteModel->update([
                'payload' => array_merge($quoteModel->payload ?? [], [
                    'converted_to_order_id' => $order->id,
                    'converted_at' => now()->toIso8601String(),
                ]),
            ]);

            /** @var Document $freshOrder */
            $freshOrder = $order->fresh(['lines']);

            return response()->json([
                'data' => DocumentData::fromModel($freshOrder),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 201);
        });
    }

    /**
     * Convert a sales order to an invoice
     */
    public function convertOrderToInvoice(Request $request, string $order): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $orderModel = Document::forCompany($this->companyContext->requireCompanyId())
            ->ofType(DocumentType::SalesOrder)
            ->with('lines')
            ->find($order);

        if ($orderModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Sales order not found',
                ],
            ], 404);
        }

        if (! $orderModel->isConfirmed()) {
            return response()->json([
                'error' => [
                    'code' => 'ORDER_NOT_CONFIRMED',
                    'message' => 'Only confirmed orders can be converted to invoices',
                ],
            ], 422);
        }

        return DB::transaction(function () use ($orderModel): JsonResponse {
            $invoiceNumber = $this->numberingService->generateNumber($orderModel->tenant_id, $orderModel->company_id, DocumentType::Invoice);

            // Create the invoice (inherits tenant and company from source document)
            $invoice = Document::create([
                'tenant_id' => $orderModel->tenant_id,
                'company_id' => $orderModel->company_id,
                'partner_id' => $orderModel->partner_id,
                'vehicle_id' => $orderModel->vehicle_id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Draft,
                'document_number' => $invoiceNumber,
                'document_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'currency' => $orderModel->currency,
                'subtotal' => $orderModel->subtotal,
                'discount_amount' => $orderModel->discount_amount,
                'tax_amount' => $orderModel->tax_amount,
                'total' => $orderModel->total,
                'notes' => $orderModel->notes,
                'reference' => $orderModel->reference,
                'source_document_id' => $orderModel->id,
            ]);

            // Copy lines
            foreach ($orderModel->lines as $line) {
                DocumentLine::create([
                    'document_id' => $invoice->id,
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

            /** @var Document $freshInvoice */
            $freshInvoice = $invoice->fresh(['lines']);

            return response()->json([
                'data' => DocumentData::fromModel($freshInvoice),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 201);
        });
    }

    /**
     * Receive goods for a purchase order.
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
    public function receive(Request $request, DocumentType $type, string $document): JsonResponse
    {
        $documentModel = Document::forCompany($this->companyContext->requireCompanyId())
            ->ofType($type)
            ->with('lines')
            ->find($document);

        if ($documentModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Document not found',
                ],
            ], 404);
        }

        if ($type !== DocumentType::PurchaseOrder) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_DOCUMENT_TYPE',
                    'message' => 'Only purchase orders can receive goods',
                ],
            ], 422);
        }

        try {
            /** @var array<string, string>|null $quantities */
            $quantities = $request->input('quantities');

            if (is_array($quantities) && count($quantities) > 0) {
                // Partial receipt with specified quantities
                $updatedDocument = $this->goodsReceiptService->receiveGoods($documentModel, $quantities);
            } else {
                // Receive all remaining quantities
                $updatedDocument = $this->goodsReceiptService->receiveAll($documentModel);
            }

            // Get receipt status for response
            $receiptStatus = $this->goodsReceiptService->getReceiptStatus($updatedDocument);

            return response()->json([
                'data' => DocumentData::fromModel($updatedDocument),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'receipt_status' => $receiptStatus,
                ],
            ]);
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
                    'code' => 'CONFIGURATION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get receipt status for a purchase order.
     */
    public function receiptStatus(Request $request, DocumentType $type, string $document): JsonResponse
    {
        $documentModel = Document::forCompany($this->companyContext->requireCompanyId())
            ->ofType($type)
            ->with('lines')
            ->find($document);

        if ($documentModel === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Document not found',
                ],
            ], 404);
        }

        if ($type !== DocumentType::PurchaseOrder) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_DOCUMENT_TYPE',
                    'message' => 'Only purchase orders have receipt status',
                ],
            ], 422);
        }

        $status = $this->goodsReceiptService->getReceiptStatus($documentModel);

        return response()->json([
            'data' => $status,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create a credit note from a posted invoice
     */
    public function createCreditNote(Request $request, string $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $invoiceModel = Document::forCompany($this->companyContext->requireCompanyId())
            ->ofType(DocumentType::Invoice)
            ->with('lines')
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
                'vehicle_id' => $invoiceModel->vehicle_id,
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
            $freshCreditNote = $creditNote->fresh(['lines']);

            return response()->json([
                'data' => DocumentData::fromModel($freshCreditNote),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ], 201);
        });
    }

    /**
     * Get related documents (ancestors and descendants) for a document
     *
     * Returns the full document chain showing:
     * - ancestors: Documents that led to this one (e.g., Quote → Order → Invoice)
     * - current: The requested document
     * - descendants: Documents derived from this one (e.g., Delivery Notes, Credit Notes)
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
}
