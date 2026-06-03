<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Exceptions\TransferStateException;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Presentation\Requests\StoreStockTransferRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $service,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $query = StockTransfer::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['sourceLocation', 'destinationLocation', 'initiatedBy', 'lines.product']);

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
            'data' => $transfers->getCollection()->map(fn (StockTransfer $t) => $this->formatTransfer($t))->all(),
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'per_page' => $transfers->perPage(),
                'total' => $transfers->total(),
                'last_page' => $transfers->lastPage(),
            ],
        ]);
    }

    public function show(string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        if (! Str::isUuid($transfer)) {
            abort(404);
        }

        /** @var StockTransfer $model */
        $model = StockTransfer::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['sourceLocation', 'destinationLocation', 'initiatedBy', 'completedBy', 'cancelledBy', 'lines.product'])
            ->findOrFail($transfer);

        return response()->json([
            'data' => $this->formatTransfer($model, includeLines: true),
        ]);
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        /** @var array<int, array{product_id: string, quantity: string|int|float}> $rawLines */
        $rawLines = $request->input('lines', []);

        $lines = [];
        foreach ($rawLines as $line) {
            /** @var numeric-string $qty */
            $qty = (string) $line['quantity'];
            $lines[] = new InitiateTransferLineData(
                productId: (string) $line['product_id'],
                quantity: $qty,
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

        $transfer->load(['sourceLocation', 'destinationLocation', 'initiatedBy', 'lines.product']);

        return response()->json([
            'data' => $this->formatTransfer($transfer, includeLines: true),
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

        try {
            $completed = $this->service->complete($existing->id, $user->id);
        } catch (TransferStateException $e) {
            return $this->stateExceptionResponse($e);
        }

        $completed->load(['sourceLocation', 'destinationLocation', 'completedBy', 'lines.product']);

        return response()->json([
            'data' => $this->formatTransfer($completed, includeLines: true),
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

        $cancelled->load(['sourceLocation', 'destinationLocation', 'cancelledBy', 'lines.product']);

        return response()->json([
            'data' => $this->formatTransfer($cancelled, includeLines: true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTransfer(StockTransfer $transfer, bool $includeLines = false): array
    {
        $payload = [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'transfer_type' => $transfer->transfer_type->value,
            'status' => $transfer->status->value,
            'source_location_id' => $transfer->source_location_id,
            'source_location_name' => $transfer->sourceLocation->name ?? null,
            'destination_location_id' => $transfer->destination_location_id,
            'destination_location_name' => $transfer->destinationLocation->name ?? null,
            'notes' => $transfer->notes,
            'transfer_cost' => $transfer->transfer_cost,
            'transfer_cost_label' => $transfer->transfer_cost_label,
            'transfer_cost_distribution' => $transfer->transfer_cost_distribution->value,
            'initiated_by_user_id' => $transfer->initiated_by_user_id,
            'initiated_by_name' => $transfer->initiatedBy->name ?? null,
            'completed_by_user_id' => $transfer->completed_by_user_id,
            'completed_by_name' => $transfer->completedBy?->name,
            'cancelled_by_user_id' => $transfer->cancelled_by_user_id,
            'cancelled_by_name' => $transfer->cancelledBy?->name,
            'initiated_at' => $transfer->initiated_at?->toIso8601String(),
            'completed_at' => $transfer->completed_at?->toIso8601String(),
            'cancelled_at' => $transfer->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $transfer->cancellation_reason,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'updated_at' => $transfer->updated_at?->toIso8601String(),
        ];

        if ($includeLines) {
            $payload['lines'] = $transfer->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product->name ?? null,
                'product_sku' => $line->product->sku ?? null,
                'quantity' => $line->quantity,
                'unit_cost_snapshot' => $line->unit_cost_snapshot,
                'allocated_transfer_cost' => $line->allocated_transfer_cost,
            ])->all();
        }

        return $payload;
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
