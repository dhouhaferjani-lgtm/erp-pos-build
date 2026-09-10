<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Events\StockTransferReceiptPosted;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class StockTransferReceiveDamageTest extends TransferReceiptFeatureTestCase
{
    public function test_damaged_units_land_then_scrap_with_one_shrinkage_journal(): void
    {
        Event::fake([JournalEntryPosted::class]);
        $body = $this->receiptBody('0.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'damaged_in_transit';
        $response = $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body);
        $response->assertCreated();
        self::assertSame(1, $this->journalCount());
        $entry = JournalEntry::query()->where('company_id', $this->company->id)->with('lines')->sole();
        $scale = $this->app->make(CurrencyScaleResolverInterface::class)->getScale($this->company->currency);
        $amount = bcmul('2.0000', $this->product->cost_price, $scale);
        $zero = bcadd('0', '0', $scale);
        $byPurpose = [];
        foreach ($entry->lines as $line) {
            $purpose = Account::findOrFail($line->account_id)->system_purpose->value;
            $byPurpose[$purpose] = [$line->debit, $line->credit];
        }
        self::assertSame([$amount, $zero], $byPurpose[SystemAccountPurpose::InventoryShrinkageExpense->value]);
        self::assertSame([$zero, $amount], $byPurpose[SystemAccountPurpose::Inventory->value]);
        self::assertSame(JournalEntryStatus::Posted, $entry->status);
        self::assertTrue($entry->isChained());
        Event::assertDispatched(JournalEntryPosted::class);

        self::assertSame('0.0000', $this->destinationQuantity());
        $response->assertJsonPath('data.receipt.has_discrepancy', true);
        self::assertSame('2.0000', $this->transfer->lines->sole()->refresh()->quantity_damaged);
    }

    public function test_damage_preserves_wac_and_lot_stock_equals_good_quantity(): void
    {
        $batch = $this->shippedBatch();
        $cost = $this->product->refresh()->cost_price;
        $body = $this->receiptBody('3.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'damaged_in_transit';
        $body['lines'][0]['lots'] = [['batch_id' => $batch->id, 'quantity_received' => '3.0000', 'quantity_damaged' => '2.0000']];
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertCreated();
        self::assertSame($cost, $this->product->refresh()->cost_price);
        self::assertSame('3.0000', BatchStock::query()->where('batch_id', $batch->id)->where('location_id', $this->destination->id)->sole()->quantity);
        self::assertSame('3.0000', $this->destinationQuantity());
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

    public function test_unmapped_shrinkage_accounts_are_refused_before_any_write(): void
    {
        Account::query()->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::InventoryShrinkageExpense)->update(['system_purpose' => null]);
        $snapshot = static function (): array {
            $rows = [];
            foreach (['stock_levels', 'stock_movements', 'stock_transfers', 'stock_transfer_lines', 'stock_transfer_receipts', 'journal_entries', 'journal_lines'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $rows;
        };
        $before = $snapshot();
        $body = $this->receiptBody('0.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'damaged_in_transit';
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)
            ->assertUnprocessable()->assertJsonPath('error.code', 'GL_ACCOUNTS_UNMAPPED');
        self::assertSame($before, $snapshot());
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
