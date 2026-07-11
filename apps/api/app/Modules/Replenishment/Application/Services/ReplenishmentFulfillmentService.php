<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\Services;

use App\Modules\Document\Application\DTOs\DraftPurchaseOrderData;
use App\Modules\Document\Application\DTOs\DraftPurchaseOrderLineData;
use App\Modules\Document\Application\Services\DraftPurchaseOrderService;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentFulfillmentType;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\Events\ReplenishmentFulfilled;
use App\Modules\Replenishment\Domain\Events\ReplenishmentRejected;
use App\Modules\Replenishment\Domain\Events\ReplenishmentSourced;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReplenishmentFulfillmentService
{
    public function __construct(
        private readonly StockTransferService $stockTransferService,
        private readonly DraftPurchaseOrderService $draftPurchaseOrderService,
    ) {}

    /**
     * @param  list<array{request_id: string, quantity: numeric-string}>  $lines
     * @return list<string>
     */
    public function createTransfers(
        string $tenantId,
        string $companyId,
        string $sourceLocationId,
        string $userId,
        array $lines,
    ): array {
        $requests = $this->openRequests($companyId, array_column($lines, 'request_id'));
        $quantityByRequest = [];
        foreach ($lines as $line) {
            $quantityByRequest[$line['request_id']] = $line['quantity'];
        }
        /** @var array<string, list<ReplenishmentRequest>> $groups */
        $groups = [];
        foreach ($requests as $request) {
            $groups[$request->location_id][] = $request;
        }

        return DB::transaction(function () use (
            $tenantId,
            $companyId,
            $sourceLocationId,
            $userId,
            $groups,
            $quantityByRequest,
        ): array {
            /** @var list<InitiateTransferData> $transfers */
            $transfers = [];
            /** @var list<InitiateTransferLineData> $allLines */
            $allLines = [];
            foreach ($groups as $destinationLocationId => $group) {
                $requestIds = array_map(static fn (ReplenishmentRequest $request): string => $request->id, $group);
                sort($requestIds, SORT_STRING);
                $transferLines = array_map(
                    static fn (ReplenishmentRequest $request): InitiateTransferLineData => new InitiateTransferLineData(
                        productId: $request->product_id,
                        quantity: $quantityByRequest[$request->id],
                        variantId: $request->variant_id,
                    ),
                    $group,
                );
                array_push($allLines, ...$transferLines);
                $transfers[] = new InitiateTransferData(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    sourceLocationId: $sourceLocationId,
                    destinationLocationId: $destinationLocationId,
                    initiatedByUserId: $userId,
                    lines: $transferLines,
                    idempotencyKey: 'replenishment:'.sha1(
                        $sourceLocationId.':'.$destinationLocationId.':'.implode(',', $requestIds),
                    ),
                    // Replenishment must not reach into batch tables (module
                    // boundary). Let the Inventory module auto-allocate the
                    // source's batches FEFO for any batch-tracked line.
                    autoAllocateBatchesFefo: true,
                );
            }

            $this->stockTransferService->assertSourceAvailability(
                $tenantId,
                $companyId,
                $sourceLocationId,
                $allLines,
            );

            $transferIds = [];
            foreach ($transfers as $transferData) {
                $transferIds[] = $this->stockTransferService->initiate($transferData)->id;
            }

            return $transferIds;
        }, attempts: 3);
    }

    /**
     * @param  list<array{request_id: string, quantity: numeric-string}>  $lines
     */
    public function createPurchaseOrder(
        string $tenantId,
        string $companyId,
        string $supplierId,
        string $destinationLocationId,
        ?string $existingDocumentId,
        string $userId,
        array $lines,
    ): string {
        return DB::transaction(function () use (
            $tenantId,
            $companyId,
            $supplierId,
            $destinationLocationId,
            $existingDocumentId,
            $userId,
            $lines,
        ): string {
            $requests = $this->openRequests($companyId, array_column($lines, 'request_id'));
            $quantityByRequest = [];
            foreach ($lines as $line) {
                $quantityByRequest[$line['request_id']] = $line['quantity'];
            }
            $documentLines = [];
            foreach ($requests as $request) {
                $documentLines[] = new DraftPurchaseOrderLineData(
                    productId: $request->product_id,
                    quantity: $quantityByRequest[$request->id],
                    variantId: $request->variant_id,
                    lineLocationId: $request->location_id,
                );
            }
            $document = $existingDocumentId === null
                ? $this->draftPurchaseOrderService->createDraft(new DraftPurchaseOrderData(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    supplierId: $supplierId,
                    destinationLocationId: $destinationLocationId,
                    createdByUserId: $userId,
                    lines: $documentLines,
                ))
                : $this->draftPurchaseOrderService->appendLines(
                    $existingDocumentId,
                    $companyId,
                    $supplierId,
                    $documentLines,
                );

            foreach ($requests as $request) {
                if ($destinationLocationId === $request->location_id) {
                    $request->update([
                        'status' => ReplenishmentStatus::Fulfilled,
                        'fulfillment_type' => ReplenishmentFulfillmentType::PurchaseOrder,
                        'fulfillment_id' => $document->id,
                        'processed_by_user_id' => $userId,
                        'processed_at' => now(),
                    ]);
                    ReplenishmentFulfilled::dispatch(
                        requestId: $request->id,
                        fulfillmentType: ReplenishmentFulfillmentType::PurchaseOrder,
                        fulfillmentId: $document->id,
                        processedByUserId: $userId,
                        occurredAt: now()->toIso8601String(),
                    );
                } else {
                    $request->update([
                        'status' => ReplenishmentStatus::InProgress,
                        'sourcing_document_id' => $document->id,
                        'processed_by_user_id' => $userId,
                        'processed_at' => now(),
                    ]);
                    ReplenishmentSourced::dispatch(
                        requestId: $request->id,
                        sourcingDocumentId: $document->id,
                        processedByUserId: $userId,
                        occurredAt: now()->toIso8601String(),
                    );
                }
            }

            return $document->id;
        });
    }

    /** @param list<string> $requestIds */
    public function reject(string $companyId, array $requestIds, string $reason, string $userId): void
    {
        DB::transaction(function () use ($companyId, $requestIds, $reason, $userId): void {
            $requests = $this->openRequests($companyId, $requestIds);
            foreach ($requests as $request) {
                $request->update([
                    'status' => ReplenishmentStatus::Rejected,
                    'rejection_reason' => $reason,
                    'processed_by_user_id' => $userId,
                    'processed_at' => now(),
                ]);
                ReplenishmentRejected::dispatch(
                    requestId: $request->id,
                    reason: $reason,
                    processedByUserId: $userId,
                    occurredAt: now()->toIso8601String(),
                );
            }
        });
    }

    /**
     * @param  list<string>  $requestIds
     * @return Collection<int, ReplenishmentRequest>
     */
    private function openRequests(string $companyId, array $requestIds): Collection
    {
        $uniqueIds = array_values(array_unique($requestIds));
        $requests = ReplenishmentRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $uniqueIds)
            ->whereIn('status', [ReplenishmentStatus::Pending, ReplenishmentStatus::InProgress])
            ->get();
        if ($requests->count() !== count($uniqueIds)) {
            $openIds = $requests->pluck('id')->all();
            $offendingIds = array_values(array_diff($uniqueIds, $openIds));
            throw ValidationException::withMessages([
                'request_ids' => $offendingIds,
            ]);
        }

        return $requests;
    }
}
