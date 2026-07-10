<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\Listeners;

use App\Modules\Inventory\Domain\Events\StockTransferInitiated;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentFulfillmentType;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\Events\ReplenishmentFulfilled;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Shared\Contracts\TransferLineReader;
use App\Shared\DTOs\TransferLineDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SettleRequestsOnTransferInitiated
{
    public function __construct(private readonly TransferLineReader $transferLineReader) {}

    public function handle(StockTransferInitiated $event): void
    {
        $lines = $this->transferLineReader->linesForTransfer(
            $event->tenantId,
            $event->companyId,
            $event->transferId,
        );

        foreach ($lines as $line) {
            try {
                $this->settleLine($event, $line);
            } catch (Throwable $exception) {
                Log::error('Failed to settle replenishment requests for a transfer line.', [
                    'transfer_id' => $event->transferId,
                    'product_id' => $line->productId,
                    'variant_id' => $line->variantId,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function settleLine(StockTransferInitiated $event, TransferLineDTO $line): void
    {
        DB::transaction(function () use ($event, $line): void {
            $requests = ReplenishmentRequest::query()
                ->where('tenant_id', $event->tenantId)
                ->where('company_id', $event->companyId)
                ->where('location_id', $event->destinationLocationId)
                ->where('product_id', $line->productId)
                ->when(
                    $line->variantId === null,
                    fn ($query) => $query->whereNull('variant_id'),
                    fn ($query) => $query->where('variant_id', $line->variantId),
                )
                ->whereIn('status', [
                    ReplenishmentStatus::Pending,
                    ReplenishmentStatus::InProgress,
                ])
                ->lockForUpdate()
                ->get();

            foreach ($requests as $request) {
                $request->update([
                    'status' => ReplenishmentStatus::Fulfilled,
                    'fulfillment_type' => ReplenishmentFulfillmentType::Transfer,
                    'fulfillment_id' => $event->transferId,
                    'processed_by_user_id' => $event->initiatedByUserId,
                    'processed_at' => now(),
                ]);
                ReplenishmentFulfilled::dispatch(
                    requestId: $request->id,
                    fulfillmentType: ReplenishmentFulfillmentType::Transfer,
                    fulfillmentId: $event->transferId,
                    processedByUserId: $event->initiatedByUserId,
                    occurredAt: now()->toIso8601String(),
                );
            }
        });
    }
}
