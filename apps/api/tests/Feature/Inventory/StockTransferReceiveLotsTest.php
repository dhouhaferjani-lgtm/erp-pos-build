<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Inventory\Application\DTOs\InitiateTransferBatchAllocationData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Illuminate\Support\Facades\DB;

final class StockTransferReceiveLotsTest extends TransferReceiptFeatureTestCase
{
    public function test_lot_rules_and_per_lot_remainder(): void
    {
        $batch = $this->shippedBatch();
        $this->receive()->assertStatus(422)->assertJsonPath('error.code', 'LOT_REQUIRED');
        $body = $this->receiptBody();
        $body['lines'][0]['lots'] = [['batch_id' => $batch->id, 'quantity_received' => '7.0000', 'quantity_damaged' => '0.0000']];
        $first = $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertCreated();
        self::assertSame('5.0000', $this->transfer->lines->sole()->batchAllocations->sole()->refresh()->remainingQuantity());
        $receiptLine = DB::table('stock_transfer_receipt_lines')->where('receipt_id', $first->json('data.receipt.id'))->sole();
        self::assertNull($receiptLine->in_movement_id);
        $lot = DB::table('stock_transfer_receipt_line_lots')->where('receipt_line_id', $receiptLine->id)->sole();
        self::assertNotNull($lot->in_movement_id);
        self::assertSame($this->transfer->id, StockMovement::findOrFail($lot->in_movement_id)->reference_id);
        $body['idempotency_key'] = 'receipt-lot-two';
        $body['lines'][0]['quantity_received'] = '6.0000';
        $body['lines'][0]['lots'][0]['quantity_received'] = '6.0000';
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
        self::assertSame('7.0000', $this->destinationQuantity());
    }

    public function test_two_shipped_lots_distinguish_lot_overflow_unknown_lot_and_mismatched_sums(): void
    {
        $first = $this->shippedBatch();
        $second = $first->replicate();
        $second->batch_number = 'RECEIPT-LOT-SECOND';
        $second->save();
        $this->app->make(StockAdjustmentService::class)->receive($this->product->id, $this->source->id, '20.0000', 'LOT-SECOND-SEED', $this->user->id, batchId: (int) $second->id, expectedCompanyId: $this->company->id);
        $this->transfer = $this->app->make(StockTransferService::class)->initiate(new InitiateTransferData(
            $this->tenant->id, $this->company->id, $this->source->id, $this->destination->id, $this->user->id,
            [new InitiateTransferLineData($this->product->id, '12.0000', batchAllocations: [
                new InitiateTransferBatchAllocationData((int) $first->id, '8.0000'),
                new InitiateTransferBatchAllocationData((int) $second->id, '4.0000'),
            ])],
        ));
        foreach ([['UNKNOWN_LOT', 99999999, '9.0000'], ['LOT_OVER_RECEIPT', (int) $first->id, '9.0000'], ['LOT_SUM_MISMATCH', (int) $first->id, '5.0000']] as [$code, $batchId, $quantity]) {
            $body = $this->receiptBody('9.0000');
            $body['lines'][0]['lots'] = [['batch_id' => $batchId, 'quantity_received' => $quantity, 'quantity_damaged' => '0.0000']];
            $movements = StockMovement::query()->where('company_id', $this->company->id)->count();
            $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertStatus(422)->assertJsonPath('error.code', $code);
            self::assertSame($movements, StockMovement::query()->where('company_id', $this->company->id)->count());
            self::assertSame(0, StockTransferReceipt::query()->where('transfer_id', $this->transfer->id)->count());
        }
    }

    public function test_lot_damage_nets_to_zero_and_requires_perpetual_valuation(): void
    {
        $batch = $this->shippedBatch();
        $body = $this->receiptBody('0.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'other';
        $body['lines'][0]['lots'] = [['batch_id' => $batch->id, 'quantity_received' => '0.0000', 'quantity_damaged' => '2.0000']];
        $this->company->update(['inventory_valuation_mode' => 'periodic']);
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertStatus(422)->assertJsonPath('error.code', 'VALUATION_MODE_UNSUPPORTED');
        $this->company->update(['inventory_valuation_mode' => 'perpetual']);
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertCreated();
        self::assertSame('0.0000', $this->destinationQuantity());
        self::assertSame(1, $this->journalCount());
    }
}
