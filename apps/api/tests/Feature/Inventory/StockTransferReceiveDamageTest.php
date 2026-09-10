<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Events\StockTransferReceiptPosted;
use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Support\Facades\Event;

final class StockTransferReceiveDamageTest extends TransferReceiptFeatureTestCase
{
    public function test_damaged_units_land_then_scrap_with_one_shrinkage_journal(): void
    {
        $body = $this->receiptBody('0.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'damaged_in_transit';
        $response = $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body);
        $response->assertCreated();
        self::assertSame(1, $this->journalCount());
        self::assertSame('0.0000', $this->destinationQuantity());
        $response->assertJsonPath('data.receipt.has_discrepancy', true);
        self::assertSame('2.0000', $this->transfer->lines->sole()->refresh()->quantity_damaged);
    }

    public function test_declared_reason_never_changes_the_movement_reason(): void
    {
        $body = $this->receiptBody('0.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'other';
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertCreated();
        $scrap = StockMovement::query()->where('company_id', $this->company->id)->where('reason', MovementReason::Damage)->sole();
        self::assertSame(MovementReason::Damage, $scrap->reason);
    }

    public function test_periodic_valuation_company_is_refused_before_any_movement(): void
    {
        $this->company->update(['inventory_valuation_mode' => 'periodic']);
        $before = StockMovement::query()->where('company_id', $this->company->id)->count();
        $body = $this->receiptBody('0.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'other';
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertStatus(422)->assertJsonPath('error.code', 'VALUATION_MODE_UNSUPPORTED');
        self::assertSame($before, StockMovement::query()->where('company_id', $this->company->id)->count());
    }

    public function test_the_posted_event_carries_the_discrepancy_line_count(): void
    {
        Event::fake([StockTransferReceiptPosted::class]);
        $body = $this->receiptBody('0.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'other';
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body);
        Event::assertDispatched(StockTransferReceiptPosted::class, static fn ($event): bool => $event->linesWithDiscrepancy === 1);
    }
}
