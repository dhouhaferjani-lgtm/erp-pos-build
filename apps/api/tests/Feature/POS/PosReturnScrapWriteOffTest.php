<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DPA V10 — a POS SCRAP return must destroy stock through the COMPLIANT
 * write-off chain, not through a raw quantity-only `stock_movements` INSERT.
 *
 * A return note justifies RE-ENTRY of the goods; their DESTRUCTION is a
 * separate economic act that must bear cost:
 *
 *   leg 1 (restore)   +qty, `MovementReason::POSReturn`   — goods came back
 *   leg 2 (write-off) −qty, `MovementReason::WriteOff`    — goods destroyed,
 *                     carrying `unit_cost`/`total_cost` and a movement-keyed
 *                     Dr COGS / Cr Inventory journal entry.
 *
 * Net sellable quantity is unchanged (the two legs cancel); what changes is
 * that the destruction is now costed and posted, and the write-off movement
 * carries the `StockMovementReferenceType` document linkage (DPA S0 seam)
 * rather than an ad-hoc string.
 */
final class PosReturnScrapWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptReturnService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private Terminal $terminal;

    private int $receiptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->terminal = $this->createTerminal();
        $this->createOpenShift();

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $this->seedWriteOffAccounts();

        $this->service = $this->app->make(ReceiptReturnService::class);
    }

    // =========================================================================
    // Cost-bearing write-off movement
    // =========================================================================

    public function test_scrap_write_off_movement_carries_unit_cost_and_total_cost(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $stock = $this->createStockLevel($product->id, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        $this->scrapReturn($sale, $line, '2.000');

        // Net sellable unchanged: +2 restore, −2 write-off.
        self::assertSame('10.0000', (string) $stock->refresh()->quantity);

        $writeOff = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::WriteOff->value)
            ->sole();

        // COST_SCALE = 6 on both cost columns (StockAdjustmentService contract).
        self::assertNotNull($writeOff->unit_cost, 'scrap write-off must carry unit_cost');
        self::assertNotNull($writeOff->total_cost, 'scrap write-off must carry total_cost');
        self::assertSame('2.500000', (string) $writeOff->unit_cost);
        self::assertSame('5.000000', (string) $writeOff->total_cost);
    }

    public function test_scrap_write_off_movement_uses_the_s0_reference_type_enum(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $this->createStockLevel($product->id, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        $return = $this->scrapReturn($sale, $line, '2.000');

        $writeOff = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::WriteOff->value)
            ->sole();

        self::assertSame(
            StockMovementReferenceType::PosReceiptReturnScrap->value,
            $writeOff->reference_type,
        );
        self::assertSame($return->id, $writeOff->reference_id);
    }

    // =========================================================================
    // Movement-keyed Dr COGS / Cr Inventory journal entry
    // =========================================================================

    public function test_scrap_write_off_posts_movement_keyed_cogs_inventory_entry(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $this->createStockLevel($product->id, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        $this->scrapReturn($sale, $line, '2.000');

        $writeOff = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::WriteOff->value)
            ->sole();

        /** @var JournalEntry $entry */
        $entry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $writeOff->id)
            ->with('lines')
            ->sole();

        // TND scale 3: 2.000 × 2.500000 = 5.000
        $cogsAccountId = $this->accountId(SystemAccountPurpose::CostOfGoodsSold);
        $inventoryAccountId = $this->accountId(SystemAccountPurpose::Inventory);

        $debit = $entry->lines->firstWhere('account_id', $cogsAccountId);
        $credit = $entry->lines->firstWhere('account_id', $inventoryAccountId);

        self::assertNotNull($debit, 'Dr COGS line missing');
        self::assertNotNull($credit, 'Cr Inventory line missing');
        self::assertSame('5.000', (string) $debit->debit);
        self::assertSame('5.000', (string) $credit->credit);
    }

    // =========================================================================
    // WAC is NOT disturbed by the pair
    // =========================================================================

    public function test_scrap_pair_leaves_weighted_average_cost_untouched(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $this->createStockLevel($product->id, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        $this->scrapReturn($sale, $line, '2.000');

        // A write-off ISSUES at the current WAC — it never re-averages it.
        // (The restore leg is quantity-only, so it does not re-average either.)
        self::assertSame('2.500000', (string) $product->refresh()->cost_price);
    }

    public function test_non_scrap_restock_return_writes_no_write_off_and_no_journal_entry(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $stock = $this->createStockLevel($product->id, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [[
                'line_id' => $line->id,
                'quantity' => '2.000',
                'physical_receipt' => true,
                'resalable' => true,
                'disposition' => 'restock',
            ]],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        self::assertSame('12.0000', (string) $stock->refresh()->quantity);
        self::assertSame(0, StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::WriteOff->value)
            ->count());
        self::assertSame(0, JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'batch_write_off')
            ->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function scrapReturn(Receipt $sale, ReceiptLine $line, string $quantity): Receipt
    {
        return $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [[
                'line_id' => $line->id,
                'quantity' => $quantity,
                'physical_receipt' => true,
                'resalable' => false,
                'disposition' => 'scrap',
            ]],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );
    }

    private function accountId(SystemAccountPurpose $purpose): string
    {
        return (string) Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', $purpose->value)
            ->sole()
            ->id;
    }

    private function seedWriteOffAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '601',
            'name' => 'Cost of Goods Sold',
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
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

    private function createStockLevel(string $productId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $productId,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProductLine(Receipt $receipt, Product $product, array $overrides = []): ReceiptLine
    {
        return ReceiptLine::create(array_merge([
            'receipt_id' => $receipt->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_code' => $product->sku,
            'product_name' => $product->name,
            'quantity' => '2.000',
            'unit' => 'pcs',
            'unit_price' => '25.000',
            'line_total' => '50.000',
            'tax_rate' => '19.00',
            'tax_amount' => '7.983',
            'discount_amount' => '0.000',
        ], $overrides));
    }

    private function createTerminal(): Terminal
    {
        return Terminal::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS10',
            'name' => 'Scrap Write-Off Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 300,
            'current_year' => 2026,
            'fiscal_schema_version' => 2,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);
    }

    private function createOpenShift(): Shift
    {
        return Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.00',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReceipt(array $overrides = []): Receipt
    {
        $this->receiptSequence++;

        return Receipt::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => sprintf('POS10-2026-%08d', $this->receiptSequence),
            'chain_sequence' => 200 + $this->receiptSequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "v10-receipt-{$this->receiptSequence}"),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Scrap Cashier',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'discount_amount' => '0.000',
            'total' => '119.000',
            'currency' => 'TND',
            'is_voided' => false,
        ], $overrides));
    }
}
