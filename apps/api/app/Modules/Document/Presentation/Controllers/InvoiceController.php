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
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Document\Presentation\Requests\UpdateDocumentRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Treasury\Application\Services\CloseInvoiceWithToleranceService;
use App\Modules\Treasury\Domain\Exceptions\InvoiceAlreadyPaidException;
use App\Modules\Treasury\Domain\Exceptions\ToleranceExceededException;
use App\Modules\Vehicle\Application\Services\VehicleContextBuilder;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Support\Traits\PaginatesResults;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Controller for Invoice document operations.
 *
 * This controller handles all invoice-specific endpoints:
 * - List invoices (with pagination and filters)
 * - Create new invoices
 * - View single invoice
 * - Update draft invoices
 * - Delete draft invoices
 * - Confirm invoices (Draft -> Confirmed)
 * - Post invoices (Confirmed -> Posted with fiscal hash chain)
 *
 * Invoices follow a specific lifecycle:
 * Draft -> Confirmed -> Posted (fiscally sealed)
 *
 * Posted invoices are immutable and added to the fiscal hash chain
 * for NF525/ZATCA compliance.
 */
class InvoiceController extends Controller
{
    use HandlesDocuments;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly DocumentNumberingService $numberingService,
        private readonly DocumentPostingService $postingService,
        private readonly DeliveryNoteService $deliveryNoteService,
        private readonly TaxCalculationService $taxCalculationService,
        private readonly VehicleContextBuilder $vehicleContextBuilder,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly CloseInvoiceWithToleranceService $closeInvoiceWithToleranceService,
        private readonly DocumentLineTaxResolver $lineTaxResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Read a line's discount field (validated numeric by the FormRequest) as a
     * numeric-string for the discount arithmetic, or null when absent.
     *
     * @param  array<string, mixed>  $line
     * @return numeric-string|null
     */
    private function lineDiscount(array $line, string $key): ?string
    {
        if (! isset($line[$key])) {
            return null;
        }

        /** @var numeric-string $value */
        $value = (string) $line[$key];

        return $value;
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
     * List all invoices with pagination and filters.
     *
     * Supports filters:
     * - status: Filter by DocumentStatus enum value
     * - partner_id: Filter by partner UUID
     * - search: Search by document number (LIKE match)
     * - date_from: Filter documents from this date (inclusive)
     * - date_to: Filter documents up to this date (inclusive)
     * - product_id: Filter documents containing a specific product
     *
     * GET /api/v1/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);

        $query = $this->baseQuery()->ofType(DocumentType::Invoice);

        // Apply common filters from the trait
        $query = $this->applyFilters($query, $request);

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
     * Get a single invoice by ID.
     *
     * GET /api/v1/invoices/{invoice}
     */
    public function show(Request $request, string $invoice): JsonResponse
    {
        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::Invoice)
            ->with($this->detailRelations())
            ->find($invoice);

        if ($documentModel === null) {
            return $this->notFoundResponse('Invoice');
        }

