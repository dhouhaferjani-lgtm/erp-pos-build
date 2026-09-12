<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Inventory\Domain\Events\StockTransferClosedV1;
use App\Modules\Inventory\Domain\Events\StockTransferReceiptLineRecordedV1;
use App\Modules\Inventory\Domain\Events\StockTransferReceivedV1;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TransferReceiptEventStreamTest extends TransferReceiptFeatureTestCase
{
    private const EVENTS = [StockTransferReceivedV1::class, StockTransferClosedV1::class, StockTransferReceiptLineRecordedV1::class];

    public function test_header_and_line_events_share_the_receipt_stream_with_ordered_versions(): void
    {
        $response = $this->receive();
        $receiptId = $response->json('data.receipt.id');
        $events = DB::table('stored_events')->whereIn('event_class', self::EVENTS)->where('aggregate_uuid', $receiptId)->orderBy('id')->get();
        self::assertSame([1, 2], $events->pluck('aggregate_version')->all());
        self::assertSame([StockTransferReceivedV1::class, StockTransferReceiptLineRecordedV1::class], $events->pluck('event_class')->all());
        $line = json_decode($events[1]->event_properties, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('receiver', $line['actorRole']);
        self::assertSame('7.0000', $line['quantityReceived']);
        self::assertSame('5.0000', $line['quantityRemainingAfter']);
        self::assertSame($receiptId, $line['receiptId']);
        self::assertSame(0, DB::table('stored_events')->whereIn('event_class', self::EVENTS)->whereNull('aggregate_uuid')->count());
        $this->receive()->assertOk();
        self::assertSame(2, DB::table('stored_events')->where('aggregate_uuid', $receiptId)->count());
    }

    /**
     * Plan rev 10 §7.12 (S1 fix round 3, I-15): mixed-unit quantities are never summed,
     * so neither immutable header event may carry a cross-line quantity total. The
     * line COUNT stays — it counts lines, not quantities.
     */
    public function test_header_events_carry_a_line_count_and_no_cross_unit_quantity_totals(): void
    {
        $this->receive()->assertCreated();
        $this->close('write_off')->assertCreated();
        $headers = DB::table('stored_events')->whereIn('event_class', [StockTransferReceivedV1::class, StockTransferClosedV1::class])->orderBy('id')->get();
        self::assertSame([StockTransferReceivedV1::class, StockTransferClosedV1::class], $headers->pluck('event_class')->all());
        foreach ($headers as $header) {
            $properties = json_decode($header->event_properties, true, flags: JSON_THROW_ON_ERROR);
            foreach (['totalReceived', 'totalDamaged', 'totalWrittenOff', 'totalReturned'] as $total) {
                self::assertFalse(array_key_exists($total, $properties), $header->event_class.' must carry no cross-unit quantity total, but it carries '.$total);
            }
            self::assertTrue(array_key_exists('lineCount', $properties), $header->event_class.' must still carry lineCount');
            self::assertSame(1, $properties['lineCount'], $header->event_class.' lineCount');
        }
    }

    public function test_replay_reproduces_every_row_and_leaves_legacy_receipts_untouched(): void
    {
        $legacy = $this->transfer;
        $legacy->update(['status' => 'completed', 'completed_by_user_id' => $this->user->id, 'completed_at' => now()]);
        (require database_path('migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php'))->up();
        $legacyBefore = DB::table('stock_transfer_receipts')->where('transfer_id', $legacy->id)->get()->toJson();
        $batch = $this->shippedBatch();
        $body = $this->receiptBody('5.0000', '1.0000');
        $body['lines'][0]['discrepancy_reason'] = 'other';
        $body['lines'][0]['discrepancy_note'] = 'damaged packaging';
        $body['notes'] = 'delivery 1';
        $body['lines'][0]['lots'] = [['batch_id' => $batch->id, 'quantity_received' => '5.0000', 'quantity_damaged' => '1.0000']];
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertCreated();
        $this->close('return_to_source')->assertCreated();
        $headers = [];
        $lines = [];
        $lots = [];
        $counters = [];
        $allocationCounters = [];
        $transfer = [];
        $receiptIds = DB::table('stock_transfer_receipts')->where('transfer_id', $this->transfer->id)->pluck('id');
        $events = DB::table('stored_events')->whereIn('aggregate_uuid', $receiptIds)->orderBy('id')->get();
        self::assertCount(4, $events);
        foreach ($events as $event) {
            $e = json_decode($event->event_properties, true, flags: JSON_THROW_ON_ERROR);
            if ($event->event_class !== StockTransferReceiptLineRecordedV1::class) {
                $close = $event->event_class === StockTransferClosedV1::class;
                $headers[$e['receiptId']] = [
                    'id' => $e['receiptId'], 'tenant_id' => $e['tenantId'], 'company_id' => $e['companyId'], 'transfer_id' => $e['transferId'],
                    'receipt_number' => $e['receiptNumber'], 'kind' => $close ? 'close' : 'receipt', 'disposition' => $e['disposition'] ?? null,
                    'status' => 'posted', 'sequence' => $e['sequence'], 'is_blind' => $e['isBlind'] ?? false, 'has_discrepancy' => $close || $e['hasDiscrepancy'],
                    'idempotency_key' => $e['idempotencyKey'], 'payload_hash' => $e['payloadHash'],
                    'received_by_user_id' => $close ? $e['closedByUserId'] : $e['receivedByUserId'],
                    'received_at' => $e['occurredAt'], 'notes' => $close ? $e['closeNote'] : $e['receiptNotes'],
                ];
                $transfer['status'] = $e['newStatus'];
                $transfer['freight_uncapitalized'] = $e['freightUncapitalized'];
                if ($close) {
                    $transfer += ['closed_by_user_id' => $e['closedByUserId'], 'closed_at' => $e['occurredAt'], 'close_disposition' => $e['disposition'], 'close_reason' => $e['closeReason'], 'close_note' => $e['closeNote']];
                }

                continue;
            }
            $lines[$e['receiptLineId']] = [
                'id' => $e['receiptLineId'], 'receipt_id' => $e['receiptId'], 'transfer_line_id' => $e['transferLineId'], 'tenant_id' => $e['tenantId'], 'company_id' => $e['companyId'],
                'product_id' => $e['productId'], 'variant_id' => $e['variantId'], 'is_lot_tracked' => $e['isLotTracked'],
                'quantity_received' => $e['quantityReceived'], 'quantity_damaged' => $e['quantityDamaged'], 'quantity_written_off' => $e['quantityWrittenOff'], 'quantity_returned' => $e['quantityReturned'],
                'quantity_sent_snapshot' => $e['quantitySent'], 'discrepancy_reason' => $e['discrepancyReason'], 'discrepancy_note' => $e['discrepancyNote'],
                'in_movement_id' => $e['inMovementId'], 'scrap_movement_id' => $e['scrapMovementId'], 'return_movement_id' => $e['returnMovementId'],
            ];
            foreach (['quantity_received' => 'quantityReceived', 'quantity_damaged' => 'quantityDamaged', 'quantity_written_off' => 'quantityWrittenOff', 'quantity_returned' => 'quantityReturned'] as $column => $property) {
                $counters[$e['transferLineId']][$column] = bcadd($counters[$e['transferLineId']][$column] ?? '0', $e[$property], 4);
            }
            foreach ($e['lots'] as $lot) {
                $lots[$lot['receiptLotId']] = ['id' => $lot['receiptLotId'], 'receipt_line_id' => $e['receiptLineId'], 'batch_allocation_id' => $lot['batchAllocationId'], 'tenant_id' => $e['tenantId'], 'company_id' => $e['companyId'], 'batch_id' => $lot['batchId'],
                    'quantity_received' => $lot['quantityReceived'], 'quantity_damaged' => $lot['quantityDamaged'], 'quantity_written_off' => $lot['quantityWrittenOff'], 'quantity_returned' => $lot['quantityReturned'],
                    'in_movement_id' => $lot['inMovementId'], 'scrap_movement_id' => $lot['scrapMovementId'], 'return_movement_id' => $lot['returnMovementId'],
                ];
                foreach (['quantity_received' => 'quantityReceived', 'quantity_damaged' => 'quantityDamaged', 'quantity_written_off' => 'quantityWrittenOff', 'quantity_returned' => 'quantityReturned'] as $column => $property) {
                    $allocationCounters[$lot['batchAllocationId']][$column] = bcadd($allocationCounters[$lot['batchAllocationId']][$column] ?? '0', $lot[$property], 4);
                }
            }
        }
        foreach (['stock_transfer_receipts' => $headers, 'stock_transfer_receipt_lines' => $lines, 'stock_transfer_receipt_line_lots' => $lots] as $table => $rows) {
            foreach ($rows as $id => $expected) {
                $actual = (array) DB::table($table)->where('id', $id)->sole();
                unset($actual['created_at'], $actual['updated_at']);
                foreach (['received_at', 'closed_at'] as $date) {
                    if (isset($actual[$date])) {
                        $actual[$date] = Carbon::parse($actual[$date])->toISOString();
                        $expected[$date] = Carbon::parse($expected[$date])->toISOString();
                    }
                }
                foreach ($expected as $column => $value) {
                    if (str_starts_with($column, 'quantity_')) {
                        $actual[$column] = bcadd((string) $actual[$column], '0', 4);
                    }
                    if (is_bool($value)) {
                        $actual[$column] = (bool) $actual[$column];
                    }
                }
                ksort($actual);
                ksort($expected);
                self::assertSame($expected, $actual, $table.' stream reconstruction');
            }
        }
        foreach (['stock_transfer_lines' => $counters, 'stock_transfer_line_batch_allocations' => $allocationCounters] as $table => $rows) {
            foreach ($rows as $id => $values) {
                $actual = (array) DB::table($table)->where('id', $id)->first(array_keys($values));
                foreach ($values as $column => $value) {
                    self::assertSame($value, bcadd((string) $actual[$column], '0', 4));
                }
            }
        }
        foreach ($transfer as $column => $value) {
            $actual = DB::table('stock_transfers')->where('id', $this->transfer->id)->value($column);
            if ($column === 'closed_at') {
                self::assertTrue(Carbon::parse($actual)->equalTo(Carbon::parse($value)));
            } else {
                self::assertSame($value, $actual);
            }
        }
        self::assertSame($legacyBefore, DB::table('stock_transfer_receipts')->where('transfer_id', $legacy->id)->get()->toJson());
        self::assertSame(0, DB::table('stored_events')->whereIn('aggregate_uuid', DB::table('stock_transfer_receipts')->where('transfer_id', $legacy->id)->select('id'))->count());
    }

    public function test_a_failure_after_persist_leaves_no_stored_event(): void
    {
        // CONTROL: passes on the pre-receipt tree; the listener forces the same
        // failure immediately AFTER an actual receipt event INSERT once implemented.
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "stored_events"') || str_starts_with(strtolower($query->sql), 'insert into `stored_events`')) {
                foreach ($query->bindings as $binding) {
                    if (is_string($binding) && in_array($binding, self::EVENTS, true)) {
                        throw new \RuntimeException('T15 forced failure after persist');
                    }
                }
            }
        });
        try {
            $this->receive();
        } catch (\RuntimeException $exception) {
            self::assertSame('T15 forced failure after persist', $exception->getMessage());
        }
        self::assertSame(0, DB::table('stored_events')->whereIn('event_class', self::EVENTS)->count());
        self::assertSame('0.0000', $this->destinationQuantity());
        self::assertSame('in_transit', $this->transfer->refresh()->status->value);
    }
}
