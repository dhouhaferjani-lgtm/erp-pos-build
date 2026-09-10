<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;

final class StockTransferCloseTest extends TransferReceiptFeatureTestCase
{
    public function test_close_write_off_posts_one_shrinkage_leg_and_persists_the_freight_residual(): void
    {
        $this->transfer = $this->initiate('12.0000', '120.0000');
        $this->receive('5.0000');
        $this->close();
        self::assertSame('70.0000', $this->transfer->refresh()->freight_uncapitalized);
        self::assertSame(1, $this->journalCount());
        self::assertSame('5.0000', $this->destinationQuantity());
        $this->close()->assertOk()->assertJsonPath('meta.replayed', true);
        self::assertSame(1, $this->journalCount());
    }

    public function test_two_line_worked_example_persists_exactly(): void
    {
        $other = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'cost_price' => '5.000000', 'requires_batch_tracking' => false]);
        $this->app->make(StockAdjustmentService::class)->receive($other->id, $this->source->id, '10.0000', 'T4-SECOND', $this->user->id, expectedCompanyId: $this->company->id);
        $this->transfer = $this->app->make(StockTransferService::class)->initiate(new InitiateTransferData(
            $this->tenant->id, $this->company->id, $this->source->id, $this->destination->id, $this->user->id,
            [new InitiateTransferLineData($this->product->id, '8.0000'), new InitiateTransferLineData($other->id, '4.0000')],
            transferCost: '120.0000', transferCostDistribution: TransferCostDistribution::ProRataQuantity,
        ));
        $lineA = $this->transfer->lines->firstWhere('product_id', $this->product->id);
        $lineB = $this->transfer->lines->firstWhere('product_id', $other->id);
        $body = ['idempotency_key' => 'worked-example', 'lines' => [
            ['transfer_line_id' => $lineA->id, 'quantity_received' => '5.0000', 'quantity_damaged' => '0.0000'],
            ['transfer_line_id' => $lineB->id, 'quantity_received' => '4.0000', 'quantity_damaged' => '0.0000'],
        ]];
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertCreated();
        $this->close()->assertCreated();
        self::assertSame('49.9999', $lineA->refresh()->allocated_transfer_cost);
        self::assertSame('40.0001', $lineB->refresh()->allocated_transfer_cost);
        self::assertSame('30.0000', $this->transfer->refresh()->freight_uncapitalized);
        self::assertSame('120.0000', bcadd(bcadd($lineA->allocated_transfer_cost, $lineB->allocated_transfer_cost, 4), $this->transfer->freight_uncapitalized, 4));
    }

    public function test_close_return_to_source_restocks_the_source_lot_exactly_and_posts_no_journal(): void
    {
        $batch = $this->shippedBatch();
        $before = StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity;
        $this->close('return_to_source')->assertCreated();
        self::assertSame('closed_returned', $this->transfer->refresh()->status->value);
        self::assertSame(bcadd($before, '12.0000', 4), StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity);
        self::assertSame('20.0000', BatchStock::query()->where('batch_id', $batch->id)->where('location_id', $this->source->id)->sole()->quantity);
        self::assertSame(1, StockMovement::query()->where('reference_id', $this->transfer->id)->where('location_id', $this->source->id)->where('movement_type', 'transfer_in')->count());
        self::assertSame(0, $this->journalCount());
        $this->close('return_to_source')->assertOk()->assertJsonPath('meta.replayed', true);
        self::assertSame(bcadd($before, '12.0000', 4), StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity);
    }
}
