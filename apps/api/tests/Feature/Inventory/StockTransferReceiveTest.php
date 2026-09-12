<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Shared fixture for the receipt document's named per-file tests. */
abstract class TransferReceiptFeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Company $company;

    protected User $user;

    protected Location $source;

    protected Location $destination;

    protected Product $product;

    protected StockTransfer $transfer;

    /** Receipt service must be the root transaction so GL actually flushes. @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        foreach (['view', 'create', 'complete', 'cancel', 'reconcile', 'close'] as $permission) {
            Permission::findOrCreate('inventory.transfers.'.$permission, 'sanctum');
            $this->user->givePermissionTo('inventory.transfers.'.$permission);
        }
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'manager', 'status' => 'active']);
        $this->source = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'warehouse', 'is_active' => true, 'pos_enabled' => true]);
        $this->destination = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'shop', 'is_active' => true, 'pos_enabled' => true]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'cost_price' => '5.000000', 'requires_batch_tracking' => false]);
        $this->app->make(StockAdjustmentService::class)->receive($this->product->id, $this->source->id, '100.0000', 'RECEIPT-SEED', $this->user->id, expectedCompanyId: $this->company->id);
        $this->seedWriteOffAccounts();
        $this->transfer = $this->initiate('12.0000');
        $this->actingAs($this->user)->withHeader('X-Company-Id', $this->company->id);
    }

    protected function seedWriteOffAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '603',
            'name' => 'Cost of Goods Sold',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '6586',
            'name' => 'Inventory Shrinkage Expense',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::InventoryShrinkageExpense,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '311',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
        ]);
    }

    protected function shippedBatch(): Batch
    {
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'cost_price' => '5.000000', 'requires_batch_tracking' => true]);
        $batch = Batch::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'product_id' => $this->product->id, 'batch_number' => 'RECEIPT-LOT', 'expiry_date' => now()->addDays(30)->toDateString(), 'is_active' => true, 'is_expired' => false, 'is_recalled' => false]);
        $this->app->make(StockAdjustmentService::class)->receive($this->product->id, $this->source->id, '20.0000', 'LOT-SEED', $this->user->id, expectedCompanyId: $this->company->id, batchId: (int) $batch->id);
        $this->transfer = $this->app->make(StockTransferService::class)->initiate(new InitiateTransferData(
            $this->tenant->id, $this->company->id, $this->source->id, $this->destination->id, $this->user->id,
            [new InitiateTransferLineData($this->product->id, '12.0000')], autoAllocateBatchesFefo: true,
        ));

        return $batch;
    }

    protected function initiate(string $quantity, string $freight = '0.0000'): StockTransfer
    {
        return $this->app->make(StockTransferService::class)->initiate(new InitiateTransferData(
            $this->tenant->id, $this->company->id, $this->source->id, $this->destination->id, $this->user->id,
            [new InitiateTransferLineData($this->product->id, $quantity)], transferCost: $freight,
            transferCostDistribution: TransferCostDistribution::ProRataQuantity,
        ));
    }

    /** @return array{idempotency_key: string, lines: list<array{transfer_line_id: string, quantity_received: string, quantity_damaged: string}>} */
    protected function receiptBody(string $good = '7.0000', string $damaged = '0.0000', string $key = 'receipt-one'): array
    {
        return ['idempotency_key' => $key, 'lines' => [['transfer_line_id' => $this->transfer->lines->sole()->id, 'quantity_received' => $good, 'quantity_damaged' => $damaged]]];
    }

    protected function receive(string $good = '7.0000', string $key = 'receipt-one'): TestResponse
    {
        return $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/receive', $this->receiptBody($good, key: $key));
    }

    protected function close(string $disposition = 'write_off', string $key = 'close-one'): TestResponse
    {
        return $this->postJson('/api/v1/stock-transfers/'.$this->transfer->id.'/close', ['idempotency_key' => $key, 'disposition' => $disposition, 'reason' => 'lost_in_transit', 'note' => null]);
    }

    protected function destinationQuantity(): string
    {
        return StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->destination->id)->first()?->quantity ?? '0.0000';
    }

    protected function journalCount(): int
    {
        return JournalEntry::query()->where('company_id', $this->company->id)->count();
    }
}

final class StockTransferReceiveTest extends TransferReceiptFeatureTestCase
{
    public function test_partial_receipt_leaves_remainder_in_transit_and_completes_on_the_second_receipt(): void
    {
        $first = $this->receive();
        self::assertSame('partially_received', $this->transfer->refresh()->status->value);
        $first->assertCreated()->assertJsonPath('meta.replayed', false);
        self::assertSame('7.0000', $this->destinationQuantity());
        self::assertSame('5.0000', $this->transfer->lines->sole()->remainingQuantity());
        $this->receive('5.0000', 'receipt-two')->assertCreated();
        self::assertSame('completed', $this->transfer->refresh()->status->value);
        self::assertSame('12.0000', $this->destinationQuantity());
        $this->receive()->assertOk()->assertJsonPath('meta.replayed', true);
        self::assertSame('12.0000', $this->destinationQuantity());
    }

    public function test_over_receipt_is_refused_with_zero_movements(): void
    {
        $before = DB::table('stock_movements')->where('company_id', $this->company->id)->count();
        $this->receive('12.0001')->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
        self::assertSame($before, DB::table('stock_movements')->where('company_id', $this->company->id)->count());
        self::assertSame('0.0000', $this->destinationQuantity());
    }

    public function test_sub_unit_quantities_round_trip_as_four_decimal_strings(): void
    {
        $response = $this->receive('0.0001');
        self::assertSame('0.0001', $response->json('data.receipt.lines.0.quantity_received'));
        self::assertSame('0.0001', $this->destinationQuantity());
        self::assertArrayNotHasKey('quantity_written_off', $response->json('data.receipt.lines.0'));
        self::assertArrayNotHasKey('quantity_returned', $response->json('data.receipt.lines.0'));
    }

    public function test_reconciliation_reports_received_minus_sent_without_counting_open_remainder_as_a_discrepancy(): void
    {
        $this->receive('5.0000')->assertCreated();
        $response = $this->getJson('/api/v1/stock-transfers/'.$this->transfer->id.'/reconciliation')->assertOk();
        self::assertSame('-7.0000', bcadd($response->json('data.lines.0.variance'), '0', 4));
        self::assertSame('7.0000', bcadd($response->json('data.lines.0.remaining'), '0', 4));
        self::assertSame(0, $response->json('data.summary.lines_with_discrepancy'));
    }
}
