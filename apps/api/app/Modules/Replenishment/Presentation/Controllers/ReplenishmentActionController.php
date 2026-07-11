<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Replenishment\Application\Services\ReplenishmentFulfillmentService;
use App\Modules\Replenishment\Presentation\Requests\CreatePoFromRequestsRequest;
use App\Modules\Replenishment\Presentation\Requests\CreateTransferFromRequestsRequest;
use App\Modules\Replenishment\Presentation\Requests\RejectRequestsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReplenishmentActionController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReplenishmentFulfillmentService $fulfillmentService,
    ) {}

    public function createTransfer(CreateTransferFromRequestsRequest $request): JsonResponse
    {
        Gate::authorize('inventory.transfers.create');
        $companyId = $this->companyContext->requireCompanyId();
        $sourceLocationId = $request->string('source_location_id')->toString();
        $this->assertLocationCompany($sourceLocationId, $companyId, 'source_location_id');
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $rawLines = $request->validated('lines');
        if (! is_array($rawLines)) {
            throw ValidationException::withMessages(['lines' => 'Lines are required.']);
        }
        $lines = $this->actionLines($rawLines);

        try {
            $ids = $this->fulfillmentService->createTransfers(
                $this->companyContext->requireTenantId(),
                $companyId,
                $sourceLocationId,
                $user->id,
                $lines,
            );
        } catch (InsufficientStockException $e) {
            return $this->insufficientStockResponse($e);
        }

        return response()->json(['data' => ['transfer_ids' => $ids]]);
    }

    public function createPo(CreatePoFromRequestsRequest $request): JsonResponse
    {
        Gate::authorize('purchase-orders.create');
        $companyId = $this->companyContext->requireCompanyId();
        $destinationLocationId = $request->string('destination_location_id')->toString();
        $this->assertLocationCompany($destinationLocationId, $companyId, 'destination_location_id');
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $rawLines = $request->validated('lines');
        if (! is_array($rawLines)) {
            throw ValidationException::withMessages(['lines' => 'Lines are required.']);
        }
        $documentId = $this->fulfillmentService->createPurchaseOrder(
            tenantId: $this->companyContext->requireTenantId(),
            companyId: $companyId,
            supplierId: $request->string('supplier_id')->toString(),
            destinationLocationId: $destinationLocationId,
            existingDocumentId: $request->filled('existing_document_id')
                ? $request->string('existing_document_id')->toString()
                : null,
            userId: $user->id,
            lines: $this->actionLines($rawLines),
        );

        return response()->json(['data' => ['document_id' => $documentId]]);
    }

    public function reject(RejectRequestsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $rawIds = $request->validated('request_ids');
        if (! is_array($rawIds)) {
            throw ValidationException::withMessages(['request_ids' => 'Request IDs are required.']);
        }
        $ids = $this->requestIds($rawIds);
        $this->fulfillmentService->reject(
            $this->companyContext->requireCompanyId(),
            $ids,
            $request->string('reason')->toString(),
            $user->id,
        );

        return response()->json(['data' => ['request_ids' => $ids]]);
    }

    /**
     * @param  array<array-key, array<array-key, scalar|null>>  $value
     * @return list<array{request_id: string, quantity: numeric-string}>
     */
    private function actionLines(array $value): array
    {
        $lines = [];
        foreach ($value as $line) {
            if (! isset($line['request_id'], $line['quantity'])
                || ! is_string($line['request_id'])
                || ! is_string($line['quantity'])
                || ! is_numeric($line['quantity'])) {
                throw ValidationException::withMessages(['lines' => 'Invalid action line.']);
            }
            $lines[] = ['request_id' => $line['request_id'], 'quantity' => $line['quantity']];
        }

        return $lines;
    }

    /**
     * @param  array<array-key, scalar|null>  $value
     * @return list<string>
     */
    private function requestIds(array $value): array
    {
        $ids = [];
        foreach ($value as $id) {
            if (! is_string($id)) {
                throw ValidationException::withMessages(['request_ids' => 'Invalid request ID.']);
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function assertLocationCompany(string $locationId, string $companyId, string $field): void
    {
        if (! DB::table('locations')->where('id', $locationId)->where('company_id', $companyId)->exists()) {
            throw ValidationException::withMessages([$field => 'Location does not belong to the active company.']);
        }
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
}
