<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Application\Services\LotActionPermissionActivation;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Exceptions\WriteOffAlreadyReversedException;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use App\Modules\BatchExpiry\Domain\Services\BatchWriteOffService;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService;
use App\Modules\BatchExpiry\Presentation\Requests\CreateBatchRequest;
use App\Modules\BatchExpiry\Presentation\Requests\ReverseWriteOffRequest;
use App\Modules\BatchExpiry\Presentation\Requests\TransferBatchStockRequest;
use App\Modules\BatchExpiry\Presentation\Requests\UpdateBatchRequest;
use App\Modules\BatchExpiry\Presentation\Requests\WriteOffBatchRequest;
use App\Modules\BatchExpiry\Presentation\Resources\BatchResource;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Contracts\BatchTraceability\DocumentBatchTraceReader;
use App\Shared\Contracts\BatchTraceability\PosBatchTraceReader;
use App\Shared\Domain\QuantityScale;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class BatchController extends Controller
{
    public function __construct(
        private readonly BatchRepositoryInterface $batchRepository,
        private readonly FEFOInventoryService $fefoService,
        private readonly CompanyContext $companyContext,
        private readonly BatchStockService $batchStockService,
        private readonly BatchWriteOffService $batchWriteOffService,
        private readonly ReverseWriteOffService $reverseWriteOffService,
        private readonly LocationScopeResolver $locationScopeResolver,
        private readonly LocationContext $locationContext,
        private readonly LotActionPermissionActivation $activation,
        private readonly DocumentBatchTraceReader $documentTraceReader,
        private readonly PosBatchTraceReader $posTraceReader,
    ) {}

    /**
     * @param  list<string>  $requestedLocationIds
     * @return list<string>|null
     */
    private function resolvedReadLocationIds(Request $request, array $requestedLocationIds = []): ?array
    {
        if (! $this->activation->enforced()) {
            return null;
        }
        $validated = $request->validate([
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'location_ids' => ['sometimes', 'array', 'list'], 'location_ids.*' => ['uuid'],
        ]);
        $requested = $requestedLocationIds !== [] ? $requestedLocationIds : ($validated['location_ids'] ?? (isset($validated['location_id']) ? [$validated['location_id']] : []));
        /** @var User $user */
        $user = $request->user();
        if ($requested === [] && $this->locationContext->getAllowedLocationIds($this->companyContext->requireCompanyId(), $user) === null) {
            return null;
        }

        return $this->locationScopeResolver->resolve($user, $requested);
    }

    /**
     * @param  list<string>  $locationIds
     * @return list<int>
     */
    private function historicallyVisibleBatchIds(string $tenantId, string $companyId, array $locationIds): array
    {
        return array_values(array_unique(array_merge(
            $this->documentTraceReader->batchIdsVisibleAtLocations($tenantId, $companyId, $locationIds),
            $this->posTraceReader->batchIdsVisibleAtLocations($tenantId, $companyId, $locationIds),
        )));
    }

    private function findVisibleBatchOrFail(Request $request, string $uuid): Batch|JsonResponse
    {
        if (! $this->activation->enforced()) {
            return $this->findBatchOrFail($uuid);
        }
        $company = $this->companyContext->requireCompany();
        $locations = $this->resolvedReadLocationIds($request);
        $history = $locations === null ? [] : $this->historicallyVisibleBatchIds($company->tenant_id, $company->id, $locations);
        $batch = Str::isUuid($uuid) ? $this->batchRepository->findVisibleByUuid($uuid, $company->id, $locations, $history) : null;

        return $batch ?? response()->json(['error' => ['code' => 'BATCH_NOT_FOUND', 'message' => 'Batch not found']], 404);
    }

    /**
     * Find a batch by UUID and verify it belongs to the current company.
     */
    private function findBatchOrFail(string $uuid): Batch|JsonResponse
    {
        if (! Str::isUuid($uuid)) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $batch = $this->batchRepository->findByUuid($uuid);

        if (! $batch) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        if ($batch->company_id !== $this->companyContext->requireCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        return $batch;
    }

    /**
     * List batches with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->companyContext->requireCompanyId();

        $filters = [
            'product_id' => $request->input('product_id'),
            'is_active' => $request->input('is_active'),
            'is_expired' => $request->input('is_expired'),
            'is_recalled' => $request->input('is_recalled'),
            'expiring_within_days' => $request->input('expiring_within_days'),
        ];

        $filters = array_filter($filters, fn ($value) => $value !== null);

        $locations = $this->resolvedReadLocationIds($request);
        $company = $this->companyContext->requireCompany();
        $history = $locations === null ? [] : $this->historicallyVisibleBatchIds($company->tenant_id, $companyId, $locations);
        $batches = $this->batchRepository->getByCompany($companyId, $filters, $locations, $history);

        return BatchResource::collection($batches);
    }

    /**
     * Get single batch details.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $result = $this->findVisibleBatchOrFail($request, $uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        if (! $this->activation->enforced()) {
            $result->load(['product', 'batchStock.location']);
        }

        return response()->json([
            'data' => new BatchResource($result),
        ]);
    }

    /**
     * Create new batch.
     */
    public function store(CreateBatchRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;

        $data = $request->validated();
        $data['tenant_id'] = $company->tenant_id;
        $data['company_id'] = $companyId;

        // Check for duplicate batch number
        $existing = $this->batchRepository->findByBatchNumber(
            $companyId,
            $data['product_id'],
            $data['batch_number']
        );

        if ($existing) {
            return response()->json([
                'error' => [
                    'code' => 'DUPLICATE_BATCH_NUMBER',
                    'message' => 'A batch with this number already exists for this product',
                ],
                ...($this->activation->enforced() ? ['meta' => ['outcome' => 'already_exists']] : []),
            ], 422);
        }

        $batch = $this->batchRepository->create($data);
        $batch->load(['product']);

        return response()->json([
            'data' => new BatchResource($batch),
        ], 201);
    }

    /**
     * Update batch.
     */
    public function update(UpdateBatchRequest $request, string $uuid): JsonResponse
    {
        $result = $this->findBatchOrFail($uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $this->batchRepository->update($result, $request->validated());
        $result->refresh()->load(['product', 'batchStock']);

        return response()->json([
            'data' => new BatchResource($result),
        ]);
    }

    /**
     * Deactivate batch (soft delete).
     */
    public function destroy(string $uuid): JsonResponse
    {
        $result = $this->findBatchOrFail($uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $this->batchRepository->delete($result);

        return response()->json([
            'data' => ['message' => 'Batch deactivated successfully'],
        ]);
    }

    /**
     * Initiate batch recall.
     */
    public function recall(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->findBatchOrFail($uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $result->recall($request->input('reason'));
        $result->refresh()->load(['product', 'batchStock']);

        return response()->json([
            'data' => new BatchResource($result),
        ]);
    }

    /**
     * Get expiring products within threshold.
     */
    public function expiring(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $daysThreshold = (int) $request->input('days', 30);
        $locationId = $request->input('location_id') !== null ? (string) $request->input('location_id') : null;

        if ($this->activation->enforced()) {
            $locationId = $this->resolvedReadLocationIds($request);
        }
        $batches = $this->fefoService->getExpiringProducts($companyId, $daysThreshold, $locationId);

        return response()->json([
            'data' => BatchResource::collection($batches),
        ]);
    }

    /**
     * Get all expired batches that still have available (un-reserved) stock.
     *
     * Intended for the pharmacy expiry write-off UI: lists every expired lot
     * where `available_quantity > 0` so operators can select lots to write off.
     *
     * Optional query parameter:
     *   - `location_id` — scope results to a single storage location.
     */
    public function expired(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $validated = $request->validate([
            'location_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'location_ids' => ['sometimes', 'array', 'list'],
            'location_ids.*' => ['string', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $requestedLocationIds = array_key_exists('location_ids', $validated)
            ? array_values($validated['location_ids'])
            : (($validated['location_id'] ?? null) !== null ? [(string) $validated['location_id']] : []);
        $locationIds = $this->locationScopeResolver->resolve($user, $requestedLocationIds);

        $batches = $this->fefoService->getExpiredBatchesWithStock($companyId, $locationIds);

        return response()->json([
            'data' => BatchResource::collection($batches),
        ]);
    }

    /**
     * Get batch stock levels by location.
     */
    public function stock(Request $request, string $uuid): JsonResponse
    {
        $result = $this->findVisibleBatchOrFail($request, $uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $stockLevels = $this->fefoService->getBatchStockByLocation((string) $result->id, $this->resolvedReadLocationIds($request));

        return response()->json([
            'data' => $stockLevels->map(fn ($stock) => [
                'location_id' => $stock->location_id,
                'location_name' => $stock->location !== null ? $stock->location->name : 'Unknown',
                'quantity' => $stock->quantity,
                'reserved_quantity' => $stock->reserved_quantity,
                'available_quantity' => bcsub($stock->quantity, $stock->reserved_quantity, QuantityScale::SCALE),
            ]),
        ]);
    }

    /**
     * Get available batches for POS (FEFO ordered).
     * This endpoint is used by POS to get batch suggestions for sale.
     */
    public function posAvailableBatches(Request $request, string $productId): JsonResponse
    {
        // api.unmapped.010 (api.inventory): locations is company-scoped
        // (no tenant_id column). Inline validator scoped via
        // ScopedExists::company so a cross-company location_id cannot
        // satisfy the FK validator.
        $companyId = $this->companyContext->requireCompanyId();
        $request->validate([
            'location_id' => ['required', 'uuid', ScopedExists::company('locations', $companyId)],
            'quantity' => [
                'required',
                'numeric',
                'min:0.0001',
                // Positive, up to 4 decimal places — never more, and never
                // scientific notation (precision contract, rule 19). The 11-digit
                // integer ceiling matches the decimal(15,4) storage domain.
                'regex:/^\d{1,11}(\.\d{1,4})?$/',
            ],
        ]);

        $locationId = (string) $request->input('location_id');
        if ($this->activation->enforced()) {
            $this->resolvedReadLocationIds($request, [$locationId]);
        }

        // Quantity stays a decimal string all the way into the FEFO service —
        // a (float) cast here reintroduced ~1e-16 false shortfalls downstream.
        /** @var numeric-string $quantity */
        $quantity = (string) $request->input('quantity');

        $result = $this->fefoService->suggestBatchesForSale(
            $productId,
            $locationId,
            $quantity
        );

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }

    /**
     * Get batch stock for a specific product.
     */
    public function productBatchStock(Request $request, string $productId): JsonResponse
    {
        // api.inventory round-2 (Codex Finding 1): scope by current
        // tenant + company so a foreign-tenant productId returns an
        // empty collection instead of leaking foreign batch records.
        $company = $this->companyContext->requireCompany();
        $batches = $this->batchRepository->getByProduct(
            $company->tenant_id,
            $company->id,
            $productId,
            activeOnly: true,
            locationIds: $this->resolvedReadLocationIds($request),
        );

        // Variant-aware callers (e.g. the stock-transfer batch picker) pass
        // ?variant_id=... so the picker only offers batches of the chosen
        // variant — without it a batch-tracked variant product would show
        // every variant's lots and the user could pick a foreign-variant lot
        // that the server then rejects (422) at submit time.
        $variantId = $request->query('variant_id');
        if (is_string($variantId) && Str::isUuid($variantId)) {
            $batches = $batches
                ->filter(fn (Batch $batch): bool => $batch->variant_id === $variantId)
                ->values();
        }

        return response()->json([
            'data' => BatchResource::collection($batches),
        ]);
    }

    /**
     * Transfer batch stock between locations.
     *
     * POST /api/v1/batches/{uuid}/transfer
     */
    public function transfer(TransferBatchStockRequest $request, string $uuid): JsonResponse
    {
        $result = $this->findBatchOrFail($uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $company = $this->companyContext->requireCompany();

        try {
            /** @var numeric-string $transferQty */
            $transferQty = (string) $request->input('quantity');
            $this->batchStockService->transferBatchStock(
                tenantId: $company->tenant_id,
                batchId: (int) $result->id,
                fromLocationId: (string) $request->input('from_location_id'),
                toLocationId: (string) $request->input('to_location_id'),
                quantity: $transferQty,
                reference: "Batch transfer: {$result->batch_number}",
                userId: (string) auth()->id(),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'TRANSFER_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $result->refresh()->load(['product', 'batchStock.location']);

        return response()->json([
            'data' => new BatchResource($result),
        ]);
    }

    /**
     * Write off batch stock (expiry, damage, etc.) with GL entries.
     *
     * POST /api/v1/batches/{uuid}/write-off
     */
    public function writeOff(WriteOffBatchRequest $request, string $uuid): JsonResponse
    {
        $result = $this->findBatchOrFail($uuid);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        try {
            /** @var numeric-string $writeOffQty */
            $writeOffQty = (string) $request->input('quantity');
            $this->batchWriteOffService->writeOff(
                batch: $result,
                locationId: (string) $request->input('location_id'),
                quantity: $writeOffQty,
                reason: (string) $request->input('reason'),
                userId: (string) auth()->id(),
                notes: $request->input('notes'),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'WRITE_OFF_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $result->refresh()->load(['product', 'batchStock.location']);

        return response()->json([
            'data' => new BatchResource($result),
        ]);
    }

    /**
     * Reverse a previously posted write-off, restoring aggregate + batch stock
     * and posting a reversing journal entry.
     *
     * POST /api/v1/stock-movements/{movementId}/reverse-write-off
     */
    public function reverseWriteOff(ReverseWriteOffRequest $request, string $movementId): JsonResponse
    {
        // Belt-and-suspenders behind the auth:sanctum middleware: never let an
        // empty user id reach the service (a `(string) null` would become '').
        $userId = auth()->id();
        if ($userId === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required',
                ],
            ], 401);
        }

        if (! Str::isUuid($movementId)) {
            return response()->json([
                'error' => [
                    'code' => 'MOVEMENT_NOT_FOUND',
                    'message' => 'Stock movement not found',
                ],
            ], 404);
        }

        // Scope the movement to the current company so a cross-company id is a 404.
        $movement = StockMovement::query()
            ->where('id', $movementId)
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->first();

        if ($movement === null) {
            return response()->json([
                'error' => [
                    'code' => 'MOVEMENT_NOT_FOUND',
                    'message' => 'Stock movement not found',
                ],
            ], 404);
        }

        try {
            $inverse = $this->reverseWriteOffService->reverse(
                original: $movement,
                userId: (string) $userId,
            );
        } catch (WriteOffAlreadyReversedException $e) {
            return response()->json([
                'error' => [
                    'code' => 'WRITE_OFF_ALREADY_REVERSED',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'WRITE_OFF_REVERSAL_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'reversal_movement_id' => $inverse->id,
                'original_movement_id' => $movement->id,
            ],
        ]);
    }
}
