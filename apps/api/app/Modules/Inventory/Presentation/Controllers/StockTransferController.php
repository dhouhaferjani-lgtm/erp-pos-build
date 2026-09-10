<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferBatchAllocationData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\DTOs\StockTransferReceiptData;
use App\Modules\Inventory\Application\DTOs\TransferCloseReceiptData;
use App\Modules\Inventory\Application\Services\StockTransferReceiptService;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Application\Services\TransferPayloadBuilder;
use App\Modules\Inventory\Application\Services\TransferReconciliationService;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Exceptions\TransferReceiptFailureException;
use App\Modules\Inventory\Domain\Exceptions\TransferStateException;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use App\Modules\Inventory\Presentation\Requests\CloseStockTransferRequest;
use App\Modules\Inventory\Presentation\Requests\ReceiveStockTransferRequest;
use App\Modules\Inventory\Presentation\Requests\StoreStockTransferRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $service,
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly StockTransferReceiptService $receiptService,
        private readonly TransferPayloadBuilder $payloadBuilder,
        private readonly TransferReconciliationService $reconciliationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        $query = StockTransfer::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['sourceLocation', 'destinationLocation', 'initiatedBy', 'closedBy', 'receipts.receivedBy', 'lines.product.unitOfMeasure', 'lines.variant', 'lines.batchAllocations.batch']);

        // Location scoping: a restricted user only sees transfers whose source
        // OR destination is in their allowed set (so incoming transfers from
        // elsewhere remain visible). NULL allowed set = unrestricted (all).
        $allowedLocationIds = $this->locationContext->getAllowedLocationIds($company->id, $user);
        if ($allowedLocationIds !== null) {
            $query->where(function ($q) use ($allowedLocationIds): void {
                $q->whereIn('source_location_id', $allowedLocationIds)
                    ->orWhereIn('destination_location_id', $allowedLocationIds);
            });
        }

        if ($request->filled('status')) {
            $status = TransferStatus::tryFrom((string) $request->input('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('source_location_id')) {
            $source = (string) $request->input('source_location_id');
            if (Str::isUuid($source)) {
                $query->where('source_location_id', $source);
            }
        }

        if ($request->filled('destination_location_id')) {
            $dest = (string) $request->input('destination_location_id');
            if (Str::isUuid($dest)) {
                $query->where('destination_location_id', $dest);
            }
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min(100, $perPage));

        $transfers = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $transfers->getCollection()->map(fn (StockTransfer $t) => $this->payloadBuilder->build($t))->all(),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'per_page' => $transfers->perPage(),
                'total' => $transfers->total(),
                'last_page' => $transfers->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($transfer)) {
            abort(404);
        }

        /** @var StockTransfer $model */
        $model = StockTransfer::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['sourceLocation', 'destinationLocation', 'initiatedBy', 'closedBy', 'receipts.receivedBy', 'completedBy', 'cancelledBy', 'lines.product.unitOfMeasure', 'lines.variant', 'lines.batchAllocations.batch'])
            ->findOrFail($transfer);

        // A transfer the user cannot see (neither endpoint in their allowed set)
        // is treated as not found, consistent with the index visibility filter.
        if (! $this->canSeeTransfer($model, $company->id, $user)) {
            abort(404);
        }

        return response()->json([
            'data' => $this->payloadBuilder->build($model, includeLines: true),
        ]);
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        // Initiating a transfer moves stock OUT of the source location, so the
        // user must have access to the SOURCE. The destination may be anywhere
        // (you can send stock to places you cannot act at).
        $sourceLocationId = (string) $request->input('source_location_id');
        if (! $this->locationContext->canAccessLocation($sourceLocationId, $company->id, $user)) {
            return $this->locationAccessDeniedResponse($sourceLocationId, $user->id);
        }

        /** @var array<int, array{product_id: string, variant_id?: string|null, quantity: string|int|float, batch_allocations?: array<int, array{batch_id: int|string, quantity: string|int|float}>}> $rawLines */
        $rawLines = $request->input('lines', []);

        $lines = [];
        foreach ($rawLines as $line) {
            /** @var numeric-string $qty */
            $qty = (string) $line['quantity'];
            $batchAllocations = [];
            foreach ($line['batch_allocations'] ?? [] as $allocation) {
                /** @var numeric-string $allocationQty */
                $allocationQty = (string) $allocation['quantity'];
                $batchAllocations[] = new InitiateTransferBatchAllocationData(
                    batchId: (int) $allocation['batch_id'],
                    quantity: $allocationQty,
                );
            }
            // An explicitly-sent null and a missing key both mean "product-level
            // line" (variant_id stays null); a present non-null value is the
            // chosen variant.
            $variantId = array_key_exists('variant_id', $line) && $line['variant_id'] !== null
                ? (string) $line['variant_id']
                : null;
            $lines[] = new InitiateTransferLineData(
                productId: (string) $line['product_id'],
                quantity: $qty,
                variantId: $variantId,
                batchAllocations: $batchAllocations,
            );
        }

        $distributionValue = $request->input('transfer_cost_distribution');
        $distribution = $distributionValue !== null
            ? TransferCostDistribution::from((string) $distributionValue)
            : TransferCostDistribution::ProRataValue;

        /** @var numeric-string $transferCost */
        $transferCost = (string) $request->input('transfer_cost', '0');

        try {
            $transfer = $this->service->initiate(new InitiateTransferData(
                tenantId: $company->tenant_id,
                companyId: $company->id,
                sourceLocationId: (string) $request->input('source_location_id'),
                destinationLocationId: (string) $request->input('destination_location_id'),
                initiatedByUserId: $user->id,
                lines: $lines,
                notes: $request->input('notes'),
                transferCost: $transferCost,
                transferCostLabel: $request->input('transfer_cost_label'),
                transferCostDistribution: $distribution,
                idempotencyKey: $request->input('idempotency_key'),
            ));
        } catch (InsufficientStockException $e) {
            return $this->insufficientStockResponse($e);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_TRANSFER',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $transfer->load(['sourceLocation', 'destinationLocation', 'initiatedBy', 'closedBy', 'receipts.receivedBy', 'lines.product.unitOfMeasure', 'lines.variant', 'lines.batchAllocations.batch']);

        return response()->json([
            'data' => $this->payloadBuilder->build($transfer, includeLines: true),
        ], 201);
    }

    public function complete(Request $request, string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($transfer)) {
            abort(404);
        }

        $existing = StockTransfer::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($transfer);

        // Completing = receiving into the DESTINATION, so require destination access.
        if (($denied = $this->canAccessLocationOrFail($existing->destination_location_id, $company->id, $user)) !== null) {
            return $denied;
        }

        try {
            $completed = $this->service->complete($existing->id, $user->id);
        } catch (TransferReceiptFailureException $e) {
            return $this->receiptFailureResponse($e);
        } catch (TransferStateException $e) {
            return $this->stateExceptionResponse($e);
        }

        $completed->load(['sourceLocation', 'destinationLocation', 'completedBy', 'lines.product.unitOfMeasure', 'lines.variant', 'lines.batchAllocations.batch']);

        return response()->json([
            'data' => $this->payloadBuilder->build($completed, includeLines: true),
        ]);
    }

    public function cancel(Request $request, string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($transfer)) {
            abort(404);
        }

        $existing = StockTransfer::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($transfer);

        // Cancelling restocks the SOURCE location, so require source access.
        if (! $this->locationContext->canAccessLocation($existing->source_location_id, $company->id, $user)) {
            return $this->locationAccessDeniedResponse($existing->source_location_id, $user->id);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $cancelled = $this->service->cancel(
                transferId: $existing->id,
                userId: $user->id,
                reason: $validated['reason'] ?? null,
            );
        } catch (TransferStateException $e) {
            return $this->stateExceptionResponse($e);
        }

        $cancelled->load(['sourceLocation', 'destinationLocation', 'cancelledBy', 'lines.product.unitOfMeasure', 'lines.variant', 'lines.batchAllocations.batch']);

        return response()->json([
            'data' => $this->payloadBuilder->build($cancelled, includeLines: true),
        ]);
    }

    public function receive(ReceiveStockTransferRequest $request, string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();
        if (! Str::isUuid($transfer)) {
            abort(404);
        }
        $model = StockTransfer::query()->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)->findOrFail($transfer);
        if (($denied = $this->canAccessLocationOrFail($model->destination_location_id, $company->id, $user)) !== null) {
            return $denied;
        }
        try {
            $result = $this->receiptService->receive($model->id, $user->id, $request->toPayload(), $company->tenant_id, $company->id);
        } catch (TransferReceiptFailureException $e) {
            return $this->receiptFailureResponse($e);
        } catch (TransferStateException $e) {
            return $this->stateExceptionResponse($e);
        }

        return response()->json(['data' => ['receipt' => $this->receiptPayload($result->receipt), 'transfer' => $this->payloadBuilder->build($result->transfer, includeLines: true)], 'meta' => ['replayed' => $result->replayed]], $result->replayed ? 200 : 201);
    }

    public function close(CloseStockTransferRequest $request, string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();
        if (! Str::isUuid($transfer)) {
            abort(404);
        }
        $model = StockTransfer::query()->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)->findOrFail($transfer);
        if (($denied = $this->canAccessLocationOrFail($model->destination_location_id, $company->id, $user)) !== null) {
            return $denied;
        }
        if ($request->input('disposition') === 'return_to_source' && ($denied = $this->canAccessLocationOrFail($model->source_location_id, $company->id, $user)) !== null) {
            return $denied;
        }
        try {
            $result = $this->receiptService->close($model->id, $user->id, $request->toPayload(), $company->tenant_id, $company->id);
        } catch (TransferReceiptFailureException $e) {
            return $this->receiptFailureResponse($e);
        } catch (TransferStateException $e) {
            return $this->stateExceptionResponse($e);
        }

        return response()->json(['data' => ['receipt' => $this->receiptPayload($result->receipt), 'transfer' => $this->payloadBuilder->build($result->transfer, includeLines: true)], 'meta' => ['replayed' => $result->replayed]], $result->replayed ? 200 : 201);
    }

    public function reconciliation(Request $request, string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();
        if (! Str::isUuid($transfer)) {
            abort(404);
        }
        $model = StockTransfer::query()->where('tenant_id', $company->tenant_id)->where('company_id', $company->id)->findOrFail($transfer);
        if (! $this->canSeeTransfer($model, $company->id, $user)) {
            abort(404);
        }

        return response()->json(['data' => $this->reconciliationService->build($model)]);
    }

    private function canAccessLocationOrFail(string $locationId, string $companyId, User $user): ?JsonResponse
    {
        return $this->locationContext->canAccessLocation($locationId, $companyId, $user) ? null : $this->locationAccessDeniedResponse($locationId, $user->id);
    }

    private function receiptPayload(StockTransferReceipt $receipt): Data
    {
        return $receipt->kind === TransferReceiptKind::Close ? TransferCloseReceiptData::fromModel($receipt) : StockTransferReceiptData::fromModel($receipt);
    }

    private function receiptFailureResponse(TransferReceiptFailureException $e): JsonResponse
    {
        return response()->json(['error' => ['code' => $e->reason->value, 'message' => $e->getMessage(), 'details' => $e->details]], 422);
    }

    /**
     * A transfer is visible when the user is unrestricted (NULL allowed set) or
     * either endpoint is in their allowed locations.
     */
    private function canSeeTransfer(StockTransfer $transfer, string $companyId, User $user): bool
    {
        $allowed = $this->locationContext->getAllowedLocationIds($companyId, $user);

        if ($allowed === null) {
            return true;
        }

        return in_array($transfer->source_location_id, $allowed, true)
            || in_array($transfer->destination_location_id, $allowed, true);
    }

    private function locationAccessDeniedResponse(string $locationId, string $userId): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'LOCATION_ACCESS_DENIED',
                'message' => 'You do not have permission to act on this location.',
                'details' => [
                    'location_id' => $locationId,
                    'user_id' => $userId,
                ],
            ],
        ], 403);
    }

    private function insufficientStockResponse(InsufficientStockException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'INSUFFICIENT_STOCK',
                'message' => $e->getMessage(),
                'details' => [
                    'product_id' => $e->productId,
                    'location_id' => $e->locationId,
                    'requested' => $e->requested,
                    'available' => $e->available,
                ],
            ],
        ], 422);
    }

    private function stateExceptionResponse(TransferStateException $e): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'INVALID_TRANSFER_STATE',
                'message' => $e->getMessage(),
                'details' => [
                    'transfer_id' => $e->transferId,
                    'current_status' => $e->currentStatus->value,
                    'attempted' => $e->attemptedAction,
                ],
            ],
        ], 422);
    }
}
