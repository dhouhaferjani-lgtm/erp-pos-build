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
use App\Modules\Document\Domain\Enums\DeliveryComplianceCode;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Exceptions\DeliveryRequiredBeforeInvoiceException;
use App\Modules\Document\Domain\Exceptions\GuidedDeliveryCannotBeGeneratedException;
use App\Modules\Document\Domain\Exceptions\GuidedDeliveryNoLongerApplicableException;
use App\Modules\Document\Domain\Services\DeliveryComplianceGate;
use App\Modules\Document\Domain\Services\DeliveryNoteFromDocumentFactory;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Document\Presentation\Requests\UpdateDocumentRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
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
        private readonly DeliveryComplianceGate $deliveryComplianceGate,
        private readonly DeliveryNoteFromDocumentFactory $deliveryNoteFactory,
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
     * Fold document-level taxes (applies_to = DOCUMENT_TOTAL — e.g. the
     * Tunisian stamp duty, 1.000 TND on a TAX_INVOICE) into a draft's totals.
     *
     * Both `store()` and `update()` compute the per-line VAT with a hand-rolled
     * loop, which by construction can only ever see LINE_ITEMS taxes. Document
     * -level taxes were therefore applied for the first time at
     * `confirm()`, where {@see TaxCalculationService::calculateDocumentTaxes()}
     * runs — so a Draft invoice's on-screen/API total was short by exactly the
     * stamp until it was confirmed (money-campaign MTP-DOC-01/03/04, TAX-01,
     * DSC-01). This helper runs the SAME service on the same persisted lines so
     * a draft's tax_amount/total already equal its post-confirmation values.
     *
     * Only the `documentTaxTotal` component is taken: the service's line-item
     * step only counts a rate that matches an active LINE_ITEMS
     * TaxConfiguration, so consuming its `totalTax` here would silently ZERO
     * the VAT of any line carrying an explicitly supplied rate that has no
     * configuration row (a new P0 regression at create time). The hand-rolled
     * per-line VAT is left exactly as it was.
     *
     * @param  numeric-string  $subtotal
     * @param  numeric-string  $lineTaxAmount
     * @return array{0: numeric-string, 1: numeric-string} [tax_amount, total]
     */
    private function withDocumentLevelTaxes(Document $document, string $subtotal, string $lineTaxAmount): array
    {
        $scale = $this->scale();

        // The service reads $document->lines; re-read them from the DB so the
        // just-persisted lines are visible (the relation is not yet loaded).
        $document->setRelation('lines', $document->lines()->get());

        /** @var numeric-string $documentTaxTotal */
        $documentTaxTotal = $this->taxCalculationService->calculateDocumentTaxes($document)->documentTaxTotal;

        /** @var numeric-string $taxAmount */
        $taxAmount = bcadd($lineTaxAmount, $documentTaxTotal, $scale);
        /** @var numeric-string $total */
        $total = bcadd($subtotal, $taxAmount, $scale);

        return [$taxAmount, $total];
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

            // Fold document-level taxes (stamp duty) in so the Draft's totals
            // already equal its post-confirmation totals.
            [$taxAmount, $total] = $this->withDocumentLevelTaxes($document, $subtotal, $taxAmount);
            $document->update([
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);

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

                // Same document-level tax pipeline as store()/confirm(), so an
                // edited draft's totals stay equal to its post-confirmation
                // totals instead of dropping the stamp duty again.
                [$taxAmount, $total] = $this->withDocumentLevelTaxes($documentModel, $subtotal, $taxAmount);

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

        // Delivery compliance. 🔁 Wave 3 T25f: this used to be a SECOND
        // implementation of the rule (`checkDeliveryNotesDelivered()`, deleted),
        // walking the source order's payload by hand and therefore blind to
        // DN → invoice-converted invoices. It now asks the same gate the posting
        // service asks, so the guided modal and the refusal cannot disagree.
        $deliveryStatus = $this->deliveryComplianceGate->evaluate($documentModel);

        // 🆕 T25b — the COMPLIANCE refusal gets its own machine code and its own
        // payload. It is not "your delivery notes are in the wrong state"; it is
        // "this jurisdiction does not permit this document to exist yet", and the
        // guided flow it points at CREATES a delivery note rather than confirming
        // one. Collapsing it into DELIVERY_NOT_COMPLETED would send the frontend
        // to a modal that has nothing to confirm.
        if ($deliveryStatus->code === DeliveryComplianceCode::DeliveryRequiredBeforeInvoice
            && $deliveryStatus->resolvedPolicy !== null) {
            return response()->json([
                'error' => [
                    'code' => DeliveryComplianceCode::DeliveryRequiredBeforeInvoice->value,
                    'message' => $deliveryStatus->message,
                    'details' => (new DeliveryRequiredBeforeInvoiceException(
                        policy: $deliveryStatus->resolvedPolicy->policy->value,
                        policySource: $deliveryStatus->resolvedPolicy->source,
                        draftDeliveryNotes: $deliveryStatus->draftDeliveryNotes,
                        canAutoConfirm: $deliveryStatus->canAutoConfirm,
                        blockedReason: $deliveryStatus->blockedReason,
                    ))->toPayload(),
                ],
            ], 422);
        }

        if (! $deliveryStatus->isCompliant()) {
            // Return structured error with draft DN details for frontend modal
            return response()->json([
                'error' => [
                    'code' => 'DELIVERY_NOT_COMPLETED',
                    'message' => $deliveryStatus->message,
                    'details' => [
                        // The wire value is the pre-T25f vocabulary on purpose:
                        // `InvoiceDetailPage.tsx:159` branches on the literal
                        // 'draft_dns_found' to decide whether to open the guided
                        // modal. The typed code lives on the enum; this is its
                        // frozen presentation.
                        'status' => $deliveryStatus->code === DeliveryComplianceCode::DraftDeliveryNotes
                            ? 'draft_dns_found'
                            : 'error',
                        'draft_dns' => $deliveryStatus->draftDeliveryNotes,
                        'can_auto_confirm' => $deliveryStatus->canAutoConfirm,
                    ],
                ],
            ], 422);
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
     * THE REQUIRED PATH for a standalone goods invoice (Wave 3 T25c / D-30).
     *
     * ── WHY A SIBLING AND NOT A WIDENING OF confirmDeliveriesAndPost() ──
     * That endpoint exists to CONFIRM delivery notes an order already has. It
     * hard-refuses `NO_SOURCE_ORDER`, reads delivery-note ids only from the
     * order's payload, and refuses `NO_DELIVERY_NOTES` when there are none —
     * every one of which is correct for its own job and fatal for this one. And
     * even if a note were created by other means, nothing would write the
     * linkage: `DeliveredQuantityResolver` reads
     * `invoice.payload['source_delivery_note_ids']` (written only by the DN →
     * invoice converter) and the order shape. Without that write, create → confirm
     * → re-post is refused AGAIN, and the compliance gate has no reachable
     * compliant path. That is the defect this endpoint exists to close.
     *
     * The steps, in one transaction. 📌 D-28 registration is PENDING (3C) —
     * candidate C-5. This docblock previously claimed "D-28 composite C-3", which
     * was false twice over: C-3 is TAKEN (`DeliveryNoteController::confirm`, the
     * final-gate convergent Critical), and nothing was registered — the register
     * itself is deferred to 3C by ruling.
     *
     *   0. 🔒 lock the invoice row and RE-DECIDE on it — everything checked above
     *      this transaction was checked on an unlocked read (fix round 1, P1-2);
     *   1. generate a DRAFT delivery note from the invoice's PHYSICAL lines;
     *   2. 🚨 write the linkage on the invoice payload — the shape the resolver
     *      already reads — in the SAME transaction, never after;
     *   3. confirm the delivery note (issues stock, seals the DN chain);
     *   4. post the invoice — `hasEverIssuedGoods()` is now true, so T25b passes;
     *   5. mark the note invoiced, so it does not report itself to the 418
     *      accrual as delivered-but-never-invoiced (fix round 1, P1-1);
     *   6. commit as one act: if the post fails, the delivery, the stock movement
     *      and the linkage all roll back with it.
     *
     * POST /api/v1/invoices/{invoice}/create-delivery-and-post
     */
    public function createDeliveryAndPost(Request $request, string $invoice): JsonResponse
    {
        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::Invoice)
            ->with(['lines.product', 'sourceDocument', 'partner'])
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

        $deliveryStatus = $this->deliveryComplianceGate->evaluate($documentModel);

        // This endpoint is ONLY for the population T25b refuses. Anything else
        // has a different remedy and must not be routed here — an invoice with
        // draft delivery notes belongs in confirm-deliveries-and-post, and a
        // compliant invoice belongs in plain post.
        if ($deliveryStatus->code !== DeliveryComplianceCode::DeliveryRequiredBeforeInvoice) {
            return $this->validationErrorResponse(
                'DELIVERY_CREATION_NOT_APPLICABLE',
                'This invoice does not need a delivery note created for it.'
            );
        }

        if (! $deliveryStatus->canAutoConfirm) {
            return $this->validationErrorResponse(
                'DELIVERY_CANNOT_BE_GENERATED',
                $deliveryStatus->blockedReason ?? 'A delivery note cannot be generated for this invoice.'
            );
        }

        try {
            return DB::transaction(function () use ($documentModel): JsonResponse {
                // 0 — 🚨 THE LOCK, FIRST (fix round 1 / inv P1-2). Everything above
                // ran OUTSIDE this transaction, so two concurrent submits can both
                // reach here. Without the lock the loser applies a STALE payload —
                // clobbering the linkage and the T25e stamp on a document the
                // winner has already SEALED (`payload` is not covered by the
                // immutability trigger) — confirms a FRESH draft delivery note, so
                // the only-draft guard cannot fire, and issues the SAME goods from
                // stock a second time; `post()` then early-returns and the operator
                // gets a 200. `lockForUpdate()` re-reads the row and holds it, so
                // the loser blocks here until the winner commits and then observes
                // the moved document. Same idiom as
                // `DocumentPostingService::cancel()`.
                /** @var Document $documentModel */
                $documentModel = Document::query()
                    ->whereKey($documentModel->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $documentModel->load(['lines.product', 'sourceDocument', 'partner']);

                // Re-decide on the LOCKED row, not on what we read before it. Both
                // conditions matter: the status (the winner may have posted it) and
                // the gate verdict (the winner may have delivered the goods, which
                // makes this endpoint the wrong remedy).
                if (! $documentModel->isConfirmed()
                    || $this->deliveryComplianceGate->evaluate($documentModel)->code
                        !== DeliveryComplianceCode::DeliveryRequiredBeforeInvoice) {
                    throw GuidedDeliveryNoLongerApplicableException::becauseTheInvoiceMoved();
                }

                // 0b — the generator's two inputs, resolved UNDER THE LOCK (fix
                // round 2 / inv N-3). They used to be read before the lock and
                // carried in, which is the same stale-read shape P1-2 closed one
                // level up: between that read and the lock another request can
                // deactivate the location, move it to another company or delete
                // the partner, and this transaction would then issue stock at a
                // location it had already stopped being allowed to use. Two
                // queries to remove the window.
                //
                // The delivery note copies the partner's name and address onto
                // itself, so a partnerless invoice cannot produce one.
                $partner = Partner::query()
                    ->where('company_id', $documentModel->company_id)
                    ->find($documentModel->partner_id);

                if ($partner === null) {
                    throw GuidedDeliveryCannotBeGeneratedException::noPartner();
                }

                // 🔁 Fix round 1 / inv P2-3: the SAME resolver the feasibility
                // predicate used to answer `canAutoConfirm`. Resolving it twice,
                // two ways, is how the gate came to promise a guided path this
                // endpoint then declined — and, in the other direction, to
                // declare un-generatable an invoice this endpoint would have
                // handled.
                $location = $this->deliveryComplianceGate->resolveDeliveryLocation($documentModel);

                if ($location === null) {
                    throw GuidedDeliveryCannotBeGeneratedException::noResolvableLocation();
                }

                // 1 — generate
                $deliveryNote = $this->deliveryNoteFactory->createDraftFrom(
                    source: $documentModel,
                    partner: $partner,
                    location: $location,
                    notes: 'Created from invoice '.$documentModel->document_number.' before posting',
                    requireCompleteFefoAllocation: true,
                );

                // 2 — 🚨 LINKAGE. Without this the invoice re-post is refused
                // again, because nothing else writes the key the resolver reads.
                // Legal on this document: it is Confirmed, not SEALED, and the
                // immutability trigger fires only on SEALED rows and does not
                // cover `payload` in any case.
                $payload = $documentModel->payload ?? [];
                $existing = is_array($payload['source_delivery_note_ids'] ?? null)
                    ? $payload['source_delivery_note_ids']
                    : [];
                $payload['source_delivery_note_ids'] = array_values(array_unique(
                    array_merge($existing, [$deliveryNote->id])
                ));
                $documentModel->update(['payload' => $payload]);
                $documentModel->refresh();

                // 3 — confirm (issues stock, seals the DN fiscal chain)
                $confirmed = $this->deliveryNoteService->confirm($deliveryNote);

                // 4 — post; the gate now sees goods issued
                $postedInvoice = $this->postingService->post($documentModel);

                // 5 — 🚨 MARK THE NOTE INVOICED (fix round 1 / inv P1-1). Mirrors
                // `SalesOrderToInvoiceConverter::markDeliveryNotesAsInvoiced()`,
                // with its own `invoiced_via` so the two paths stay
                // distinguishable. Without it this note reports itself, forever,
                // as "delivered, never invoiced": `invoiced_at` had exactly two
                // writers in `app/` and neither is on this path, while
                // `UninvoicedDeliveryNoteService` lists precisely that shape.
                // Under `require_delivery_first` this is THE path for every
                // standalone goods invoice, so the 418 year-end accrual would
                // accrue revenue ON TOP of revenue this same transaction
                // recognised and sealed.
                $notePayload = $confirmed->payload ?? [];
                $notePayload['invoiced_at'] = now()->toDateTimeString();
                $notePayload['invoice_id'] = $postedInvoice->id;
                $notePayload['invoiced_via'] = 'pre_post_delivery';
                $confirmed->update(['payload' => $notePayload]);

                return response()->json([
                    'data' => DocumentData::fromModel($postedInvoice, true, $this->scale()),
                    'meta' => [
                        'timestamp' => now()->toIso8601String(),
                        'invoice_fiscal_hash' => $postedInvoice->fiscal_hash,
                        'invoice_chain_sequence' => $postedInvoice->chain_sequence,
                        'confirmed_delivery_notes' => [[
                            'id' => $confirmed->id,
                            'number' => $confirmed->document_number,
                            'fiscal_hash' => $confirmed->fiscal_hash,
                            'chain_sequence' => $confirmed->chain_sequence,
                        ]],
                    ],
                ]);
            });
        } catch (GuidedDeliveryCannotBeGeneratedException $e) {
            $message = $e->reason === 'FEFO_ALLOCATION_FAILED_CONFIRM_MANUALLY_WITH_BATCH'
                ? __('documents.guided_delivery.fefo_allocation_failed')
                : $e->reason;

            return response()->json([
                'error' => [
                    'code' => 'DELIVERY_CANNOT_BE_GENERATED',
                    'message' => $message,
                    'reason' => $e->reason,
                ],
            ], 422);
        } catch (GuidedDeliveryNoLongerApplicableException $e) {
            // Same machine code as the pre-transaction check: the client sees ONE
            // refusal for "this invoice does not need a delivery note created for
            // it", whichever side of the row lock detected it.
            return $this->validationErrorResponse('DELIVERY_CREATION_NOT_APPLICABLE', $e->getMessage());
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
}
