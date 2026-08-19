<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Compliance\Services\UninvoicedDeliveryNoteService;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\CreateDocumentRequest;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Vehicle\Application\Services\VehicleContextBuilder;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Support\Traits\PaginatesResults;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controller for Delivery Note document operations.
 *
 * This controller handles all delivery-note-specific endpoints:
 * - List delivery notes (with pagination and filters)
 * - Create new delivery notes
 * - View single delivery note
 * - Confirm delivery notes (Draft -> Confirmed with fiscal hash chain)
 *
 * Delivery notes are different from invoices:
 * - They are hashed on CONFIRM (when stock moves), not on POST
 * - They do not create GL entries (not an accounting document)
 * - They have their own separate hash chain per company
 * - Required for Tunisia fiscal compliance (tamper-proof delivery documents)
 *
 * Delivery notes are typically not updated after creation - they either
 * get confirmed or cancelled and recreated.
 */
class DeliveryNoteController extends Controller
{
    use HandlesDocuments;
    use PaginatesResults;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly UninvoicedDeliveryNoteService $uninvoicedDeliveryNoteService,
        private readonly DocumentNumberingService $numberingService,
        private readonly DeliveryNoteService $deliveryNoteService,
        private readonly VehicleContextBuilder $vehicleContextBuilder,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly InventoryGlPostingBuffer $glBuffer,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
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
     * List all delivery notes with pagination and filters.
     *
     * Supports filters:
     * - status: Filter by DocumentStatus enum value
     * - partner_id: Filter by partner UUID
     * - search: Search by document number (LIKE match)
     * - date_from: Filter documents from this date (inclusive)
     * - date_to: Filter documents up to this date (inclusive)
     * - product_id: Filter documents containing a specific product
     *
     * GET /api/v1/delivery-notes
     */
    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);
        $company = $this->companyContext->requireCompany();

        $query = $this->baseQuery()
            // Shared-DB compatibility mode has no tenant connection switch; retain
            // the row predicate there while DB-per-tenant mode supplies the same
            // boundary through Stancl's active connection.
            ->forTenant($company->tenant_id)
            ->ofType(DocumentType::DeliveryNote);

        // Apply common filters from the trait
        $query = $this->applyFilters($query, $request);
        $query = $this->applyDeliveryNoteFilters($query, $request);

        $aggregates = $request->query('with_aggregates') === '1'
            ? $this->deliveryNoteAggregates(clone $query, $company->currency)
            : null;

        // Order by created_at desc and id for consistent cursor pagination (in case created_at is the same)
        $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

        if ($request->query->has('page')) {
            $paginator = $query->with('vehicleContext')->paginate($params['per_page']);
            $response = $this->formatOffsetPaginatedResponse($paginator, null, $aggregates);
            $response['data'] = $paginator->getCollection()
                ->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false, $this->scale()))
                ->all();

            return response()->json($response);
        }

        // Use cursor pagination with vehicleContext eager loaded
        $paginator = $query->with('vehicleContext')->cursorPaginate($params['per_page'], ['*'], 'cursor', $params['cursor']);

        // Transform items
        $items = $paginator->getCollection()->map(fn (Document $doc): DocumentData => DocumentData::fromModel($doc, false, $this->scale()))->all();

        $response = [
            'data' => $items,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'links' => [
                'next' => $paginator->nextCursor()?->encode(),
                'prev' => $paginator->previousCursor()?->encode(),
            ],
        ];
        if ($aggregates !== null) {
            $response['aggregates'] = $aggregates;
        }

        return response()->json($response);
    }

    /**
     * List delivery notes that have not yet been invoiced.
     *
     * GET /api/v1/delivery-notes/uninvoiced
     */
    public function uninvoiced(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $filters = $this->toBillFilters($request, $company);

        $response = $this->uninvoicedDeliveryNoteService->getToBillSummary(
            $company->id,
            $filters['location_id'],
            $filters['date_from'],
            $filters['date_to'],
            $filters['partner_search'],
            $filters['periodic_only'],
            $filters['page'],
            $filters['per_page'],
        );
        $response['scope'] = [
            'location_id' => $filters['location_id'],
            'can_view_all_locations' => $filters['can_view_all_locations'],
        ];

        return response()->json($response);
    }

    /**
     * Lazily expand one partner group from the global to-bill queue.
     *
     * GET /api/v1/delivery-notes/uninvoiced/{partner}
     */
    public function uninvoicedForPartner(Request $request, string $partner): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $filters = $this->toBillFilters($request, $company);

        Partner::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
            ->findOrFail($partner);

        return response()->json($this->uninvoicedDeliveryNoteService->getToBillPartnerRows(
            $company->id,
            $partner,
            $filters['location_id'],
            $filters['date_from'],
            $filters['date_to'],
            $filters['partner_search'],
            $filters['periodic_only'],
            $filters['page'],
            $filters['per_page'],
        ));
    }

    /**
     * @return array{
     *   location_id: string|null,
     *   can_view_all_locations: bool,
     *   date_from: Carbon|null,
     *   date_to: Carbon|null,
     *   partner_search: string|null,
     *   periodic_only: bool,
     *   page: int,
     *   per_page: int
     * }
     */
    private function toBillFilters(Request $request, Company $company): array
    {
        $partnerSearch = $request->query('partner_search');
        if (is_string($partnerSearch)) {
            $request->merge(['partner_search' => trim($partnerSearch)]);
        }

        $locationRules = ['nullable', 'string'];
        if ($request->query('location_id') !== 'all') {
            $locationRules[] = 'uuid';
        }

        $validated = $request->validate([
            'location_id' => $locationRules,
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'partner_search' => ['nullable', 'string', 'min:2', 'max:120'],
            'periodic_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requestedLocation = isset($validated['location_id'])
            ? (string) $validated['location_id']
            : null;
        $locationId = null;
        $canViewAllLocations = $this->locationContext->getAllowedLocationIds($company->id) === null;

        if ($requestedLocation === 'all') {
            if (! $canViewAllLocations) {
                throw ValidationException::withMessages([
                    'location_id' => ['All locations is outside your allowed scope.'],
                ]);
            }
        } else {
            $locationId = $this->locationContext->resolveLocationId($requestedLocation, $company->id);
            if ($locationId !== null) {
                $belongsToCompany = Location::query()
                    ->where('id', $locationId)
                    ->where('company_id', $company->id)
                    ->exists();

                if (! $belongsToCompany || ! $this->locationContext->canAccessLocation($locationId, $company->id)) {
                    throw ValidationException::withMessages([
                        'location_id' => ['The selected location is invalid or outside your allowed scope.'],
                    ]);
                }
            } elseif (! $canViewAllLocations) {
                // The implicit path must not grant what the explicit one denies.
                //
                // resolveLocationId() yields null when the company has no ACTIVE location
                // (getDefaultLocation filters is_active on both lookups, and the
                // setLocationId priority is dead in a request). The membership check above
                // sits inside `if ($locationId !== null)`, so this branch used to add no
                // predicate at all and queueQuery served the WHOLE company — to a
                // location-restricted user, in a response that simultaneously declared
                // `can_view_all_locations: false`, while the explicit request for the same
                // scope (`?location_id=all`) is refused with 422 for that very user.
                //
                // Refuse instead of silently widening: a restricted user whose scope cannot
                // be resolved gets the same 422 as the explicit request, never the company.
                // (M5-terminal tenancy-authz F-T3.)
                //
                // Translated, because this message is operator-facing: ToBillPage renders
                // query failures through <QueryError error={query.error} …> (:511-512), so
                // a location-restricted user reads it verbatim in a French or Arabic UI.
                // The sibling refusal at :274 is still a hardcoded English literal — it is
                // PRE-EXISTING (M3, untouched by this wave) and stays RECORDED rather than
                // silently swept in here; it is owed the same treatment in the i18n
                // follow-up that already carries it. (M5-terminal r2, tenancy `F-R2-2`.)
                throw ValidationException::withMessages([
                    'location_id' => [__('documents.to_bill_queue.no_active_location_in_scope')],
                ]);
            }
        }

        return [
            'location_id' => $locationId,
            'can_view_all_locations' => $canViewAllLocations,
            'date_from' => isset($validated['date_from']) ? Carbon::createFromFormat('Y-m-d', (string) $validated['date_from']) : null,
            'date_to' => isset($validated['date_to']) ? Carbon::createFromFormat('Y-m-d', (string) $validated['date_to']) : null,
            'partner_search' => isset($validated['partner_search']) ? (string) $validated['partner_search'] : null,
            'periodic_only' => $request->boolean('periodic_only'),
            'page' => isset($validated['page']) ? (int) $validated['page'] : 1,
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : 25,
        ];
    }

    /**
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    private function applyDeliveryNoteFilters(Builder $query, Request $request): Builder
    {
        if ($request->query('uninvoiced') === '1') {
            $query->whereDeliveryNoteUninvoiced();
        } elseif ($request->query('invoiced') === '1') {
            $query->whereDeliveryNoteInvoiced();
        }

        // `documents.location_id` is a PostgreSQL `uuid` column, so an unvalidated query
        // string binds straight into it and `?location_id=not-a-uuid` raised 22P02 — a 500
        // reachable by any holder of `deliveries.view`. This wave hardened exactly this
        // class three times elsewhere (the conditional `uuid` rule on the queue filter at
        // toBillFilters, and `whereUuid` on documents.show / delivery-notes.show /
        // uninvoiced/{partner}) and missed the filter it added itself. Mirror the queue
        // filter's rule so a malformed value is a 422, not a 500.
        // (M5-terminal tenancy-authz F-T2.)
        $request->validate([
            'location_id' => ['nullable', 'uuid'],
        ]);

        $locationId = $request->query('location_id');
        if (is_string($locationId) && $locationId !== '') {
            $query->where('location_id', $locationId);
        }

        return $query;
    }

    /**
     * @param  Builder<Document>  $query
     * @return array{count: int, total: string, currency: string}
     */
    private function deliveryNoteAggregates(Builder $query, string $currency): array
    {
        $query->where('currency', $currency);

        $scale = $this->scaleResolver->getScale($currency);
        $count = (clone $query)->count();
        $total = (clone $query)
            ->toBase()
            ->selectRaw('CAST(COALESCE(SUM(total), 0) AS TEXT) AS aggregate_total')
            ->value('aggregate_total');

        return [
            'count' => $count,
            'total' => CurrencyScale::bcformatStrict((string) ($total ?? '0'), $scale),
            'currency' => $currency,
        ];
    }

    /**
     * Get a single delivery note by ID.
     *
     * GET /api/v1/delivery-notes/{deliveryNote}
     */
    public function show(Request $request, string $deliveryNote): JsonResponse
    {
        $documentModel = $this->baseQuery()
            ->ofType(DocumentType::DeliveryNote)
            ->with($this->detailRelations())
            ->find($deliveryNote);

        if ($documentModel === null) {
            return $this->notFoundResponse('Delivery note');
        }

        return $this->documentResponse($documentModel, 200, $this->scale());
    }

    /**
     * Create a new delivery note.
     *
     * POST /api/v1/delivery-notes
     */
    public function store(CreateDocumentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        /** @var array<int, array{description: string, quantity: string, unit_price: string, product_id?: string, service_id?: string, tax_rate?: string, discount_percent?: string, discount_amount?: string, notes?: string}> $lines */
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
            $documentNumber = $this->numberingService->generateNumber($tenantId, $companyId, DocumentType::DeliveryNote);

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

                $lineSubtotal = bcmul($quantity, $unitPrice, $this->scale());
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
                'type' => DocumentType::DeliveryNote,
                'fiscal_category' => FiscalCategory::fromDocumentType(DocumentType::DeliveryNote),
                'fiscal_status' => FiscalStatus::Draft,
                'status' => DocumentStatus::Draft,
                'location_id' => $locationId,
                'document_number' => $documentNumber,
                'currency' => $validated['currency'] ?? $company->currency,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ]);

            // Batch-fetch products and services for snapshot capture (1 query each)
            $productIds = collect($lines)->pluck('product_id')->filter()->unique()->values()->toArray();
            $serviceIds = collect($lines)->pluck('service_id')->filter()->unique()->values()->toArray();
            /** @var Collection<int, Product> $products */
            $products = Product::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereIn('id', $productIds)->get()->keyBy('id');
            /** @var Collection<int, Service> $services */
            $services = Service::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereIn('id', $serviceIds)->get()->keyBy('id');

            // Create lines
            foreach ($lines as $index => $lineData) {
                /** @var numeric-string $quantity */
                $quantity = (string) $lineData['quantity'];
                /** @var numeric-string $unitPrice */
                $unitPrice = (string) $lineData['unit_price'];
                $lineTotal = bcmul($quantity, $unitPrice, $this->scale());

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

            return $this->documentCreatedResponse($freshDocument, $this->scale());
        });
    }

    /**
     * Confirm a delivery note (Draft -> Confirmed with fiscal hash chain).
     *
     * Uses pessimistic locking inside the transaction to prevent race conditions
     * when two requests try to confirm the same delivery note simultaneously.
     *
     * When confirmed:
     * - Delivery note is added to the fiscal hash chain (tamper-proof)
     * - Stock is issued (outbound for sales, inbound for purchases)
     * - Stock reservations from source sales order are released
     * - DeliveryNoteConfirmed event is dispatched for audit trail
     *
     * POST /api/v1/delivery-notes/{deliveryNote}/confirm
     */
    public function confirm(Request $request, string $deliveryNote): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $companyId = $this->companyContext->requireCompanyId();

        // Initial existence check (without lock - for fast 404 response)
        $exists = Document::forCompany($companyId)
            ->ofType(DocumentType::DeliveryNote)
            ->where('id', $deliveryNote)
            ->exists();

        if (! $exists) {
            return $this->notFoundResponse('Delivery note');
        }

        try {
            $documentModel = DB::transaction(function () use ($companyId, $deliveryNote): Document {
                // Re-fetch with pessimistic lock inside transaction to prevent race conditions
                $lockedDocument = Document::forCompany($companyId)
                    ->ofType(DocumentType::DeliveryNote)
                    ->with('lines')
                    ->lockForUpdate()
                    ->find($deliveryNote);

                if ($lockedDocument === null) {
                    throw new \DomainException('Delivery note not found');
                }

                // Check status inside the lock - this is the idempotency check
                if (! $lockedDocument->isDraft()) {
                    // Already confirmed - return silently (idempotent)
                    if ($lockedDocument->status === DocumentStatus::Confirmed) {
                        return $lockedDocument;
                    }
                    throw new \DomainException('Only draft delivery notes can be confirmed');
                }

                // Use the DeliveryNoteService for proper lifecycle management with hash chain.
                // Its nested flush defers at depth two; C-3 owns the root tail.
                $confirmed = $this->deliveryNoteService->confirm($lockedDocument, $this->glBuffer);
                $this->glBuffer->flushIfOutermost();

                return $confirmed;
            });
        } catch (\DomainException $e) {
            return $this->validationErrorResponse('INVALID_STATUS_TRANSITION', $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->validationErrorResponse('CONFIGURATION_ERROR', $e->getMessage());
        }

        /** @var Document $freshDocument */
        $freshDocument = $documentModel->fresh($this->defaultRelations());

        return response()->json([
            'data' => DocumentData::fromModel($freshDocument, true, $this->scale()),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'fiscal_hash' => $freshDocument->fiscal_hash,
                'chain_sequence' => $freshDocument->chain_sequence,
            ],
        ]);
    }
}
