<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;

final class TransferReceiptSecondOfEverythingTest extends TransferReceiptFeatureTestCase
{
    public function test_full_transfer_entity_types_are_generated(): void
    {
        $generated = file_get_contents(base_path('../../packages/shared/types/generated.d.ts'));
        self::assertSame([], array_values(array_filter(['StockTransferData', 'StockTransferLineData', 'StockTransferLineBatchAllocationData'], static fn (string $name): bool => ! str_contains($generated, 'export type '.$name))));
        foreach (['StockTransferReceiptData', 'TransferCloseReceiptData', 'TransferReconciliationReceiptData', 'TransferReconciliationLineData'] as $name) {
            $shape = explode('};', explode('export type '.$name.' = {', $generated)[1])[0];
            self::assertStringNotContainsString('Array<any>', $shape);
        }
    }

    public function test_second_company_second_location_and_rerun_for_every_receipt_writer(): void
    {
        $companyA = $this->company;
        $transferA = $this->transfer;
        $productA = $this->product;
        $sourceA = $this->source;
        $destinationA = $this->destination;
        $this->receive()->assertCreated();
        $historyA = DB::table('stock_transfer_receipts')->where('company_id', $companyA->id)->orderBy('id')->get()->toJson();
        $firstNumber = StockTransferReceipt::query()->where('transfer_id', $transferA->id)->sole()->receipt_number;
        $sourceBefore = StockLevel::query()->where('product_id', $productA->id)->where('location_id', $sourceA->id)->sole()->quantity;
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'manager', 'status' => 'active']);
        $this->source = Location::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $this->destination = Location::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'cost_price' => '5.000000', 'requires_batch_tracking' => false]);
        $this->app->make(StockAdjustmentService::class)->receive($this->product->id, $this->source->id, '100.0000', 'SECOND', $this->user->id, expectedCompanyId: $this->company->id);
        $this->withHeader('X-Company-Id', $this->company->id);
        $this->seedWriteOffAccounts();
        $this->transfer = $this->initiate('12.0000');
        $this->receive()->assertCreated();
        self::assertSame($firstNumber, StockTransferReceipt::query()->where('transfer_id', $this->transfer->id)->sole()->receipt_number);
        $this->receive()->assertOk()->assertJsonPath('meta.replayed', true);
        self::assertSame('7.0000', $this->destinationQuantity());
        $this->close()->assertCreated();
        $this->close()->assertOk()->assertJsonPath('meta.replayed', true);
        self::assertSame(1, $this->journalCount());
        $this->transfer = $this->initiate('12.0000');
        $sourceQuantity = StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity;
        $this->close('return_to_source', 'return-second')->assertCreated();
        $this->close('return_to_source', 'return-second')->assertOk();
        self::assertSame(bcadd($sourceQuantity, '12.0000', 4), StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity);
        $this->transfer = $this->initiate('12.0000');
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/complete')->assertOk();
        $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/complete')->assertOk();
        self::assertSame('19.0000', $this->destinationQuantity());
        $this->postJson('/api/v1/stock-transfers/'.$transferA->id.'/complete')->assertNotFound();
        $this->getJson('/api/v1/stock-transfers/'.$transferA->id)->assertNotFound();
        $this->postJson('/api/v1/stock-transfers/'.$transferA->id.'/receive', $this->receiptBody())->assertNotFound();
        $this->postJson('/api/v1/stock-transfers/'.$transferA->id.'/close', ['idempotency_key' => 'cross-company', 'disposition' => 'write_off', 'reason' => 'lost_in_transit'])->assertNotFound();
        $this->getJson('/api/v1/stock-transfers/'.$transferA->id.'/reconciliation')->assertNotFound();
        self::assertSame('7.0000', StockLevel::query()->where('product_id', $productA->id)->where('location_id', $destinationA->id)->sole()->quantity);
        self::assertSame($historyA, DB::table('stock_transfer_receipts')->where('company_id', $companyA->id)->orderBy('id')->get()->toJson());
        self::assertSame($sourceBefore, StockLevel::query()->where('product_id', $productA->id)->where('location_id', $sourceA->id)->sole()->quantity);
        self::assertSame(0, DB::table('journal_entries')->where('company_id', $companyA->id)->count());
    }
}
