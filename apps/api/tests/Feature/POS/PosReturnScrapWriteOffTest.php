<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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
 *                     Dr Shrinkage / Cr Inventory journal entry.
 *
 * Net sellable quantity is unchanged (the two legs cancel); what changes is
 * that the destruction is now costed and posted, and the write-off movement
 * carries the `StockMovementReferenceType` document linkage (DPA S0 seam)
 * rather than an ad-hoc string.
 */
final class PosReturnScrapWriteOffTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

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
    // Movement-keyed Dr Shrinkage / Cr Inventory journal entry
    // =========================================================================

    public function test_scrap_write_off_posts_movement_keyed_shrinkage_inventory_entry(): void
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
        $restore = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::POSReturn->value)
            ->sole();

        /** @var JournalEntry $entry */
        $entry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $writeOff->id)
            ->with('lines')
            ->sole();

        // TND scale 3: 2.000 × 2.500000 = 5.000
        $shrinkageAccountId = $this->accountId(SystemAccountPurpose::InventoryShrinkageExpense);
        $cogsAccountId = $this->accountId(SystemAccountPurpose::CostOfGoodsSold);
        $inventoryAccountId = $this->accountId(SystemAccountPurpose::Inventory);

        $debit = $entry->lines->firstWhere('account_id', $shrinkageAccountId);
        $credit = $entry->lines->firstWhere('account_id', $inventoryAccountId);

        self::assertNotNull($debit, 'Dr Shrinkage line missing');
        self::assertNotNull($credit, 'Cr Inventory line missing');
        self::assertSame('5.000', (string) $debit->debit);
        self::assertSame('5.000', (string) $credit->credit);

        $restoreEntry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'inventory_entry')
            ->where('source_id', $restore->id)
            ->with('lines')
            ->sole();
        $restoreInventory = $restoreEntry->lines->firstWhere('account_id', $inventoryAccountId);
        $restoreCogs = $restoreEntry->lines->firstWhere('account_id', $cogsAccountId);
        self::assertSame('5.000', (string) $restoreInventory?->debit);
        self::assertSame('5.000', (string) $restoreCogs?->credit);
        self::assertSame($entry->entry_date->toDateString(), $restoreEntry->entry_date->toDateString());
    }

    // =========================================================================
    // WAC is NOT disturbed by the pair
    // =========================================================================

    /**
     * WAC neutrality, pinned by CONSEQUENCE rather than by asserting a value
     * nothing on the path can write (gate inv-M7): after the scrap pair, a
     * subsequent receive must re-average against the on-hand the pair left
     * behind. If the pair leaked quantity in either direction, the running
     * average computed here would move off the gold value.
     */
    public function test_scrap_pair_leaves_the_running_average_cost_correct_for_a_later_receive(): void
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

        // Now buy 10 more at 4.00. `recordPurchase` blends against the
        // COMPANY-OWNED on-hand, so the resulting average is a direct function
        // of the quantity the scrap pair left behind:
        //   (10 × 2.50 + 10 × 4.00) / 20 = 3.25
        // Had either leg leaked, the denominator would be 18 or 22 and the
        // average would not be 3.25.
        $this->app->make(WeightedAverageCostService::class)->recordPurchase(
            product: $product->refresh(),
            location: $this->location,
            quantity: '10.0000',
            landedUnitCost: '4.000000',
            reference: 'post-scrap purchase',
        );

        self::assertSame('3.250000', (string) $product->refresh()->cost_price);
    }

    // =========================================================================
    // Gate fix round 1 — C1: the two legs must be ATOMIC
    // =========================================================================

    /**
     * Gate C1 (fiscal, reviewer-reproduced). `Product` uses SoftDeletes, so a
     * product archived between the sale and the return is unresolvable. Before
     * the fix the write-off leg RETURNED NULL and only the restore leg
     * committed — a SCRAP return permanently ADDED destroyed goods back to
     * sellable stock (10 → 12).
     *
     * The pair is now atomic: an unresolvable product fails BOTH legs, the
     * quantity falls back to the pre-return figure, and the refund itself still
     * completes (a customer's refund is never refused by an inventory-side
     * fault).
     */
    public function test_scrap_with_soft_deleted_product_rolls_back_both_legs_and_still_refunds(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $stock = $this->createStockLevel($product->id, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        // Archived after the sale, before the return.
        $product->delete();

        $return = $this->scrapReturn($sale, $line, '2.000');

        self::assertNotNull($return->id, 'the refund must still complete');
        self::assertSame('10.0000', (string) $stock->refresh()->quantity, 'NO phantom restock of destroyed goods');
        self::assertSame(0, StockMovement::query()->where('product_id', $product->id)->count(),
            'neither leg may survive alone');
    }

    /**
     * Gate I3 (both halves). `issue()` enforces `quantity − reserved`, which is
     * semantically irrelevant when DESTROYING goods you physically hold. An
     * over-reserved row must not turn a customer refund into a 500 — the
     * destruction leg is contained and both legs roll back.
     */
    public function test_scrap_with_over_reserved_stock_is_contained_and_the_refund_still_completes(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $stock = $this->createStockLevel($product->id, '10.0000');
        $stock->reserved = '11.0000';
        $stock->save();

        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, ['quantity' => '2.000']);

        $return = $this->scrapReturn($sale, $line, '2.000');

        self::assertNotNull($return->id, 'the refund must still complete');
        self::assertSame('10.0000', (string) $stock->refresh()->quantity);
        self::assertSame(0, StockMovement::query()->where('product_id', $product->id)->count());
    }

    // =========================================================================
    // Gate fix round 1 — C3: a POS scrap must NOT be reversible
    // =========================================================================

    /**
     * Gate C3 (inventory). Pinning `movement_type` to `Issue` made POS scrap
     * movements pass `ReverseWriteOffService`'s type guard, and the web Reverse
     * button is reason-gated only — so one click would have `receive()`d
     * physically destroyed goods back into sellable stock AND (with no
     * BatchMovement to restore) inflated the DEFAULT lot to the whole aggregate.
     * A POS scrap is undone by correcting the return, never by a batch reversal.
     */
    public function test_pos_scrap_write_off_cannot_be_reversed(): void
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

        $writeOff = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::WriteOff->value)
            ->sole();

        try {
            $this->app->make(ReverseWriteOffService::class)->reverse($writeOff, $this->cashier->id);
            self::fail('reversing a POS return scrap must be refused');
        } catch (\DomainException $e) {
            self::assertStringContainsString('POS return scrap', $e->getMessage());
        }

        // Destroyed goods stayed destroyed; no inverse movement, no lot inflation.
        self::assertSame('10.0000', (string) $stock->refresh()->quantity);
        self::assertSame(0, StockMovement::query()
            ->where('reverses_movement_id', $writeOff->id)
            ->count());
    }

    // =========================================================================
    // Gate I6 — the journal entry must actually REACH Posted
    // =========================================================================

    /**
     * Every reporting surface (trial balance, P&L, balance sheet) filters on
     * `Posted`. A Draft write-off entry leaves inventory value on the balance
     * sheet — the exact defect V10 exists to fix — while every "the entry
     * exists" assertion stays green. Pin the status AND the account balances.
     */
    public function test_scrap_write_off_journal_entry_reaches_posted_and_moves_both_accounts(): void
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
            ->where('source_type', 'batch_write_off')
            ->where('source_id', $writeOff->id)
            ->sole();

        self::assertSame(JournalEntryStatus::Posted, $entry->status,
            'a Draft entry appears in no trial balance, P&L or balance sheet');
        self::assertNotNull($entry->posted_at);
        self::assertNotNull($entry->fiscal_hash, 'the entry must be sealed into the GL chain');

        // Balances actually moved: Dr Shrinkage 5.000 / Cr Inventory 5.000.
        self::assertSame('5.000', $this->postedSum($this->accountId(SystemAccountPurpose::InventoryShrinkageExpense), 'debit'));
        self::assertSame('5.000', $this->postedSum($this->accountId(SystemAccountPurpose::Inventory), 'credit'));
    }

    public function test_non_scrap_restock_has_no_write_off_and_reverses_the_original_sale_cost(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $stock = $this->createStockLevel($product->id, '10.0000');
        $sale = $this->createReceipt();
        $line = $this->createProductLine($sale, $product, [
            'quantity' => '2.000',
            'unit_cost' => '2.5000',
        ]);
        $product->update(['cost_price' => '12.000000']);

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
        $restore = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('reason', MovementReason::POSReturn)
            ->sole();
        self::assertSame('2.500000', (string) $restore->unit_cost);
        $entry = JournalEntry::query()
            ->where('source_type', 'inventory_entry')
            ->where('source_id', $restore->id)
            ->with('lines')
            ->sole();
        self::assertSame(0, bccomp('5.000', (string) $entry->lines->sum('debit'), 3));
        self::assertSame(0, bccomp('5.000', (string) $entry->lines->sum('credit'), 3));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return iterable<string, array{string}> */
    public static function t11cScrapPairs(): iterable
    {
        yield 'pair 7 — POS refund scrap x DN confirm' => ['pos-refund-scrap_x_dn-confirm'];
        yield 'pair 8 — POS refund scrap x POS sale' => ['pos-refund-scrap_x_pos-sale'];
    }

    #[DataProvider('t11cScrapPairs')]
    public function test_t11c_scrap_company_advisory_is_terminal_to_the_full_inventory_loop(string $pair): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('[PG] T11c scrap lock-order traces require PostgreSQL advisory locks.');
        }

        $firstProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '2.500000',
        ]);
        $secondProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '3.500000',
        ]);
        $this->createStockLevel($firstProduct->id, '10.0000');
        $this->createStockLevel($secondProduct->id, '10.0000');
        $sale = $this->createReceipt();
        $firstLine = $this->createProductLine($sale, $firstProduct, ['line_number' => 1]);
        $secondLine = $this->createProductLine($sale, $secondProduct, ['line_number' => 2]);

        /** @var list<array{sql: string, bindings: array<int, mixed>}> $trace */
        $trace = [];
        DB::listen(static function (QueryExecuted $query) use (&$trace): void {
            $trace[] = ['sql' => strtolower($query->sql), 'bindings' => array_values($query->bindings)];
        });

        $this->service->processReturn(
            originalReceiptId: $sale->id,
            returnLines: [
                [
                    'line_id' => $firstLine->id,
                    'quantity' => '2.000',
                    'physical_receipt' => true,
                    'resalable' => false,
                    'disposition' => 'scrap',
                ],
                [
                    'line_id' => $secondLine->id,
                    'quantity' => '2.000',
                    'physical_receipt' => true,
                    'resalable' => false,
                    'disposition' => 'scrap',
                ],
            ],
            returnReason: ReturnReason::Defective,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $firstCompanyAdvisory = null;
        $lastInventoryStatement = null;
        foreach ($trace as $index => $query) {
            if ($firstCompanyAdvisory === null
                && str_contains($query['sql'], 'pg_advisory_xact_lock(hashtextextended')
                && ($query['bindings'][0] ?? null) === $this->company->id) {
                $firstCompanyAdvisory = $index;
            }
            if (str_contains($query['sql'], '"stock_levels"')
                || str_contains($query['sql'], '"stock_movements"')) {
                $lastInventoryStatement = $index;
            }
        }

        self::assertNotNull($firstCompanyAdvisory, "{$pair}: production trace did not reach the company GL advisory.");
        self::assertNotNull($lastInventoryStatement, "{$pair}: production trace did not reach inventory persistence.");
        self::assertGreaterThan(
            $lastInventoryStatement,
            $firstCompanyAdvisory,
            "{$pair}: T16d missing — production acquired the company GL advisory before the inventory loop was terminal. "
            ."first_company_advisory={$firstCompanyAdvisory}, last_inventory={$lastInventoryStatement}",
        );
    }

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

    /**
     * Sum of POSTED journal-line debits/credits on one account — i.e. what a
     * trial balance would actually show.
     */
    private function postedSum(string $accountId, string $column): string
    {
        $total = '0.000';
        $lines = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', JournalEntryStatus::Posted->value))
            ->get();

        foreach ($lines as $line) {
            $total = bcadd($total, (string) $line->{$column}, 3);
        }

        return $total;
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
