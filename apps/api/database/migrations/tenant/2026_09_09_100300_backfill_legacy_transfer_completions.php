<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        $now = now();

        do {
            $transfers = DB::table('stock_transfers')
                ->where('status', 'completed')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('stock_transfer_receipts')
                        ->whereColumn('stock_transfer_receipts.transfer_id', 'stock_transfers.id');
                })
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();

            foreach ($transfers as $transfer) {
                $this->backfillTransfer($transfer, $now);
            }
        } while ($transfers->count() === self::CHUNK);

        DB::table('stock_transfer_lines')
            ->whereIn('transfer_id', DB::table('stock_transfer_receipts')->select('transfer_id')->where('kind', 'legacy_completion'))
            ->where('quantity_received', 0)
            ->update(['quantity_received' => DB::raw('quantity')]);

        DB::table('stock_transfer_line_batch_allocations')
            ->whereIn('stock_transfer_line_id', DB::table('stock_transfer_lines')
                ->select('id')
                ->whereIn('transfer_id', DB::table('stock_transfer_receipts')->select('transfer_id')->where('kind', 'legacy_completion')))
            ->where('quantity_received', 0)
            ->update(['quantity_received' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        DB::table('stock_transfer_receipts')->where('kind', 'legacy_completion')->delete();
    }

    private function backfillTransfer(stdClass $transfer, Carbon $now): void
    {
        $receiptId = (string) Str::orderedUuid();
        $idempotencyKey = 'sys:legacy-completion:'.$transfer->id;

        DB::table('stock_transfer_receipts')->insert([
            'id' => $receiptId,
            'tenant_id' => $transfer->tenant_id,
            'company_id' => $transfer->company_id,
            'transfer_id' => $transfer->id,
            'receipt_number' => 'TRR-LEGACY-'.$transfer->transfer_number,
            'kind' => 'legacy_completion',
            'disposition' => null,
            'status' => 'posted',
            'sequence' => 1,
            'is_blind' => false,
            'has_discrepancy' => false,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => hash('sha256', $idempotencyKey),
            'received_by_user_id' => $transfer->completed_by_user_id ?? $transfer->initiated_by_user_id,
            'received_at' => $transfer->completed_at ?? $transfer->updated_at,
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lines = DB::table('stock_transfer_lines')->where('transfer_id', $transfer->id)->orderBy('id')->get();

        foreach ($lines as $line) {
            $allocations = DB::table('stock_transfer_line_batch_allocations')
                ->where('stock_transfer_line_id', $line->id)
                ->orderBy('id')
                ->get();

            $isLotTracked = $allocations->isNotEmpty();
            $receiptLineId = (string) Str::orderedUuid();

            DB::table('stock_transfer_receipt_lines')->insert([
                'id' => $receiptLineId,
                'receipt_id' => $receiptId,
                'transfer_line_id' => $line->id,
                'tenant_id' => $transfer->tenant_id,
                'company_id' => $transfer->company_id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id ?? null,
                'is_lot_tracked' => $isLotTracked,
                'quantity_received' => $line->quantity,
                'quantity_damaged' => '0.0000',
                'quantity_written_off' => '0.0000',
                'quantity_returned' => '0.0000',
                'quantity_sent_snapshot' => $line->quantity,
                'discrepancy_reason' => null,
                'discrepancy_note' => null,
                'in_movement_id' => $isLotTracked
                    ? null
                    : $this->uniqueTransferInMovementId($transfer, $line->product_id, null),
                'scrap_movement_id' => null,
                'return_movement_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($allocations as $allocation) {
                DB::table('stock_transfer_receipt_line_lots')->insert([
                    'id' => (string) Str::orderedUuid(),
                    'receipt_line_id' => $receiptLineId,
                    'batch_allocation_id' => $allocation->id,
                    'tenant_id' => $transfer->tenant_id,
                    'company_id' => $transfer->company_id,
                    'batch_id' => $allocation->batch_id,
                    'quantity_received' => $allocation->quantity,
                    'quantity_damaged' => '0.0000',
                    'quantity_written_off' => '0.0000',
                    'quantity_returned' => '0.0000',
                    'in_movement_id' => $this->uniqueTransferInMovementId($transfer, $line->product_id, $allocation->batch_id),
                    'scrap_movement_id' => null,
                    'return_movement_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function uniqueTransferInMovementId(stdClass $transfer, string $productId, ?int $batchId): ?string
    {
        $query = DB::table('stock_movements')
            ->where('reference_type', 'App\\Modules\\Inventory\\Domain\\StockTransfer')
            ->where('reference_id', $transfer->id)
            ->where('movement_type', 'transfer_in')
            ->where('location_id', $transfer->destination_location_id)
            ->where('product_id', $productId);

        if ($batchId !== null) {
            $query->whereExists(function (Builder $lots) use ($batchId): void {
                $lots->selectRaw('1')->from('inventory_batch_movements')
                    ->whereColumn('inventory_batch_movements.movement_id', 'stock_movements.id')
                    ->where('inventory_batch_movements.batch_id', $batchId);
            });
        }

        $ids = $query->pluck('id');

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }
};
