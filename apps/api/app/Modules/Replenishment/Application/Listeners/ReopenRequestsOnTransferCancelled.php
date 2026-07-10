<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\Listeners;

use App\Modules\Inventory\Domain\Events\StockTransferCancelled;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentFulfillmentType;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\Events\ReplenishmentReopened;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReopenRequestsOnTransferCancelled
{
    public function handle(StockTransferCancelled $event): void
    {
        $requests = ReplenishmentRequest::query()
            ->where('tenant_id', $event->tenantId)
            ->where('company_id', $event->companyId)
            ->where('status', ReplenishmentStatus::Fulfilled)
            ->where('fulfillment_type', ReplenishmentFulfillmentType::Transfer)
            ->where('fulfillment_id', $event->transferId)
            ->get();

        foreach ($requests as $request) {
            try {
                $this->reopen($request, $event);
            } catch (QueryException $exception) {
                if (! $this->isOpenKeyUniqueViolation($exception)) {
                    $this->logFailure($request, $event, $exception);

                    continue;
                }

                try {
                    $this->mergeCollision($request, $event);
                } catch (Throwable $mergeException) {
                    $this->logFailure($request, $event, $mergeException);
                }
            } catch (Throwable $exception) {
                $this->logFailure($request, $event, $exception);
            }
        }
    }

    private function reopen(ReplenishmentRequest $request, StockTransferCancelled $event): void
    {
        DB::transaction(function () use ($request, $event): void {
            $locked = ReplenishmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            $locked->update([
                'status' => ReplenishmentStatus::Pending,
                'fulfillment_type' => null,
                'fulfillment_id' => null,
                'processed_by_user_id' => null,
                'processed_at' => null,
                'note' => $this->appendCancellationNote($locked->note, $event->transferNumber),
            ]);
            ReplenishmentReopened::dispatch(
                requestId: $locked->id,
                cancelledTransferId: $event->transferId,
                occurredAt: now()->toIso8601String(),
            );
        });
    }

    private function mergeCollision(ReplenishmentRequest $request, StockTransferCancelled $event): void
    {
        DB::transaction(function () use ($request, $event): void {
            $reopened = ReplenishmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            $open = ReplenishmentRequest::query()
                ->whereKeyNot($reopened->id)
                ->where('tenant_id', $reopened->tenant_id)
                ->where('company_id', $reopened->company_id)
                ->where('location_id', $reopened->location_id)
                ->where('product_id', $reopened->product_id)
                ->when(
                    $reopened->variant_id === null,
                    fn ($query) => $query->whereNull('variant_id'),
                    fn ($query) => $query->where('variant_id', $reopened->variant_id),
                )
                ->whereIn('status', [
                    ReplenishmentStatus::Pending,
                    ReplenishmentStatus::InProgress,
                ])
                ->lockForUpdate()
                ->firstOrFail();

            $open->requested_qty = match (true) {
                $open->requested_qty !== null && $reopened->requested_qty !== null => bcadd($open->requested_qty, $reopened->requested_qty, 4), // precision-ok: replenishment quantity is decimal(15,4), canonical scale 4
                $reopened->requested_qty !== null => $reopened->requested_qty,
                default => $open->requested_qty,
            };
            $open->request_count += $reopened->request_count;
            $open->note = $this->appendCancellationNote(
                $this->appendNote($open->note, $reopened->note),
                $event->transferNumber,
            );
            $open->last_requested_at = now();
            $open->save();

            $reopened->update([
                'status' => ReplenishmentStatus::Cancelled,
                'fulfillment_type' => null,
                'fulfillment_id' => null,
                'processed_by_user_id' => $event->cancelledByUserId,
                'processed_at' => now(),
                'rejection_reason' => 'superseded_after_transfer_cancelled',
            ]);
            ReplenishmentReopened::dispatch(
                requestId: $open->id,
                cancelledTransferId: $event->transferId,
                occurredAt: now()->toIso8601String(),
            );
        });
    }

    private function appendCancellationNote(?string $note, string $transferNumber): string
    {
        return $this->appendNote($note, "transfer {$transferNumber} cancelled");
    }

    private function appendNote(?string $existing, ?string $addition): string
    {
        if ($addition === null || $addition === '') {
            return $existing ?? '';
        }

        return $existing === null || $existing === '' ? $addition : $existing."\n---\n".$addition;
    }

    private function isOpenKeyUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)
            && (str_contains($exception->getMessage(), 'replenishment_open_')
                || str_contains($exception->getMessage(), 'replenishment_requests.company_id'));
    }

    private function logFailure(
        ReplenishmentRequest $request,
        StockTransferCancelled $event,
        Throwable $exception,
    ): void {
        Log::error('Failed to reopen replenishment request for a cancelled transfer.', [
            'request_id' => $request->id,
            'transfer_id' => $event->transferId,
            'exception' => $exception,
        ]);
    }
}
