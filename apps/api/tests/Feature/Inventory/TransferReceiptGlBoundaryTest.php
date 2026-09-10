<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class TransferReceiptGlBoundaryTest extends TransferReceiptFeatureTestCase
{
    public function test_receipt_and_close_leave_the_gl_buffer_empty_with_no_leak_alarm(): void
    {
        Log::spy();
        $body = $this->receiptBody('3.0000', '2.0000');
        $body['lines'][0]['discrepancy_reason'] = 'damaged_in_transit';
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $body)->assertCreated();
        self::assertSame(1, $this->journalCount());
        self::assertTrue($this->app->make(InventoryGlPostingBuffer::class)->isEmpty());
        $this->close()->assertCreated();
        self::assertSame(2, $this->journalCount());
        self::assertTrue($this->app->make(InventoryGlPostingBuffer::class)->isEmpty());
        Log::shouldNotHaveReceived('critical');
        self::assertSame(0, DB::transactionLevel());
    }
}
