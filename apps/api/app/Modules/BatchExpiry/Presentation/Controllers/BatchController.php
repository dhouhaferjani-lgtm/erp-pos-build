<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BatchExpiry\Domain\Repositories\BatchRepositoryInterface;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\BatchExpiry\Presentation\Requests\CreateBatchRequest;
use App\Modules\BatchExpiry\Presentation\Requests\UpdateBatchRequest;
use App\Modules\BatchExpiry\Presentation\Resources\BatchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BatchController extends Controller
{
    public function __construct(
        private readonly BatchRepositoryInterface $batchRepository,
        private readonly FEFOInventoryService $fefoService
    ) {}

    /**
     * List batches with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $request->user()->company_id;

        $filters = [
            'product_id' => $request->input('product_id'),
            'is_active' => $request->input('is_active'),
            'is_expired' => $request->input('is_expired'),
            'is_recalled' => $request->input('is_recalled'),
            'expiring_within_days' => $request->input('expiring_within_days'),
        ];

        $filters = array_filter($filters, fn ($value) => $value !== null);

        $batches = $this->batchRepository->getByCompany($companyId, $filters);

        return BatchResource::collection($batches);
    }

    /**
     * Get single batch details.
     */
    public function show(string $uuid): JsonResponse
    {
        $batch = $this->batchRepository->findByUuid($uuid);

        if (! $batch) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $batch->load(['product', 'batchStock.location']);

        return response()->json([
            'data' => new BatchResource($batch),
        ]);
    }

    /**
     * Create new batch.
     */
    public function store(CreateBatchRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validated();
        $data['tenant_id'] = $user->tenant_id;
        $data['company_id'] = $user->company_id;

        // Check for duplicate batch number
        $existing = $this->batchRepository->findByBatchNumber(
            $user->company_id,
            $data['product_id'],
            $data['batch_number']
        );

        if ($existing) {
            return response()->json([
                'error' => [
                    'code' => 'DUPLICATE_BATCH_NUMBER',
                    'message' => 'A batch with this number already exists for this product',
                ],
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
        $batch = $this->batchRepository->findByUuid($uuid);

        if (! $batch) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $this->batchRepository->update($batch, $request->validated());
        $batch->refresh()->load(['product', 'batchStock']);

        return response()->json([
            'data' => new BatchResource($batch),
        ]);
    }

    /**
     * Deactivate batch (soft delete).
     */
    public function destroy(string $uuid): JsonResponse
    {
        $batch = $this->batchRepository->findByUuid($uuid);

        if (! $batch) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $this->batchRepository->delete($batch);

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

        $batch = $this->batchRepository->findByUuid($uuid);

        if (! $batch) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $batch->recall($request->input('reason'));
        $batch->refresh()->load(['product', 'batchStock']);

        return response()->json([
            'data' => new BatchResource($batch),
        ]);
    }

    /**
     * Get expiring products within threshold.
     */
    public function expiring(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $daysThreshold = $request->input('days', 30);
        $locationId = $request->input('location_id');

        $batches = $this->fefoService->getExpiringProducts($companyId, $daysThreshold, $locationId);

        return response()->json([
            'data' => BatchResource::collection($batches),
        ]);
    }

    /**
     * Get batch stock levels by location.
     */
    public function stock(string $uuid): JsonResponse
    {
        $batch = $this->batchRepository->findByUuid($uuid);

        if (! $batch) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_FOUND',
                    'message' => 'Batch not found',
                ],
            ], 404);
        }

        $stockLevels = $this->fefoService->getBatchStockByLocation($batch->id);

        return response()->json([
            'data' => $stockLevels->map(fn ($stock) => [
                'location_id' => $stock->location_id,
                'location_name' => $stock->location->name,
                'quantity' => $stock->quantity,
                'reserved_quantity' => $stock->reserved_quantity,
                'available_quantity' => $stock->available_quantity,
            ]),
        ]);
    }

    /**
     * Get available batches for POS (FEFO ordered).
     * This endpoint is used by POS to get batch suggestions for sale.
     */
    public function posAvailableBatches(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $locationId = $request->input('location_id');
        $quantity = $request->input('quantity');

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
    public function productBatchStock(int $productId): JsonResponse
    {
        $batches = $this->batchRepository->getByProduct($productId, activeOnly: true);

        return response()->json([
            'data' => BatchResource::collection($batches),
        ]);
    }
}