        return $this->documentResponse($documentModel, 200, $this->scale());
    }

    /**
     * Create a new invoice.
     *
     * POST /api/v1/invoices
     */
    public function store(CreateDocumentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var array<int, array{description: string, quantity: string, unit_price: string, product_id?: string, service_id?: string, tax_rate?: string|null, tax_configuration_id?: string|null, discount_percent?: string, discount_amount?: string, notes?: string}> $lines */
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

        return DB::transaction(function () use ($request, $tenantId, $companyId, $company, $validated, $lines, $vehicleContext): JsonResponse {
            // Generate document number
            $documentNumber = $this->numberingService->generateNumber($tenantId, $companyId, DocumentType::Invoice);

            // Batch-fetch products and services for tax defaults + snapshot capture (1 query each)
            $productIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->toArray();
            $serviceIds = collect($lines)->pluck('service_id')->filter()->unique()->values()->toArray();
            /** @var Collection<array-key, Product> $products */
            $products = Product::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereIn('id', $productIds)->get()->keyBy('id');
            /** @var Collection<array-key, Service> $services */
            $services = Service::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereIn('id', $serviceIds)->get()->keyBy('id');
            $lines = $this->lineTaxResolver->resolve($lines, $company, $products);

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

                // NET of any line discount (before tax) — the canonical helper
                // keeps the draft subtotal and tax base consistent with the
                // fiscal recompute done on confirm.
                $lineSubtotal = DocumentLine::computeLineTotal(
                    $quantity,
                    $unitPrice,
                    $this->lineDiscount($line, 'discount_percent'),
                    $this->lineDiscount($line, 'discount_amount'),
                    $this->scale(),
                );
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
                'type' => DocumentType::Invoice,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::Invoice),
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
                // Stored line_total is NET of the line discount (before tax).
                $lineTotal = DocumentLine::computeLineTotal(
                    $quantity,
                    $unitPrice,
                    $this->lineDiscount($lineData, 'discount_percent'),
                    $this->lineDiscount($lineData, 'discount_amount'),
                    $this->scale(),
                );

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
                $this->createVehicleContext($document, $vehicleContext, $tenantId, $companyId, $this->vehicleContextBuilder);
            }

            /** @var Document $freshDocument */
            $freshDocument = $document->fresh($this->defaultRelations());

            return $this->documentCreatedResponse($freshDocument, $this->scale(), $request);
        });
    }

    /**
     * Update an existing invoice.
     *
     * Only draft invoices can be updated. Confirmed or posted invoices are immutable.
     *
     * PATCH /api/v1/invoices/{invoice}
     */
    public function update(UpdateDocumentRequest $request, string $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::Invoice)
            ->find($invoice);

        if ($documentModel === null) {
            return $this->notFoundResponse('Invoice');
        }

        if ($documentModel->isFiscallyImmutable()) {
            return $this->fiscalImmutabilityErrorResponse();
        }

        if (! $documentModel->isEditable()) {
            return $this->notEditableErrorResponse();
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var array<int, array{description: string, quantity: string, unit_price: string, product_id?: string, service_id?: string, tax_rate?: string|null, tax_configuration_id?: string|null, discount_percent?: string, discount_amount?: string, notes?: string}>|null $lines */
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

        return DB::transaction(function () use ($request, $documentModel, $validated, $lines, $vehicleContext, $hasVehicleContext, $company): JsonResponse {
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
                $lines = $this->lineTaxResolver->resolve($lines, $company, $updateProducts);

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

                    // NET of any line discount (before tax); also persisted as the
                    // line_total below via $lineSubtotal.
                    $lineSubtotal = DocumentLine::computeLineTotal(
                        $quantity,
                        $unitPrice,
                        $this->lineDiscount($lineData, 'discount_percent'),
                        $this->lineDiscount($lineData, 'discount_amount'),
                        $this->scale(),
                    );
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
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount_percent' => isset($lineData['discount_percent']) ? (string) $lineData['discount_percent'] : null,
                        'discount_amount' => isset($lineData['discount_amount']) ? (string) $lineData['discount_amount'] : null,
                        'tax_rate' => $taxRate,
                        'line_total' => $lineSubtotal,
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

            return $this->documentResponse($freshDocument, 200, $this->scale(), $request);
        });
    }

    /**
     * Delete an invoice (soft delete).
     *
     * Only draft invoices can be deleted. Confirmed or posted invoices cannot be deleted.
     *
     * DELETE /api/v1/invoices/{invoice}
     */
    public function destroy(Request $request, string $invoice): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::Invoice)
            ->find($invoice);

        if ($documentModel === null) {
            return $this->notFoundResponse('Invoice');
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
     * Confirm an invoice (Draft -> Confirmed).
     *
     * Uses pessimistic locking inside the transaction to prevent race conditions
     * when two requests try to confirm the same invoice simultaneously.
     *
     * POST /api/v1/invoices/{invoice}/confirm
     */
    public function confirm(Request $request, string $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $companyId = $this->companyContext->requireCompanyId();

        // Initial existence check (without lock - for fast 404 response)
        $exists = Document::forCompany($companyId)
            ->ofType(DocumentType::Invoice)
            ->where('id', $invoice)
            ->exists();

        if (! $exists) {
            return $this->notFoundResponse('Invoice');
        }

        try {
            $documentModel = DB::transaction(function () use ($companyId, $invoice): Document {
                // Re-fetch with pessimistic lock inside transaction to prevent race conditions
                $lockedDocument = Document::forCompany($companyId)
                    ->ofType(DocumentType::Invoice)
                    ->lockForUpdate()
                    ->find($invoice);

                if ($lockedDocument === null) {
                    throw new \DomainException('Invoice not found');
                }

                // Check status inside the lock - this is the idempotency check
                if (! $lockedDocument->isDraft()) {
                    // Already confirmed - return silently (idempotent)
                    if ($lockedDocument->status === DocumentStatus::Confirmed) {
                        return $lockedDocument;
                    }
                    throw new \DomainException('Only draft invoices can be confirmed');
                }

                // For invoices, simple status change (posting creates GL entries)
                $lockedDocument->update([
                    'status' => DocumentStatus::Confirmed,
                    'confirmed_at' => now(),
                    'confirmed_by' => auth()->id(),
                ]);

                // Calculate and snapshot taxes for immutable audit trail
                $taxResult = $this->taxCalculationService->calculateDocumentTaxes($lockedDocument);

                // Update document with calculated totals
                $lockedDocument->update([
                    'tax_amount' => $taxResult->totalTax,
                    'total' => $taxResult->total,
                ]);

                // Snapshot for immutable audit trail
                $this->taxCalculationService->snapshotTaxDetails($lockedDocument, $taxResult);

                return $lockedDocument;
            });
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('INVALID_STATUS_TRANSITION', $e->getMessage());
        }

        /** @var Document $freshDocument */
        $freshDocument = $documentModel->fresh($this->defaultRelations());

        return $this->documentResponse($freshDocument, 200, $this->scale());
    }

    /**
     * Post an invoice (Confirmed -> Posted).
     *
     * Posting makes the invoice final and immutable. For fiscal documents,
     * this creates an entry in the SHA-256 hash chain for NF525 compliance.
     *
     * The invoice becomes:
     * - Fiscally sealed (cannot be modified)
     * - Part of the hash chain (tamper-proof)
     * - Ready for GL entry creation by event listeners
     *
     * POST /api/v1/invoices/{invoice}/post
     */
    public function post(Request $request, string $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::Invoice)
            ->with(['lines.product', 'sourceDocument'])
            ->find($invoice);

        if ($documentModel === null) {
            return $this->notFoundResponse('Invoice');
        }

        if (! $documentModel->isConfirmed()) {
            return $this->validationErrorResponse(
                'INVOICE_NOT_CONFIRMED',
                'Only confirmed invoices can be posted'
            );
        }

        // Tunisia fiscal compliance: Check if physical products have been delivered
        $hasPhysicalProducts = $this->invoiceHasPhysicalProducts($documentModel);
        if ($hasPhysicalProducts) {
            $deliveryCheckResult = $this->checkDeliveryNotesDelivered($documentModel);
            if ($deliveryCheckResult !== null) {
                // Return structured error with draft DN details for frontend modal
                return response()->json([
                    'error' => [
                        'code' => 'DELIVERY_NOT_COMPLETED',
                        'message' => $deliveryCheckResult['message'],
                        'details' => [
                            'status' => $deliveryCheckResult['status'],
                            'draft_dns' => $deliveryCheckResult['draft_dns'] ?? [],
                            'can_auto_confirm' => $deliveryCheckResult['can_auto_confirm'] ?? false,
                        ],
                    ],
                ], 422);
            }
        }

        try {
            $freshDocument = $this->postingService->post($documentModel);

            return response()->json([
                'data' => DocumentData::fromModel($freshDocument, true, $this->scale()),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'fiscal_hash' => $freshDocument->fiscal_hash,
                    'chain_sequence' => $freshDocument->chain_sequence,
                ],
            ]);
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('POSTING_FAILED', $e->getMessage());
        }
    }

    /**
     * Confirm all auto-created delivery notes and post the invoice in one atomic operation.
     *
     * This endpoint is called from the frontend modal when user clicks "Confirm & Post".
     * It confirms all draft delivery notes (issuing stock and adding to fiscal chain),
     * then posts the invoice.
     *
     * POST /api/v1/invoices/{invoice}/confirm-deliveries-and-post
     */
    public function confirmDeliveriesAndPost(Request $request, string $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::Invoice)
            ->with(['lines.product', 'sourceDocument'])
            ->find($invoice);

        if ($documentModel === null) {
            return $this->notFoundResponse('Invoice');
        }

        if (! $documentModel->isConfirmed()) {
            return $this->validationErrorResponse(
                'INVOICE_NOT_CONFIRMED',
                'Only confirmed invoices can be posted'
            );
        }

        // Get source order
        $sourceOrder = $documentModel->sourceDocument;
        if ($sourceOrder === null || $sourceOrder->type !== DocumentType::SalesOrder) {
            return $this->validationErrorResponse(
                'NO_SOURCE_ORDER',
                'Invoice does not have a source sales order'
            );
        }

        // Get delivery notes from order
        $orderPayload = $sourceOrder->payload ?? [];
        $deliveryNoteIds = $orderPayload['delivery_note_ids'] ?? [];

        if (empty($deliveryNoteIds)) {
            return $this->validationErrorResponse(
                'NO_DELIVERY_NOTES',
                'No delivery notes found for this order'
            );
        }

        $deliveryNotes = Document::whereIn('id', $deliveryNoteIds)
            ->where('type', DocumentType::DeliveryNote)
            ->with('lines')
            ->get();

        // Filter to only draft DNs
        $draftDns = $deliveryNotes->filter(fn ($dn) => $dn->isDraft());

        if ($draftDns->isEmpty()) {
            return $this->validationErrorResponse(
                'NO_DRAFT_DNS',
                'No draft delivery notes to confirm'
            );
        }

        try {
            return DB::transaction(function () use ($draftDns, $documentModel): JsonResponse {
                $confirmedDns = [];

                // Confirm each draft DN (issues stock, adds to fiscal chain)
                foreach ($draftDns as $dn) {
                    $confirmed = $this->deliveryNoteService->confirm($dn);
                    $confirmedDns[] = [
                        'id' => $confirmed->id,
                        'number' => $confirmed->document_number,
                        'fiscal_hash' => $confirmed->fiscal_hash,
                        'chain_sequence' => $confirmed->chain_sequence,
                    ];
                }

                // Post the invoice (adds to fiscal chain)
                $postedInvoice = $this->postingService->post($documentModel);

                return response()->json([
                    'data' => DocumentData::fromModel($postedInvoice, true, $this->scale()),
                    'meta' => [
                        'timestamp' => now()->toIso8601String(),
                        'invoice_fiscal_hash' => $postedInvoice->fiscal_hash,
                        'invoice_chain_sequence' => $postedInvoice->chain_sequence,
                        'confirmed_delivery_notes' => $confirmedDns,
                    ],
                ]);
            });
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('OPERATION_FAILED', $e->getMessage());
        }
    }

    /**
     * Close a partially-paid invoice by writing off the residual balance to GL 658.
     *
     * Eligibility (enforced by CloseInvoiceWithToleranceService):
     *   - balance_due > 0 (not already settled)
     *   - balance_due strictly less than both the absolute and percentage
     *     payment-tolerance thresholds for the company.
     *
     * Successful close: posts Dr 658 / Cr AR via existing
     * GeneralLedgerService::createPaymentToleranceJournalEntry, sets the
     * invoice status to Paid + balance_due to 0, and dispatches
     * InvoiceClosedWithTolerance.
     *
     * Permission: payments.allocate (gated on the route).
     *
     * POST /api/v1/invoices/{invoice}/close-with-tolerance
     */
    public function closeWithTolerance(Request $request, string $invoice): JsonResponse
    {
        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::Invoice)
            ->find($invoice);

        if ($documentModel === null) {
            return $this->notFoundResponse('Invoice');
        }

        // Block premature close attempts at workflow boundary: only Posted invoices
        // can be closed-with-tolerance. Paid is allowed to fall through so the
        // service surfaces the more specific ALREADY_PAID idempotency error.
        if (
            $documentModel->status !== DocumentStatus::Posted
            && $documentModel->status !== DocumentStatus::Paid
        ) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Invoice must be Posted to close with tolerance.',
                    'details' => ['status' => $documentModel->status->value],
                ],
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->closeInvoiceWithToleranceService->close(
                invoiceId: $documentModel->id,
                closedBy: (string) $user->id,
            );
        } catch (ToleranceExceededException $e) {
            return response()->json([
                'error' => [
                    'code' => 'TOLERANCE_EXCEEDED',
                    'message' => 'Invoice balance exceeds payment tolerance threshold.',
                    'details' => [
                        'remaining_balance' => $e->remainingBalance,
                        'max_amount' => $e->maxAmount,
                        'percentage' => $e->percentage,
                    ],
                ],
            ], 422);
        } catch (InvoiceAlreadyPaidException $e) {
            return response()->json([
                'error' => [
                    'code' => 'ALREADY_PAID',
                    'message' => 'Invoice is already settled; close-with-tolerance is a no-op.',
                    'details' => ['invoice_id' => $e->invoiceId],
                ],
            ], 422);
        }

        /** @var Document $fresh */
        $fresh = $documentModel->fresh($this->detailRelations());

        return response()->json([
            'data' => DocumentData::fromModel($fresh, true, $this->scale()),
            'meta' => [
                'tolerance_writeoff' => [
                    'amount' => $result->amountWrittenOff,
                    'gl_entry_id' => $result->glEntryId,
                ],
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Check if invoice has any physical products.
     */
    private function invoiceHasPhysicalProducts(Document $invoice): bool
    {
        foreach ($invoice->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            if ($line->product !== null && $line->product->is_physical) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all delivery notes for this invoice have been delivered.
     *
     * Returns array with status and details, or null if all checks pass.
     *
     * @return array{status: string, message: string, draft_dns?: array<int, mixed>, can_auto_confirm?: bool}|null
     */
    private function checkDeliveryNotesDelivered(Document $invoice): ?array
    {
        // Get source order if invoice was created from order
        $sourceOrder = $invoice->sourceDocument;
        if ($sourceOrder === null || $sourceOrder->type !== DocumentType::SalesOrder) {
            // No source order - this is a standalone invoice, no delivery check needed
            return null;
        }

        // Check if order has delivery notes
        $orderPayload = $sourceOrder->payload ?? [];
        $deliveryNoteIds = $orderPayload['delivery_note_ids'] ?? [];

        if (empty($deliveryNoteIds)) {
            return [
                'status' => 'error',
                'message' => 'Physical products must be delivered before posting invoice. No delivery notes found for the source order.',
            ];
        }

        // Get all delivery notes and check their status
        $deliveryNotes = Document::whereIn('id', $deliveryNoteIds)
            ->where('type', DocumentType::DeliveryNote)
            ->with('lines')
            ->get();

        // Check for draft delivery notes (auto-created but not confirmed)
        $draftDns = $deliveryNotes->filter(fn ($dn) => $dn->isDraft());

        if ($draftDns->isNotEmpty()) {
            // Check if all draft DNs are auto-created (can be batch confirmed)
            $canAutoConfirm = $draftDns->every(function ($dn) {
                $payload = $dn->payload ?? [];

                return isset($payload['auto_created']) && $payload['auto_created'] === true;
            });

            return [
                'status' => 'draft_dns_found',
                'message' => 'Delivery notes must be confirmed before posting invoice',
                'draft_dns' => $draftDns->map(fn ($dn) => [
                    'id' => $dn->id,
                    'number' => $dn->document_number,
                    'total' => $dn->total,
                    'line_count' => $dn->lines->count(),
                ])->values()->toArray(),
                'can_auto_confirm' => $canAutoConfirm,
            ];
        }

        // Check if confirmed DNs are fully delivered
        foreach ($deliveryNotes as $dn) {
            if ($dn->isDraft()) {
                continue; // Already handled above
            }

            // Check if all lines have been fully delivered
            $fullyDelivered = true;
            foreach ($dn->lines as $line) {
                $qtyDelivered = $line->quantity_delivered ?? '0.00';
                $qty = $line->quantity;

                // If any line hasn't been fully delivered, mark as not complete
                if (bccomp((string) $qtyDelivered, (string) $qty, 4) < 0) {
                    $fullyDelivered = false;
                    break;
                }
            }

            if (! $fullyDelivered) {
                return [
                    'status' => 'error',
                    'message' => sprintf(
                        'Delivery note %s must be marked as fully delivered before posting invoice. Please update the delivery quantities.',
                        $dn->document_number
                    ),
                ];
            }
        }

        return null; // All delivery notes are confirmed and delivered
    }
}
