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
use App\Modules\Document\Domain\Services\PurchaseOrderService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Document\Presentation\Requests\UpdateDocumentRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Controller for Purchase Order document operations.
 *
 * This controller handles all purchase-order-specific endpoints:
 * - List purchase orders (with pagination and filters)
 * - Create new purchase orders
 * - View single purchase order
 * - Update draft purchase orders
 * - Delete draft purchase orders
 * - Confirm purchase orders (Draft -> Confirmed with landed cost allocation)
 * - Receive goods (partial or full receipt)
 * - Get receipt status
 *
 * Purchase Order Lifecycle:
 * 1. Draft -> Confirmed (allocates landed costs)
 * 2. Confirmed -> Goods Receipt (updates stock and WAC)
 * 3. Confirmed -> Received (when fully received)
 */
class PurchaseOrderController extends Controller
{
    use HandlesDocuments;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly DocumentNumberingService $numberingService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly GoodsReceiptService $goodsReceiptService,
    ) {}

    /**
     * Get the CompanyContext service.
     *
     * Required by HandlesDocuments trait.
     */
    protected function getCompanyContext(): CompanyContext
    {
        return $this->companyContext;
    }

    /**
     * List all purchase orders with pagination and filters.
     *
     * Supports filters:
     * - status: Filter by DocumentStatus enum value
     * - partner_id: Filter by partner UUID
     * - search: Search by document number (LIKE match)
     * - date_from: Filter documents from this date (inclusive)
     * - date_to: Filter documents up to this date (inclusive)
     * - product_id: Filter documents containing a specific product
     *
     * GET /api/v1/purchase-orders
     */
    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);

        $query = $this->baseQuery()->ofType(DocumentType::PurchaseOrder);

        // Apply common filters from the trait
        $query = $this->applyFilters($query, $request);

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
     * Get a single purchase order by ID.
     *
     * GET /api/v1/purchase-orders/{purchaseOrder}
     */
    public function show(Request $request, string $purchaseOrder): JsonResponse
    {
        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::PurchaseOrder)
            ->with($this->detailRelations())
            ->find($purchaseOrder);

        if ($documentModel === null) {
            return $this->notFoundResponse('Purchase order');
        }

        return $this->documentResponse($documentModel);
    }

    /**
     * Create a new purchase order.
     *
     * POST /api/v1/purchase-orders
     */
    public function store(CreateDocumentRequest $request): JsonResponse
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

        return DB::transaction(function () use ($tenantId, $companyId, $validated, $lines, $vehicleContext): JsonResponse {
            // Generate document number
            $documentNumber = $this->numberingService->generateNumber($tenantId, $companyId, DocumentType::PurchaseOrder);

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
                'type' => DocumentType::PurchaseOrder,
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
                $this->createVehicleContext($document, $vehicleContext, $tenantId, $companyId);
            }

            /** @var Document $freshDocument */
            $freshDocument = $document->fresh($this->defaultRelations());

            return $this->documentCreatedResponse($freshDocument);
        });
    }

    /**
     * Update an existing purchase order.
     *
     * Only draft purchase orders can be updated. Confirmed orders cannot be modified.
     *
     * PATCH /api/v1/purchase-orders/{purchaseOrder}
     */
    public function update(UpdateDocumentRequest $request, string $purchaseOrder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::PurchaseOrder)
            ->find($purchaseOrder);

        if ($documentModel === null) {
            return $this->notFoundResponse('Purchase order');
        }

        if ($documentModel->isFiscallyImmutable()) {
            return $this->fiscalImmutabilityErrorResponse();
        }

        if (! $documentModel->isEditable()) {
            return $this->notEditableErrorResponse();
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var array<int, array{description: string, quantity: string, unit_price: string, product_id?: string, tax_rate?: string, discount_percent?: string, discount_amount?: string, notes?: string}>|null $lines */
        $lines = $validated['lines'] ?? null;
        unset($validated['lines']);

        // Extract vehicle_context for separate handling
        $hasVehicleContext = array_key_exists('vehicle_context', $validated);
        /** @var array{vehicle_id: string, snapshot?: array<string, mixed>, mileage?: int, additional_data?: array<string, mixed>}|null $vehicleContext */
        $vehicleContext = $validated['vehicle_context'] ?? null;
        unset($validated['vehicle_context']);

        // Normalize issue_date to document_date (frontend sends issue_date)
        if (isset($validated['issue_date']) && ! isset($validated['document_date'])) {
            $validated['document_date'] = $validated['issue_date'];
            unset($validated['issue_date']);
        }

        return DB::transaction(function () use ($documentModel, $validated, $lines, $vehicleContext, $hasVehicleContext): JsonResponse {
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

            // Update vehicle context only if vehicle_context was provided in request
            $this->attachVehicleContext($documentModel, $vehicleContext, $hasVehicleContext);

            /** @var Document $freshDocument */
            $freshDocument = $documentModel->fresh($this->defaultRelations());

            return $this->documentResponse($freshDocument);
        });
    }

    /**
     * Delete a purchase order (soft delete).
     *
     * Only draft purchase orders can be deleted. Confirmed orders cannot be deleted.
     *
     * DELETE /api/v1/purchase-orders/{purchaseOrder}
     */
    public function destroy(Request $request, string $purchaseOrder): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::PurchaseOrder)
            ->find($purchaseOrder);

        if ($documentModel === null) {
            return $this->notFoundResponse('Purchase order');
        }

        if ($documentModel->isFiscallyImmutable()) {
            return $this->fiscalNotDeletableErrorResponse();
        }

        if (! $documentModel->isDeletable()) {
            return $this->notDeletableErrorResponse();
        }

        $documentModel->delete();

        return response()->noContent();
    }

    /**
     * Confirm a purchase order (Draft -> Confirmed with landed cost allocation).
     *
     * Uses pessimistic locking inside the transaction to prevent race conditions
     * when two requests try to confirm the same order simultaneously.
     *
     * When confirmed:
     * - Order status changes to Confirmed
     * - Landed costs are allocated to all lines proportionally
     * - PurchaseOrderConfirmed event is dispatched for audit trail
     *
     * POST /api/v1/purchase-orders/{purchaseOrder}/confirm
     */
    public function confirm(Request $request, string $purchaseOrder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $companyId = $this->companyContext->requireCompanyId();

        // Initial existence check (without lock - for fast 404 response)
        $exists = Document::forCompany($companyId)
            ->ofType(DocumentType::PurchaseOrder)
            ->where('id', $purchaseOrder)
            ->exists();

        if (! $exists) {
            return $this->notFoundResponse('Purchase order');
        }

        try {
            $documentModel = DB::transaction(function () use ($companyId, $purchaseOrder): Document {
                // Re-fetch with pessimistic lock inside transaction to prevent race conditions
                $lockedDocument = Document::forCompany($companyId)
                    ->ofType(DocumentType::PurchaseOrder)
                    ->lockForUpdate()
                    ->find($purchaseOrder);

                if ($lockedDocument === null) {
                    throw new \DomainException('Purchase order not found');
                }

                // Check status inside the lock - this is the idempotency check
                if (! $lockedDocument->isDraft()) {
                    // Already confirmed - return silently (idempotent)
                    if ($lockedDocument->status === DocumentStatus::Confirmed) {
                        return $lockedDocument;
                    }
                    throw new \DomainException('Only draft purchase orders can be confirmed');
                }

                // Use the PurchaseOrderService for proper lifecycle management with landed costs
                return $this->purchaseOrderService->confirm($lockedDocument);
            });
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('INVALID_STATUS_TRANSITION', $e->getMessage());
        }

        /** @var Document $freshDocument */
        $freshDocument = $documentModel->fresh($this->defaultRelations());

        return $this->documentResponse($freshDocument);
    }

    /**
     * Receive goods for a purchase order.
     *
     * Supports partial receipts via the `quantities` request body:
     * - If `quantities` is provided: receive specified quantities per line
     * - If `quantities` is empty/missing: receive all remaining quantities
     *
     * When goods are received:
     * - Stock levels are increased
     * - Weighted Average Cost (WAC) is updated
     * - Line quantity_received is updated
     * - If fully received, status changes to Received
     *
     * Request body (optional):
     * {
     *   "quantities": {
     *     "line_uuid_1": "10.00",
     *     "line_uuid_2": "5.00"
     *   }
     * }
     *
     * POST /api/v1/purchase-orders/{purchaseOrder}/receive
     */
    public function receive(Request $request, string $purchaseOrder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::PurchaseOrder)
            ->with('lines')
            ->find($purchaseOrder);

        if ($documentModel === null) {
            return $this->notFoundResponse('Purchase order');
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
            return $this->validationErrorResponse('GOODS_RECEIPT_FAILED', $e->getMessage());
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
     *
     * Returns:
     * - status: 'not_received' | 'partially_received' | 'fully_received'
     * - total_ordered: Total quantity ordered across all lines
     * - total_received: Total quantity received so far
     * - percentage: Percentage of order received
     * - lines: Per-line breakdown with quantities
     *
     * GET /api/v1/purchase-orders/{purchaseOrder}/receipt-status
     */
    public function receiptStatus(Request $request, string $purchaseOrder): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::PurchaseOrder)
            ->with('lines')
            ->find($purchaseOrder);

        if ($documentModel === null) {
            return $this->notFoundResponse('Purchase order');
        }

        $status = $this->goodsReceiptService->getReceiptStatus($documentModel);

        return response()->json([
            'data' => $status,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
