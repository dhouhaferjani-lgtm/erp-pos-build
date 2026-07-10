<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Application\Services;

use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\Events\ReplenishmentRequestBumped;
use App\Modules\Replenishment\Domain\Events\ReplenishmentRequested;
use App\Modules\Replenishment\Domain\Exceptions\CrossCompanyReplayException;
use App\Modules\Replenishment\Domain\ReplenishmentCaptureReceipt;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReplenishmentCaptureService
{
    public function capture(CaptureRequestData $data): ReplenishmentRequest
    {
        $replay = $this->findReplay($data);
        if ($replay !== null) {
            return $replay;
        }

        try {
            return $this->insertOrBump($data);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $replay = $this->findReplay($data);
            if ($replay !== null) {
                return $replay;
            }

            return $this->insertOrBump($data);
        }
    }

    private function findReplay(CaptureRequestData $data): ?ReplenishmentRequest
    {
        if ($data->clientRequestUuid === null) {
            return null;
        }

        $receipt = ReplenishmentCaptureReceipt::query()
            ->where('tenant_id', $data->tenantId)
            ->where('client_request_uuid', $data->clientRequestUuid)
            ->first();

        if ($receipt === null) {
            return null;
        }

        if ($receipt->company_id !== $data->companyId) {
            throw new CrossCompanyReplayException($data->clientRequestUuid);
        }

        return ReplenishmentRequest::query()->findOrFail($receipt->request_id);
    }

    private function insertOrBump(CaptureRequestData $data): ReplenishmentRequest
    {
        return DB::transaction(function () use ($data): ReplenishmentRequest {
            $open = ReplenishmentRequest::query()
                ->where('company_id', $data->companyId)
                ->where('location_id', $data->locationId)
                ->where('product_id', $data->productId)
                ->when(
                    $data->variantId === null,
                    fn ($query) => $query->whereNull('variant_id'),
                    fn ($query) => $query->where('variant_id', $data->variantId),
                )
                ->whereIn('status', [
                    ReplenishmentStatus::Pending,
                    ReplenishmentStatus::InProgress,
                ])
                ->lockForUpdate()
                ->first();

            $now = now();
            if ($open === null) {
                $row = ReplenishmentRequest::query()->create([
                    'tenant_id' => $data->tenantId,
                    'company_id' => $data->companyId,
                    'location_id' => $data->locationId,
                    'product_id' => $data->productId,
                    'variant_id' => $data->variantId,
                    'requested_qty' => $data->requestedQty,
                    'note' => $data->note,
                    'request_count' => 1,
                    'status' => ReplenishmentStatus::Pending,
                    'source_channel' => $data->channel,
                    'requested_by_user_id' => $data->requestedByUserId,
                    'first_requested_at' => $now,
                    'last_requested_at' => $now,
                    'client_request_uuid' => $data->clientRequestUuid,
                ]);

                $this->recordReceipt($data, $row);
                ReplenishmentRequested::dispatch(
                    requestId: $row->id,
                    tenantId: $row->tenant_id,
                    companyId: $row->company_id,
                    locationId: $row->location_id,
                    productId: $row->product_id,
                    variantId: $row->variant_id,
                    requestedQty: $row->requested_qty,
                    requestedByUserId: $row->requested_by_user_id,
                    channel: $row->source_channel,
                    occurredAt: $now->toIso8601String(),
                );

                return $row;
            }

            $open->requested_qty = match (true) {
                $open->requested_qty !== null && $data->requestedQty !== null => bcadd($open->requested_qty, $data->requestedQty, 4), // precision-ok: replenishment quantity is decimal(15,4), canonical scale 4
                $data->requestedQty !== null => $data->requestedQty,
                default => $open->requested_qty,
            };
            if ($data->note !== null && $data->note !== '') {
                $open->note = $open->note === null || $open->note === ''
                    ? $data->note
                    : $open->note."\n---\n".$data->note;
            }
            $open->request_count++;
            $open->last_requested_at = $now;
            $open->save();

            $this->recordReceipt($data, $open);
            ReplenishmentRequestBumped::dispatch(
                requestId: $open->id,
                tenantId: $open->tenant_id,
                companyId: $open->company_id,
                requestedQty: $open->requested_qty,
                requestCount: $open->request_count,
                occurredAt: $now->toIso8601String(),
            );

            return $open;
        });
    }

    private function recordReceipt(CaptureRequestData $data, ReplenishmentRequest $row): void
    {
        if ($data->clientRequestUuid === null) {
            return;
        }

        ReplenishmentCaptureReceipt::query()->create([
            'tenant_id' => $data->tenantId,
            'company_id' => $data->companyId,
            'client_request_uuid' => $data->clientRequestUuid,
            'request_id' => $row->id,
            'applied_at' => now(),
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
            return false;
        }

        return str_contains($exception->getMessage(), 'replenishment_open_')
            || str_contains($exception->getMessage(), 'replenishment_receipt_uuid_unique')
            || str_contains($exception->getMessage(), 'replenishment_requests.company_id')
            || str_contains($exception->getMessage(), 'replenishment_capture_receipts.tenant_id');
    }
}
