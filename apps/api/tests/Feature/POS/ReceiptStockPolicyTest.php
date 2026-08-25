<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchMovement;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Application\Services\Fiscal\V3\V3ReceiptHashComputer;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 6 (spec §4.2) — policy-aware stock enforcement on the POS ONLINE
 * draft sale path (`ReceiptCreationService::createReceipt`).
 *
 * - `Block` (default): insufficient stock keeps today's behavior — the
 *   sale is rejected with the unchanged "Insufficient stock for ..."
 *   RuntimeException and stock is untouched (transaction rolls back).
 * - `Warn` / `Off`: a warning is logged and the decrement PROCEEDS into
 *   negative stock — same direction the fiscal-event projection path
 *   (`PosCoreReceiptProjection`) already takes.
 * - The policy is resolved ONCE per receipt at the createReceipt boundary
 *   and threaded down; composite leaf deduction respects the same policy.
 *
 * The projection-path pin (a signed fiscal event always lands, regardless
 * of policy) lives in
 * `Tests\Feature\Fiscal\PosCoreReceiptProjectionTest::test_projection_warns_and_continues_on_insufficient_stock_even_under_block_policy`.
 */
final class ReceiptStockPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private Shift $shift;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->cashier = $cashier;

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '12.50',
            'tax_rate' => '20.00',
        ]);

        // Single unit on hand — selling 2 forces the insufficient branch.
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '1.0000',
            'reserved' => '0.0000',
        ]);

        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    public function test_block_policy_rejects_insufficient_stock(): void
    {
        // Default policy is Block (migration default + non-F&B verticals).
        $this->assertSame(PosStockPolicy::Block, $this->company->refresh()->pos_stock_policy);

        try {
            $this->createReceipt(quantity: '2');
            $this->fail('Expected RuntimeException for insufficient stock under Block policy');
        } catch (\RuntimeException $e) {
            // Pin the existing message — Block keeps today's throw exactly.
            $this->assertStringStartsWith('Insufficient stock', $e->getMessage());
        }

        // Transaction rolled back — stock unchanged.
        $this->assertSame('1.0000', $this->productStock()->quantity);
    }

    public function test_warn_policy_proceeds_and_goes_negative(): void
    {
        $this->company->update(['pos_stock_policy' => PosStockPolicy::Warn]);

        Log::spy();

        $receipt = $this->createReceipt(quantity: '2');

        $this->assertNotNull($receipt->id);
        $this->assertSame('-1.0000', $this->productStock()->quantity);

        $productId = $this->product->id;
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use ($productId): bool {
                return str_contains($message, 'insufficient stock')
                    && ($context['product_id'] ?? null) === $productId;
            });
    }

    public function test_off_policy_proceeds(): void
    {
        $this->company->update(['pos_stock_policy' => PosStockPolicy::Off]);

        $receipt = $this->createReceipt(quantity: '2');

        $this->assertNotNull($receipt->id);
        $this->assertSame('-1.0000', $this->productStock()->quantity);
    }

    public function test_composite_leaf_deduction_respects_policy(): void
    {
        // Leaf product with ZERO stock on hand.
        $leaf = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Leaf Ingredient',
            'tax_rate' => '0.00',
            'cost_price' => '1.00',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $leaf->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '0.0000',
            'reserved' => '0.0000',
        ]);

        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'POLICY-COMBO',
            'name' => 'Policy Combo',
            'base_price' => '5.00',
            'tax_rate' => '0.00',
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $recipe->id]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $leaf->id,
            'quantity' => 1,
        ]);

        // Block (default): leaf shortfall aborts the whole sale.
        try {
            $this->createCompositeReceipt($combo->id);
            $this->fail('Expected RuntimeException for insufficient leaf stock under Block policy');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Insufficient stock', $e->getMessage());
        }

        $this->assertSame('0.0000', $this->productStock($leaf->id)->quantity);

        // Off: same sale succeeds and the leaf goes negative.
        $this->company->update(['pos_stock_policy' => PosStockPolicy::Off]);

        $receipt = $this->createCompositeReceipt($combo->id);

        $this->assertNotNull($receipt->id);
        $this->assertSame('-1.0000', $this->productStock($leaf->id)->quantity);
    }

    // ───── gate r3 R3-5 — the POS composite arm's lot consumption (PG-only) ─────

    /**
     * 🚨 Gate r3 R3-5. `ReceiptCreationService::deductCompositeItemStock()` gained
     * a FEFO lot draw for batch-tracked leaf components: the DIRECT product line
     * always allocated lots, but a leaf sold inside a combo decremented only the
     * aggregate, so `Σ lots` drifted upward exactly like the pre-W4-5 delivery
     * note. The arm shipped with zero tests — every combo test in
     * `ComboReceiptTest` is skipped and the policy test above uses a
     * non-batch-tracked leaf — so the branch was dead in CI on both drivers.
     *
     * Three properties, all owed by the gate:
     *   1. the leaf consumes FEFO and `Σ lots == stock_levels`;
     *   2. `pos_receipt_line_batch_allocations` stays EMPTY for the leaf — the
     *      known gap (a leaf has no receipt line of its own and
     *      `receipt_line_id` is NOT NULL), pinned so it cannot be quietly
     *      forgotten;
     *   3. a short-lot leaf aborts the WHOLE receipt.
     */
    public function test_a_batch_tracked_composite_leaf_consumes_fefo_lots(): void
    {
        $this->skipUnlessPostgres();

        [$combo, $leaf] = $this->batchTrackedCombo('10.0000');
        $lot = $this->seedLeafLot($leaf, 'LOT-LEAF-A', now()->addDays(30)->toDateString(), '10.0000');

        $receipt = $this->createCompositeReceipt($combo->id);

        $this->assertNotNull($receipt->id);
        $this->assertSame('9.0000', $this->productStock($leaf->id)->quantity);
        $this->assertSame(
            0,
            bccomp('9.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4),
            'the leaf lot moves with the aggregate — Σ lots must not drift',
        );

        // The fixture's own GRN receipt movement also exists now (R5-8), so scope
        // to the SALE's issue movement.
        $movement = StockMovement::query()
            ->where('product_id', $leaf->id)
            ->where('movement_type', MovementType::Issue)
            ->sole();
        $legs = BatchMovement::query()->where('movement_id', $movement->id)->get();
        $this->assertCount(1, $legs, 'one inventory_batch_movements leg, keyed to the leaf issue movement');
        $this->assertSame(0, bccomp('-1.0000', (string) $legs->first()?->quantity, 4));

        // Known gap, deliberately pinned: no per-leaf allocation snapshot.
        $this->assertSame(
            0,
            ReceiptLineBatchAllocation::query()->count(),
            'a leaf has no pos_receipt_lines row of its own and receipt_line_id is NOT NULL — '
            .'the LEDGER leg is written, the SNAPSHOT is a named follow-up',
        );

        // The lot draw happens INSIDE the receipt transaction and entirely BEFORE
        // the seal: `createReceipt()` leaves `current_hash` NULL — sealing is
        // `ReceiptFinalizationService::finalize()`'s job — so no lot state can
        // reach the chain through this path.
        $this->assertNull(
            $receipt->refresh()->current_hash,
            'createReceipt() does not seal; the lot draw is complete before any hash exists',
        );

        // And the canonical v3 payload is a pure function of the RECEIPT: it does
        // not read `inventory_batch_stock` or `inventory_batch_movements`, so the
        // lot legs written above cannot perturb the hash inputs. Recomputing with
        // the legs present, then again after mutating the lot ledger, yields the
        // byte-identical digest.
        $computer = app(V3ReceiptHashComputer::class);
        $before = $computer->compute($receipt);

        BatchStock::where('batch_id', $lot->id)->update(['quantity' => '1.2345']);

        $this->assertSame(
            $before,
            $computer->compute($receipt->refresh()),
            'the sealed payload must be byte-identical regardless of lot state',
        );
    }

    /**
     * Gate r3 R3-5 (3) — a leaf whose lots cannot cover the draw aborts the whole
     * receipt: no `pos_receipts` row, no `stock_movements`, and the terminal
     * sequence is not advanced. Strict lot fulfilment is not relaxed by
     * `pos_stock_policy` (the FU-1 asymmetry), so this holds under Off too.
     */
    public function test_a_short_lot_composite_leaf_aborts_the_whole_receipt(): void
    {
        $this->skipUnlessPostgres();

        $this->company->update(['pos_stock_policy' => PosStockPolicy::Off]);

        [$combo, $leaf] = $this->batchTrackedCombo('10.0000');
        // Aggregate says 10, the lots hold nothing.
        $sequenceBefore = $this->terminal->refresh()->current_sequence;

        try {
            $this->createCompositeReceipt($combo->id);
            $this->fail('Expected InsufficientBatchStockException for a leaf with no lot stock');
        } catch (InsufficientBatchStockException) {
            // expected
        }

        $this->assertSame(0, Receipt::query()->count(), 'no receipt may survive the rollback');
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('10.0000', $this->productStock($leaf->id)->quantity);
        $this->assertSame($sequenceBefore, $this->terminal->refresh()->current_sequence);
    }

    /**
     * 🚨 Gate r5 R5-1 — the wrong-lot regression, and the probe that discriminates.
     *
     * r4 deleted the per-line `pos_receipt_line_batch_allocations` snapshot in
     * favour of a ledger scan aggregated over the whole (product, location,
     * variant) tuple. The ledger cannot say which RECEIPT shipped a leg, so the
     * heuristic is only right when the returned sale was the last one out.
     *
     * The gate's probe, verbatim: lot A (short-dated, 5) and lot B (long-dated,
     * 10); receipt 1 sells 5 → FEFO takes lot A; receipt 2 sells 5 → lot B.
     * Returning RECEIPT 1 must credit **lot A**. Before this fix it credited lot B
     * — lot A read 0 with 5 of its units physically back on the shelf, lot B read
     * 10 while only 5 of its units exist, `BatchTraceabilityController`'s recall
     * trail (which still reads these allocation rows) was wrong in both
     * directions, and the short-dated units were re-labelled long-dated so FEFO
     * would ship them LAST.
     *
     * The lane's earlier POS pin could not see any of this: it seeded ONE lot, so
     * every candidate ordering gave the same answer.
     */
    public function test_a_pos_return_credits_the_lot_that_receipts_own_line_consumed(): void
    {
        $this->skipUnlessPostgres();

        [$product, $lotA, $lotB] = $this->twoLotBatchTrackedProduct();

        $receiptOne = $this->createDirectReceipt($product, '5');
        $receiptTwo = $this->createDirectReceipt($product, '5');

        // FEFO: receipt 1 emptied the short-dated lot, receipt 2 took from the long one.
        $this->assertSame(0, bccomp('0.0000', (string) BatchStock::where('batch_id', $lotA->id)->value('quantity'), 4));
        $this->assertSame(0, bccomp('5.0000', (string) BatchStock::where('batch_id', $lotB->id)->value('quantity'), 4));
        $this->assertNotSame($receiptOne->id, $receiptTwo->id);

        app(ReceiptReturnService::class)->processReturn(
            originalReceiptId: $receiptOne->id,
            returnLines: [[
                'line_id' => (string) $receiptOne->lines()->sole()->id,
                'quantity' => '5',
            ]],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $this->assertSame(
            0,
            bccomp('5.0000', (string) BatchStock::where('batch_id', $lotA->id)->value('quantity'), 4),
            'the SHORT-DATED lot receipt 1 actually consumed must be credited — the gate measured it left at 0',
        );
        $this->assertSame(
            0,
            bccomp('5.0000', (string) BatchStock::where('batch_id', $lotB->id)->value('quantity'), 4),
            'the long-dated lot receipt 2 consumed must be untouched — the gate measured it at 10',
        );
    }

    /**
     * Gate r5 R5-1, the fallback half: a sale with NO allocation snapshot (pre-lane
     * data) still restores through the heuristic. Provenance is a hint, not a
     * precondition.
     */
    public function test_a_pos_return_without_a_snapshot_still_restores_through_the_heuristic(): void
    {
        $this->skipUnlessPostgres();

        [$product, $lotA] = $this->twoLotBatchTrackedProduct();

        $receipt = $this->createDirectReceipt($product, '5');

        // Simulate pre-lane data: the sale's provenance rows are gone, the ledger
        // legs remain.
        ReceiptLineBatchAllocation::query()->delete();

        app(ReceiptReturnService::class)->processReturn(
            originalReceiptId: $receipt->id,
            returnLines: [[
                'line_id' => (string) $receipt->lines()->sole()->id,
                'quantity' => '5',
            ]],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $this->assertSame(
            0,
            bccomp('5.0000', (string) BatchStock::where('batch_id', $lotA->id)->value('quantity'), 4),
            'the heuristic credits the most-recently-shipped lot when no snapshot exists',
        );
        $this->assertNull(
            Batch::query()->where('product_id', $product->id)->where('batch_number', 'DEFAULT')->first(),
        );
    }

    /**
     * A batch-tracked product with TWO dated lots — the shape the wave-4 tenant
     * carries (`LOT-SIRÔP-2026A` / `LOT-SIRÔP-2026C` on one product).
     *
     * @return array{0: Product, 1: Batch, 2: Batch}
     */
    private function twoLotBatchTrackedProduct(): array
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'R51-'.bin2hex(random_bytes(3)),
            'name' => 'Two-lot batch-tracked product',
            'unit_price' => '12.50',
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '15.0000',
            'reserved' => '0.0000',
        ]);

        $lotA = $this->seedLeafLot($product, 'LOT-A-SHORT', now()->addDays(30)->toDateString(), '5.0000');
        $lotB = $this->seedLeafLot($product, 'LOT-B-LONG', now()->addDays(150)->toDateString(), '10.0000');

        return [$product, $lotA, $lotB];
    }

    /** @param  numeric-string  $quantity */
    private function createDirectReceipt(Product $product, string $quantity): Receipt
    {
        return app(ReceiptCreationService::class)->createReceipt(
            terminalId: $this->terminal->id,
            lines: [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => '12.50',
            ]],
        );
    }

    /**
     * Gate r4 R4-7, the closed half — a DIRECT batch-tracked line returned through
     * the POS lands back on the lot it left, resolved from the movement ledger by
     * the same Domain service the document channel uses, and carries its own
     * `inventory_batch_movements` leg (the old snapshot restore wrote none).
     */
    public function test_a_pos_return_credits_the_origin_lot_through_the_shared_domain_service(): void
    {
        $this->skipUnlessPostgres();

        $batchProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'R47-DIRECT-'.bin2hex(random_bytes(3)),
            'name' => 'Batch-tracked direct line',
            'unit_price' => '12.50',
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $batchProduct->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $lot = $this->seedLeafLot($batchProduct, 'LOT-DIRECT-A', now()->addDays(30)->toDateString(), '10.0000');

        $receipt = app(ReceiptCreationService::class)->createReceipt(
            terminalId: $this->terminal->id,
            lines: [[
                'product_id' => $batchProduct->id,
                'quantity' => '3',
                'unit_price' => '12.50',
            ]],
        );

        $this->assertSame(0, bccomp('7.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4));

        app(ReceiptReturnService::class)->processReturn(
            originalReceiptId: $receipt->id,
            returnLines: [[
                'line_id' => (string) $receipt->lines()->sole()->id,
                'quantity' => '3',
            ]],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        $this->assertSame(
            0,
            bccomp('10.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4),
            'the returned units go back on the lot they left',
        );
        $this->assertSame('10.0000', $this->productStock($batchProduct->id)->quantity);
        $this->assertNull(
            Batch::query()->where('product_id', $batchProduct->id)->where('batch_number', 'DEFAULT')->first(),
        );

        $credit = StockMovement::query()
            ->where('product_id', $batchProduct->id)
            ->where('reason', MovementReason::POSReturn)
            ->sole();
        $legs = BatchMovement::query()->where('movement_id', $credit->id)->get();
        $this->assertCount(1, $legs, 'the credit carries its own ledger leg — the snapshot restore wrote none');
        $this->assertSame(0, bccomp('3.0000', (string) $legs->first()?->quantity, 4));
    }

    /**
     * 🚨 Gate r4 R4-6/R4-7 — what a COMBO return actually does today.
     *
     * Two separate things were tangled here, and this test separates them.
     *
     * **Closed (R4-7).** The POS return arm used to resolve the origin lot from
     * the per-line `ReceiptLineBatchAllocation` snapshot and restore NOTHING when
     * the snapshot was absent, while the document arm resolved from the movement
     * ledger and fell back to a `DEFAULT` lot — two policies for one physical
     * event, disagreeing. Both channels now go through the one Domain service,
     * `FEFOInventoryService::restoreBatchesForReturn()`, and credit lots
     * identically. That convergence is pinned by the document-channel tests and by
     * the fact that a returned DIRECT line lands back on its origin lot.
     *
     * **Open, and stated rather than hidden (R4-6).** The return path does not
     * DECOMPOSE a combo at all: a composite `pos_receipt_lines` row carries
     * `product_id = NULL`, so neither the aggregate restore nor the lot restore
     * runs for the leaf. The leaf's aggregate and its lots therefore BOTH stay
     * decremented — the ledger does not drift, `Σ lots` still equals
     * `stock_levels`, but the physically-returned units are missing from both. The
     * gate could not execute this (`tests/Unit/POS/ReceiptReturnServiceTest` is
     * 11-of-14 red on both drivers with an `ArgumentCountError` predating this
     * lane); this test executes it, and its assertions are the measured truth, not
     * the desired one. Composite-return decomposition is a named residual — fixing
     * it means decomposing the aggregate credit, the GL and the disposition too,
     * which is its own lane.
     */
    public function test_returning_a_combo_leaves_the_leaf_undecomposed_but_never_drifts_the_lot_ledger(): void
    {
        $this->skipUnlessPostgres();

        [$combo, $leaf] = $this->batchTrackedCombo('10.0000');
        $lot = $this->seedLeafLot($leaf, 'LOT-LEAF-A', now()->addDays(30)->toDateString(), '10.0000');

        $receipt = $this->createCompositeReceipt($combo->id);

        $this->assertSame('9.0000', $this->productStock($leaf->id)->quantity);
        $this->assertSame(0, bccomp('9.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4));

        app(ReceiptReturnService::class)->processReturn(
            originalReceiptId: $receipt->id,
            returnLines: [[
                'line_id' => (string) $receipt->lines()->sole()->id,
                'quantity' => '1',
            ]],
            returnReason: ReturnReason::CustomerChangedMind,
            cashier: $this->cashier,
            terminalId: $this->terminal->id,
        );

        // The leaf is not decomposed on the way back — neither half moves …
        $this->assertSame(
            '9.0000',
            $this->productStock($leaf->id)->quantity,
            'the aggregate credit does not decompose the combo either — this is the OPEN half (R4-6)',
        );
        $this->assertSame(0, bccomp('9.0000', (string) BatchStock::where('batch_id', $lot->id)->value('quantity'), 4));

        // … so the two stay in agreement: no lot-ledger drift is introduced.
        $this->assertSame(
            0,
            bccomp(
                (string) $this->productStock($leaf->id)->quantity,
                (string) BatchStock::where('batch_id', $lot->id)->value('quantity'),
                4,
            ),
            'Σ lots == stock_levels for the leaf — the convergence must not create the drift it exists to prevent',
        );

        $this->assertNull(
            Batch::query()->where('product_id', $leaf->id)->where('batch_number', 'DEFAULT')->first(),
            'and no phantom DEFAULT lot is minted for a leaf that was never credited',
        );
    }

    /**
     * A combo whose leaf is batch-tracked, with the leaf stocked at $quantity.
     *
     * @param  numeric-string  $quantity
     * @return array{0: CompositeItem, 1: Product}
     */
    private function batchTrackedCombo(string $quantity): array
    {
        $leaf = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'R35-LEAF-'.bin2hex(random_bytes(3)),
            'name' => 'Batch-tracked leaf',
            'unit_price' => '5.00',
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $leaf->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);

        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'R35-COMBO-'.bin2hex(random_bytes(3)),
            'name' => 'Batch Combo',
            'base_price' => '5.00',
            'tax_rate' => '0.00',
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $recipe->id]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $leaf->id,
            'quantity' => 1,
        ]);

        return [$combo, $leaf];
    }

    /**
     * A lot seeded THE WAY A GOODS RECEIPT SEEDS ONE — batch row, batch stock, and
     * the positive `inventory_batch_movements` leg keyed to a real receipt
     * `stock_movements` row.
     *
     * Gate r5 R5-8: this helper used to write the rows with `Batch::create()` /
     * `BatchStock::create()` and NO ledger leg — the exact shape R4-11 corrected in
     * `BatchTrackedSalesOrderConfirmFefoTest`, where it had hidden a Critical for a
     * whole round. Under the outstanding-outbound ceiling an inbound leg is
     * genuinely irrelevant (only negative legs and return-credit positives count),
     * so it hid nothing here — but two sibling fixtures claiming to be the same
     * thing while differing is exactly how the last one got missed.
     *
     * @param  numeric-string  $quantity
     */
    private function seedLeafLot(Product $leaf, string $batchNumber, string $expiryDate, string $quantity): Batch
    {
        $batchService = app(BatchStockService::class);

        $batch = $batchService->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $leaf->id,
            batchNumber: $batchNumber,
            expiryDate: $expiryDate,
            manufacturingDate: now()->subDay()->toDateString(),
        );

        $movement = StockMovement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $leaf->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::GoodsReceipt,
            'quantity' => $quantity,
            'quantity_before' => '0.0000',
            'quantity_after' => $quantity,
            'reference' => 'GRN-'.$batchNumber,
            'occurred_at' => now()->subDay(),
        ]);

        $batchService->receiveBatchStock(
            tenantId: $this->tenant->id,
            batchId: $batch->id,
            locationId: $this->location->id,
            quantity: $quantity,
            movementId: $movement->id,
        );

        return $batch;
    }

    /**
     * FEFO lot consumption uses `FOR UPDATE … SKIP LOCKED`, which SQLite cannot
     * parse — the same reason `BatchChainE2ETest` is PostgreSQL-only.
     */
    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Requires PostgreSQL: FEFO lot consumption uses FOR UPDATE SKIP LOCKED.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createReceipt(string $quantity): Receipt
    {
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        return $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $this->product->id,
                    'quantity' => $quantity,
                    'unit_price' => '12.50',
                ],
            ],
        );
    }

    private function createCompositeReceipt(string $compositeItemId): Receipt
    {
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        return $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'composite_item_id' => $compositeItemId,
                    'quantity' => '1',
                    'unit_price' => '5.00',
                ],
            ],
        );
    }

    private function productStock(?string $productId = null): StockLevel
    {
        /** @var StockLevel $stock */
        $stock = StockLevel::where('product_id', $productId ?? $this->product->id)
            ->where('location_id', $this->location->id)
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        return $stock;
    }
}
