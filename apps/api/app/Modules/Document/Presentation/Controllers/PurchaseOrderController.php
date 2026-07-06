<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Application\Services\DocumentLineTaxResolver;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PriceEntryMode;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\PurchaseOrderService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Document\Presentation\Requests\ReceiveGoodsRequest;
use App\Modules\Document\Presentation\Requests\UpdateDocumentRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptData;
use App\Modules\Inventory\Application\DTOs\GoodsReceiptResult;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\GoodsReceipt;
use App\Modules\Inventory\Domain\GoodsReceiptLine;
use App\Modules\Procurement\Application\PurchaseBonusGate;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Vehicle\Application\Services\VehicleContextBuilder;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Support\Traits\PaginatesResults;
use Illuminate\Database\Eloquent\Collection;
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
        private readonly LocationContext $locationContext,
        private readonly DocumentNumberingService $numberingService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly GoodsReceiptService $goodsReceiptService,
        private readonly VehicleContextBuilder $vehicleContextBuilder,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly DocumentLineTaxResolver $lineTaxResolver,
        private readonly PurchaseBonusGate $purchaseBonusGate,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Normalize purchase-line pricing without changing the semantic meaning of
     * `quantity`: it remains paid quantity. Free quantity affects inventory
     * later, never tax or document totals.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizePurchaseLine(array $line): array
    {
        /** @var numeric-string $quantity */
        $quantity = CurrencyScale::bcformatStrict((string) $line['quantity'], 4);
        /** @var numeric-string $freeQuantity */
        $freeQuantity = CurrencyScale::bcformatStrict((string) ($line['free_quantity'] ?? '0'), 4);
        $mode = PriceEntryMode::tryFrom((string) ($line['price_entry_mode'] ?? PriceEntryMode::Unit->value))
            ?? PriceEntryMode::Unit;

        if ($mode === PriceEntryMode::Total) {
            /** @var numeric-string $lineTotal */
            $lineTotal = CurrencyScale::bcformatStrict((string) $line['line_total'], $this->scale());
            /** @var numeric-string $derivedUnitPrice */
            $derivedUnitPrice = CurrencyScale::bcformatStrict(bcdiv($lineTotal, $quantity, $this->scale() + 1), $this->scale());
            $line['unit_price'] = $derivedUnitPrice;
        } else {
            /** @var numeric-string $unitPrice */
            $unitPrice = CurrencyScale::bcformatStrict((string) $line['unit_price'], $this->scale());
            /** @var numeric-string $lineTotal */
            $lineTotal = DocumentLine::computeLineTotal(
                $quantity,
                $unitPrice,
                $this->numericStringOrNull($line['discount_percent'] ?? null),
                $this->numericStringOrNull($line['discount_amount'] ?? null),
                $this->scale(),
            );
            $line['unit_price'] = $unitPrice;
        }

        $line['quantity'] = $quantity;
        $line['free_quantity'] = $freeQuantity;
        $line['line_total'] = $lineTotal;
        $line['price_entry_mode'] = $mode->value;

        if ($mode === PriceEntryMode::Total || bccomp($freeQuantity, '0', 4) > 0) {
            $line['landed_unit_cost'] = CurrencyScale::bcformatStrict(bcdiv($lineTotal, $quantity, $this->scale() + 4), 6);
        }

        return $line;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{description: string, quantity: numeric-string, unit_price: numeric-string, product_id?: string, service_id?: string, tax_rate?: string|null, tax_configuration_id?: string|null, discount_percent?: string|null, discount_amount?: string|null, notes?: string|null, ...}>
     */
    private function documentLinePayloads(array $lines): array
    {
        return array_map(function (array $line): array {
            $payload = [
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => $this->numericString((string) ($line['quantity'] ?? '0')),
                'unit_price' => $this->numericString((string) ($line['unit_price'] ?? '0')),
            ];

            foreach (['product_id', 'service_id'] as $key) {
                if (isset($line[$key]) && is_scalar($line[$key])) {
                    $payload[$key] = (string) $line[$key];
                }
            }

            foreach ([
                'tax_rate',
                'tax_configuration_id',
                'discount_percent',
                'discount_amount',
                'notes',
                'free_quantity',
                'price_entry_mode',
                'line_total',
            ] as $key) {
                if (array_key_exists($key, $line)) {
                    $payload[$key] = $line[$key] === null ? null : (is_scalar($line[$key]) ? (string) $line[$key] : null);
                }
            }

            if (array_key_exists('is_bonus_line', $line)) {
                $payload['is_bonus_line'] = (bool) $line['is_bonus_line'];
            }

            return $payload;
        }, $lines);
    }

    /**
     * @return numeric-string
     */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException('Expected numeric string.');
        }

        return $value;
    }

    /**
     * @return numeric-string|null
     */
    private function numericStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->numericString((string) $value);
    }

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

        $includeAutoGenerated = $request->query('include_auto_generated');
        if ($includeAutoGenerated !== '1' && $includeAutoGenerated !== 'true') {
            $query->whereJsonDoesntContainKey('payload->auto_generated');
        }

        $hasUninvoiced = $request->query('has_uninvoiced');
        if ($hasUninvoiced === '1' || $hasUninvoiced === 'true') {
            $companyId = $this->companyContext->requireCompanyId();
            $tenantId = $this->companyContext->requireCompany()->tenant_id;
            $query->whereHas('lines', function ($lineQuery) use ($companyId, $tenantId): void {
                $lineQuery->whereExists(
                    GoodsReceiptLine::query()
                        ->selectRaw('1')
                        ->postedReceipts()
                        ->where('goods_receipt_lines.tenant_id', $tenantId)
                        ->where('goods_receipt_lines.company_id', $companyId)
                        ->whereColumn('goods_receipt_lines.po_line_id', 'document_lines.id')
                        ->whereColumn('goods_receipt_lines.received_qty', '>', 'goods_receipt_lines.quantity_invoiced')
                );
            });
        }

        // Order by created_at desc and id for consistent cursor pagination (in case created_at is the same)
        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        // Use cursor pagination with vehicleContext eager loaded
        $paginator = $query->with('vehicleContext')->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        // Transform items
        $items = collect($paginator->items())->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false, $this->scale()))->all();

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

        $data = DocumentData::fromModel($documentModel, true, $this->scale())->toArray();
        $data['goods_receipts'] = $this->goodsReceiptLinks($documentModel);
        $data['supplier_invoices'] = $this->supplierInvoiceLinks($documentModel);

        return response()->json(['data' => $data]);
    }

    /**
     * @return list<array{id: string, receipt_number: string|null, status: string, received_at: string|null, external_reference: string|null}>
     */
    private function goodsReceiptLinks(Document $purchaseOrder): array
    {
        return array_values(GoodsReceipt::query()
            ->where('tenant_id', $purchaseOrder->tenant_id)
            ->where('company_id', $purchaseOrder->company_id)
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (GoodsReceipt $receipt): array => [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'status' => $receipt->status->value,
                'received_at' => $receipt->received_at->toIso8601String(),
                'external_reference' => $receipt->external_reference,
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array{id: string, document_number: string|null, status: string, total: string|null}>
     */
    private function supplierInvoiceLinks(Document $purchaseOrder): array
    {
        return array_values(Document::query()
            ->where('tenant_id', $purchaseOrder->tenant_id)
            ->where('company_id', $purchaseOrder->company_id)
            ->where('type', DocumentType::SupplierInvoice)
            ->where(function ($query) use ($purchaseOrder): void {
                $query->where('source_document_id', $purchaseOrder->id)
                    ->orWhereJsonContains('payload->supplier_invoice->source_document_ids', $purchaseOrder->id);
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Document $invoice): array => [
                'id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'status' => $invoice->status->value,
                'total' => $invoice->total,
            ])
            ->values()
            ->all());
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

        /** @var array<int, array<string, mixed>> $lines */
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

        return DB::transaction(function () use ($tenantId, $companyId, $company, $validated, $lines, $vehicleContext): JsonResponse {
            // Generate document number
            $documentNumber = $this->numberingService->generateNumber($tenantId, $companyId, DocumentType::PurchaseOrder);

            // Batch-fetch products and services for tax defaults + snapshot capture (1 query each)
            $productIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->toArray();
            $serviceIds = collect($lines)->pluck('service_id')->filter()->unique()->values()->toArray();
            /** @var Collection<array-key, Product> $products */
            $products = Product::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereIn('id', $productIds)->get()->keyBy('id');
            /** @var Collection<array-key, Service> $services */
            $services = Service::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereIn('id', $serviceIds)->get()->keyBy('id');
            $lines = $this->lineTaxResolver->resolve($this->documentLinePayloads($lines), $company, $products);
            $lines = array_map(fn (array $line): array => $this->normalizePurchaseLine($line), $lines);

            // Calculate totals from lines
            $subtotal = '0.00';
            $taxAmount = '0.00';

            foreach ($lines as $line) {
                /** @var numeric-string $taxRate */
                $taxRate = (string) ($line['tax_rate'] ?? '0');

                $lineSubtotal = $this->numericString((string) $line['line_total']);
                $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 4), $this->scale());

                $subtotal = bcadd($subtotal, $lineSubtotal, $this->scale());
                $taxAmount = bcadd($taxAmount, $lineTax, $this->scale());
            }

            $total = bcadd($subtotal, $taxAmount, $this->scale());

            // Resolve location using LocationContext fallback chain
            $locationId = $this->locationContext->resolveLocationId(
                $validated['location_id'] ?? null,
                $companyId
            );

            // Create document
            $document = Document::create([
                ...$validated,
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'type' => DocumentType::PurchaseOrder,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::PurchaseOrder),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'location_id' => $locationId,
                'document_number' => $documentNumber,
                'currency' => $validated['currency'] ?? $company->currency,
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
                /** @var numeric-string $lineTotal */
                $lineTotal = (string) $lineData['line_total'];

                /** @var Service|null $lineService */
                $lineService = isset($lineData['service_id']) ? $services->get($lineData['service_id']) : null;
                /** @var Product|null $lineProduct */
                $lineProduct = isset($lineData['product_id']) ? $products->get($lineData['product_id']) : null;

                $defaultName = $lineService !== null
                    ? (string) $lineService->name
                    : ($lineProduct !== null ? (string) $lineProduct->name : '');

                DocumentLine::create([
                    'document_id' => $document->id,
                    'product_id' => $lineData['product_id'] ?? null,
                    'service_id' => $lineData['service_id'] ?? null,
                    'line_number' => $index + 1,
                    'description' => $lineData['description'],
                    'designation_default_snapshot' => $defaultName !== '' ? mb_substr($defaultName, 0, 500) : null,
                    'quantity' => $quantity,
                    'free_quantity' => (string) ($lineData['free_quantity'] ?? '0'),
                    'unit_price' => $unitPrice,
                    'discount_percent' => isset($lineData['discount_percent']) ? (string) $lineData['discount_percent'] : null,
                    'discount_amount' => isset($lineData['discount_amount']) ? (string) $lineData['discount_amount'] : null,
                    'tax_rate' => isset($lineData['tax_rate']) ? (string) $lineData['tax_rate'] : null,
                    'line_total' => $lineTotal,
                    'landed_unit_cost' => $lineData['landed_unit_cost'] ?? null,
                    'price_entry_mode' => (string) ($lineData['price_entry_mode'] ?? PriceEntryMode::Unit->value),
                    'is_bonus_line' => (bool) ($lineData['is_bonus_line'] ?? false),
                    'notes' => $lineData['notes'] ?? null,
                ]);
            }

            // Create vehicle context if vehicle_context provided
            if ($vehicleContext !== null) {
                $this->createVehicleContext($document, $vehicleContext, $tenantId, $companyId, $this->vehicleContextBuilder);
            }

            /** @var Document $freshDocument */
            $freshDocument = $document->fresh($this->defaultRelations());

            return $this->documentCreatedResponse($freshDocument, $this->scale());
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

        /** @var array<int, array<string, mixed>>|null $lines */
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

        $company = $this->companyContext->requireCompany();

        // Receipt-lock check MUST precede the transaction: a rejection returned from
        // inside the closure would still COMMIT the header update executed before it.
        if ($lines !== null) {
            /** @var list<string> $existingLineIds */
            $existingLineIds = $documentModel->lines()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            if ($this->goodsReceiptService->poLineIdsWithReceipts($existingLineIds) !== []) {
                return $this->validationErrorResponse(
                    'PO_LINES_LOCKED_BY_RECEIPTS',
                    'Purchase order lines with goods receipts cannot be modified.'
                );
            }
        }

        return DB::transaction(function () use ($documentModel, $validated, $lines, $vehicleContext, $hasVehicleContext, $company): JsonResponse {
            // Update document fields (excluding lines)
            $documentModel->update($validated);

            // If lines are provided, replace all lines
            if ($lines !== null) {
                // Delete existing lines
                $documentModel->lines()->delete();

                // Batch-fetch products and services for snapshot capture (1 query each)
                $updateProductIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->toArray();
                $updateServiceIds = collect($lines)->pluck('service_id')->filter()->unique()->values()->toArray();
                /** @var Collection<array-key, Product> $updateProducts */
                $updateProducts = Product::query()->where('tenant_id', $documentModel->tenant_id)->where('company_id', $documentModel->company_id)->whereIn('id', $updateProductIds)->get()->keyBy('id');
                /** @var Collection<array-key, Service> $updateServices */
                $updateServices = Service::query()->where('tenant_id', $documentModel->tenant_id)->where('company_id', $documentModel->company_id)->whereIn('id', $updateServiceIds)->get()->keyBy('id');
                $lines = $this->lineTaxResolver->resolve($this->documentLinePayloads($lines), $company, $updateProducts);
                $lines = array_map(fn (array $line): array => $this->normalizePurchaseLine($line), $lines);

                // Calculate totals from new lines
                $subtotal = '0.00';
                $taxAmount = '0.00';

                foreach ($lines as $index => $lineData) {
                    /** @var numeric-string $taxRate */
                    $taxRate = (string) ($lineData['tax_rate'] ?? '0');

                    $lineSubtotal = $this->numericString((string) $lineData['line_total']);
                    $lineTax = bcmul($lineSubtotal, bcdiv($taxRate, '100', 4), $this->scale());

                    $subtotal = bcadd($subtotal, $lineSubtotal, $this->scale());
                    $taxAmount = bcadd($taxAmount, $lineTax, $this->scale());

                    /** @var Service|null $updateLineService */
                    $updateLineService = isset($lineData['service_id']) ? $updateServices->get($lineData['service_id']) : null;
                    /** @var Product|null $updateLineProduct */
                    $updateLineProduct = isset($lineData['product_id']) ? $updateProducts->get($lineData['product_id']) : null;

                    $updateDefaultName = $updateLineService !== null
                        ? (string) $updateLineService->name
                        : ($updateLineProduct !== null ? (string) $updateLineProduct->name : '');

                    DocumentLine::create([
                        'document_id' => $documentModel->id,
                        'product_id' => $lineData['product_id'] ?? null,
                        'service_id' => $lineData['service_id'] ?? null,
                        'line_number' => $index + 1,
                        'description' => $lineData['description'],
                        'designation_default_snapshot' => $updateDefaultName !== '' ? mb_substr($updateDefaultName, 0, 500) : null,
                        'quantity' => (string) $lineData['quantity'],
                        'free_quantity' => (string) ($lineData['free_quantity'] ?? '0'),
                        'unit_price' => (string) $lineData['unit_price'],
                        'discount_percent' => isset($lineData['discount_percent']) ? (string) $lineData['discount_percent'] : null,
                        'discount_amount' => isset($lineData['discount_amount']) ? (string) $lineData['discount_amount'] : null,
                        'tax_rate' => $taxRate,
                        'line_total' => $lineSubtotal,
                        'landed_unit_cost' => $lineData['landed_unit_cost'] ?? null,
                        'price_entry_mode' => (string) ($lineData['price_entry_mode'] ?? PriceEntryMode::Unit->value),
                        'is_bonus_line' => (bool) ($lineData['is_bonus_line'] ?? false),
                        'notes' => $lineData['notes'] ?? null,
                    ]);
                }

                $total = bcadd($subtotal, $taxAmount, $this->scale());

                $documentModel->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                ]);
            }

            // Update vehicle context only if vehicle_context was provided in request
            $this->attachVehicleContext($documentModel, $vehicleContext, $hasVehicleContext, $this->vehicleContextBuilder);

            /** @var Document $freshDocument */
            $freshDocument = $documentModel->fresh($this->defaultRelations());

            return $this->documentResponse($freshDocument, 200, $this->scale());
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
            $documentModel = DB::transaction(function () use ($companyId, $purchaseOrder, $user): Document {
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
                return $this->purchaseOrderService->confirm($lockedDocument, $user->id);
            });
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('INVALID_STATUS_TRANSITION', $e->getMessage());
        }

        /** @var Document $freshDocument */
        $freshDocument = $documentModel->fresh($this->defaultRelations());

        return $this->documentResponse($freshDocument, 200, $this->scale());
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
    public function receive(ReceiveGoodsRequest $request, string $purchaseOrder): JsonResponse
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
            $validated = $request->validated();

            /** @var array<string, string>|null $quantities */
            $quantities = $validated['quantities'] ?? null;

            /** @var array<string, array{batch_number: string, expiry_date: string, manufacturing_date?: string}>|null $batches */
            $batches = $validated['batches'] ?? null;

            /** @var array<string, string>|null $freeQuantities */
            $freeQuantities = $validated['free_quantities'] ?? null;

            /** @var array<string, string>|null $receivedUnitPrices */
            $receivedUnitPrices = $validated['received_unit_prices'] ?? null;

            /** @var string|null $priceOverrideReason */
            $priceOverrideReason = $validated['price_override_reason'] ?? null;
            $saveAsDraft = (bool) ($validated['save_as_draft'] ?? false);

            if (is_array($freeQuantities) && count($freeQuantities) > 0 && ! $this->purchaseBonusGate->enabledFor($this->companyContext->requireCompany())) {
                return $this->validationErrorResponse('GOODS_RECEIPT_FAILED', 'free_quantities is not enabled for this company.');
            }

            if ($saveAsDraft) {
                $draftReceipt = $this->goodsReceiptService->createDraft(
                    $documentModel,
                    is_array($quantities) ? $quantities : [],
                    is_array($batches) ? $batches : [],
                    is_array($freeQuantities) ? $freeQuantities : [],
                    is_array($receivedUnitPrices) ? $receivedUnitPrices : [],
                    $priceOverrideReason,
                    $user->id,
                );
                $updatedDocument = new GoodsReceiptResult(
                    $documentModel->fresh(['lines']) ?? $documentModel,
                    $draftReceipt,
                );
            } elseif ((is_array($quantities) && count($quantities) > 0) || (is_array($freeQuantities) && count($freeQuantities) > 0)) {
                // Partial receipt with specified quantities (and optional batch data)
                $updatedDocument = $this->goodsReceiptService->receiveGoods(
                    $documentModel,
                    is_array($quantities) ? $quantities : [],
                    is_array($batches) ? $batches : [],
                    is_array($freeQuantities) ? $freeQuantities : [],
                    is_array($receivedUnitPrices) ? $receivedUnitPrices : [],
                    $priceOverrideReason,
                    $user->id,
                );
            } else {
                // Receive all remaining quantities
                $updatedDocument = $this->goodsReceiptService->receiveAll($documentModel, $user->id);
            }

            // Get receipt status for response
            $receiptStatus = $this->goodsReceiptService->getReceiptStatus($updatedDocument->purchaseOrder);

            return response()->json([
                'data' => DocumentData::fromModel($updatedDocument->purchaseOrder, true, $this->scale()),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'receipt_status' => $receiptStatus,
                    'goods_receipt' => GoodsReceiptData::fromModel($updatedDocument->receipt, withLines: false),
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

    /**
     * Return receipt-ledger lines for supplier-invoice prefill.
     *
     * GET /api/v1/purchase-orders/{purchaseOrder}/receipt-lines?uninvoiced=1
     */
    public function receiptLines(Request $request, string $purchaseOrder): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::PurchaseOrder)
            ->find($purchaseOrder);

        if ($documentModel === null) {
            return $this->notFoundResponse('Purchase order');
        }

        $receiptIds = GoodsReceipt::query()
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('purchase_order_id', $purchaseOrder);

        $query = GoodsReceiptLine::query()
            ->with('goodsReceipt')
            ->postedReceipts()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('goods_receipt_id', $receiptIds);

        $uninvoiced = $request->query('uninvoiced');
        if ($uninvoiced === '1' || $uninvoiced === 'true') {
            $query->whereColumn('received_qty', '>', 'quantity_invoiced');
        }

        $lines = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GoodsReceiptLine $line): array => [
                'id' => $line->id,
                'receipt_number' => $line->goodsReceipt->receipt_number,
                'external_reference' => $line->goodsReceipt->external_reference,
                'external_date' => $line->goodsReceipt->external_date?->toDateString(),
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'received_qty' => $line->received_qty,
                'free_qty' => $line->free_qty,
                'quantity_invoiced' => $line->quantity_invoiced,
                'free_quantity_invoiced' => $line->free_quantity_invoiced,
                'accrual_unit_cost' => $line->accrual_unit_cost,
                'received_unit_price' => $line->received_unit_price,
                'po_line_id' => $line->po_line_id,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $lines,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
