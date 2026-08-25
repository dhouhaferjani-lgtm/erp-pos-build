<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\BatchExpiry\Application\Services\LotLedgerDriftCensus;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W4R-2 — the LIVE POS receipt projection must move LOTS, not only the
 * aggregate.
 *
 * The wave-4 re-run measured 27 units of lot drift on the first tenant and
 * every unit of it was a live POS sale or refund: `stock_levels` decremented,
 * `Σ inventory_batch_stock` did not, `pos_receipt_line_batch_allocations` held
 * zero rows. W2-7/W4-5 HAD built the FEFO arms — but on
 * `POS\Application\Services\ReceiptCreationService`, which serves
 * `POST /pos/receipts`, retired at 410 `NEW_SALE_AUTHORING_RETIRED`. The live
 * device-authored path is this projection, and it contained no FEFO call and
 * no batch write of any kind.
 *
 * This suite pins the fixed contract, on the LIVE path only:
 *
 *   (a) a batch-tracked sale line draws FEFO lots and snapshots each one into
 *       `pos_receipt_line_batch_allocations`;
 *   (b) `Σ lots == stock_levels` after every receipt and every refund;
 *   (c) a refund credits the lot the SALE took (per-line provenance), not
 *       whichever lot shipped last;
 *   (d) replay writes no second leg (the `fiscal_event_id` idempotency guard
 *       covers the lot legs exactly as it covers the aggregate);
 *   (e) a lot shortfall NEVER rejects an already-signed event (Model 1 §4.1);
 *   (f) the sealed canonical bytes and the chain hash are untouched;
 *   (g) `disposition = scrap` credits NO lot (DPA V10: scrapped goods
 *       deliberately never re-enter a sellable lot, and the write-off leg
 *       writes no lot leg either — crediting here would push Σ lots ABOVE the
 *       aggregate);
 *   (h) a composite line moves NOTHING on this path — no aggregate stock and no
 *       lot stock — so it contributes zero drift. That is the W4R-3 residual
 *       (`decrementStockForLines()`'s own docblock and handback §8), not a
 *       contract this class pins; there is deliberately no test for it, because
 *       a composite line's sealed `product_id` never survives `Str::isUuid()`.
 *
 * Fix round r1 adds the gates' four conditions:
 *
 *   (i) a lot arm that THROWS never takes the sealed receipt down with it
 *       (inventory F-1 / fiscal F-1+F-2);
 *   (j) a provenance hint naming the `DEFAULT` lot is honoured, not dropped
 *       (inventory F-2);
 *   (k) refunding a PRE-LANE sale mints no phantom `DEFAULT@today+365` and
 *       leaves drift at zero (inventory F-3);
 *   (l) the drift a shortfall leaves is DETECTABLE, not merely logged
 *       (inventory F-4 / fiscal F-5).
 *
 * **Rule 20 — projections run with NO CompanyContext.** Every `apply()` below
 * is preceded by `app(CompanyContext::class)->clear()` so the suite mirrors the
 * queue worker rather than masking a context dependency.
 */
final class PosCoreReceiptProjectionBatchLotTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

        // Bound only for factory paths in setUp; CLEARED before every apply().
        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
    }

    // =====================================================================
    // (a)+(b) sale draws FEFO lots, snapshots allocations, Σ lots reconciles
    // =====================================================================

    public function test_batch_tracked_sale_draws_fefo_lots_and_snapshots_allocations(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '13.0000');
        $early = $this->seedLot($product->id, 'LOT-EARLY', now()->addDays(30)->toDateString(), '3.0000');
        $late = $this->seedLot($product->id, 'LOT-LATE', now()->addDays(120)->toDateString(), '10.0000');

        $event = $this->saleEvent($product->id, '5.000');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $stock->refresh();
        $this->assertSame('8.0000', $stock->quantity);

        // FEFO: the earliest-expiry lot is drained first, the remainder from the later lot.
        $this->assertSame('0.0000', $this->lotQuantity($early->id));
        $this->assertSame('8.0000', $this->lotQuantity($late->id));
        $this->assertSame('8.0000', $this->lotSum($product->id), 'Σ lots must equal stock_levels after the sale.');

        // One signed ledger leg per lot, keyed to the sale's own issue movement.
        $movement = DB::table('stock_movements')->where('reason', 'pos_sale')->first();
        $this->assertNotNull($movement);
        $legs = DB::table('inventory_batch_movements')
            ->where('movement_id', $movement->id)
            ->orderBy('batch_id')
            ->get();
        $this->assertCount(2, $legs);
        $this->assertSame(
            ['-3.0000', '-2.0000'],
            $legs->sortBy('batch_id')->pluck('quantity')->map(fn ($q) => (string) $q)->values()->all(),
        );

        // Per-line provenance snapshot — the only record of which lot went to
        // which customer, and the input the refund arm reads back.
        $lineId = DB::table('pos_receipt_lines')->value('id');
        $allocations = DB::table('pos_receipt_line_batch_allocations')
            ->where('receipt_line_id', $lineId)
            ->orderBy('batch_id')
            ->get();
        $this->assertCount(2, $allocations);
        $this->assertSame(
            ['3.0000', '2.0000'],
            $allocations->pluck('quantity')->map(fn ($q) => (string) $q)->values()->all(),
        );
        $earliestAllocation = $allocations->first();
        $this->assertNotNull($earliestAllocation);
        $this->assertSame('LOT-EARLY', (string) $earliestAllocation->batch_number);
    }

    public function test_non_batch_tracked_sale_writes_no_lot_legs(): void
    {
        $product = $this->seedProduct(requiresBatchTracking: false);
        $stock = $this->seedStockLevel($product->id, null, '10.0000');

        $event = $this->saleEvent($product->id, '4.000');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $stock->refresh();
        $this->assertSame('6.0000', $stock->quantity);
        $this->assertSame(0, DB::table('inventory_batch_movements')->count());
        $this->assertSame(0, DB::table('pos_receipt_line_batch_allocations')->count());
    }

    // =====================================================================
    // (d) replay writes no second leg
    // =====================================================================

    public function test_replay_of_the_same_sale_writes_no_second_lot_leg(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '10.0000');
        $lot = $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $event = $this->saleEvent($product->id, '2.000');
        $projector = $this->app->make(PosCoreReceiptProjection::class);

        app(CompanyContext::class)->clear();
        $projector->apply($event);

        app(CompanyContext::class)->clear();
        $projector->apply($event);

        $stock->refresh();
        $this->assertSame('8.0000', $stock->quantity);
        $this->assertSame('8.0000', $this->lotQuantity($lot->id), 'replay must not double-consume the lot');
        $this->assertSame(1, DB::table('inventory_batch_movements')->count());
        $this->assertSame(1, DB::table('pos_receipt_line_batch_allocations')->count());
    }

    // =====================================================================
    // (e) a lot shortfall never rejects a sealed event
    // =====================================================================

    public function test_lot_shortfall_never_rejects_the_sealed_sale(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        // The aggregate can cover the sale; the lot ledger cannot (2 of 5 units
        // sit in no lot at all). A projector may never REJECT an already-signed
        // event, so the receipt still projects and the shortfall is observable.
        $stock = $this->seedStockLevel($product->id, null, '10.0000');
        $lot = $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '3.0000');

        $event = $this->saleEvent($product->id, '5.000');

        Log::spy();
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(1, DB::table('pos_receipts')->count(), 'the sealed sale must still project');
        $stock->refresh();
        $this->assertSame('5.0000', $stock->quantity);
        $this->assertSame('0.0000', $this->lotQuantity($lot->id), 'the lot is drawn down as far as it goes');

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'lot shortfall')
                && ($context['shortfall'] ?? null) === '2.0000'
        );
    }

    // =====================================================================
    // (f) sealed bytes untouched
    // =====================================================================

    public function test_projection_leaves_the_sealed_bytes_and_chain_hash_byte_identical(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $this->seedStockLevel($product->id, null, '10.0000');
        $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $event = $this->saleEvent($product->id, '2.000');
        $bytesBefore = $this->sealedBytes($event->id);
        $hashBefore = (string) DB::table('fiscal_events')->where('id', $event->id)->value('current_hash');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $bytesAfter = $this->sealedBytes($event->id);
        $hashAfter = (string) DB::table('fiscal_events')->where('id', $event->id)->value('current_hash');

        $this->assertSame($bytesBefore, $bytesAfter);
        $this->assertSame($hashBefore, $hashAfter);
        $this->assertSame(hash('sha256', $bytesBefore), hash('sha256', $bytesAfter));
        $this->assertGreaterThan(0, DB::table('inventory_batch_movements')->count(), 'the lot leg really was written');
    }

    // =====================================================================
    // (c) refund credits the PROVENANCE lot, not the last one shipped
    // =====================================================================

    public function test_refund_credits_the_lot_the_sale_actually_took(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '20.0000');
        $shortDated = $this->seedLot($product->id, 'LOT-A', now()->addDays(20)->toDateString(), '10.0000');
        $longDated = $this->seedLot($product->id, 'LOT-B', now()->addDays(200)->toDateString(), '10.0000');

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        // Receipt 1 drains the short-dated lot A (FEFO).
        $sale1 = $this->saleEvent($product->id, '10.000', sequenceNumber: 1, receiptUuid: $this->uuid(1));
        app(CompanyContext::class)->clear();
        $projector->apply($sale1);

        // Receipt 2 then ships the long-dated lot B.
        $sale2 = $this->saleEvent($product->id, '4.000', sequenceNumber: 2, receiptUuid: $this->uuid(2));
        app(CompanyContext::class)->clear();
        $projector->apply($sale2);

        $this->assertSame('0.0000', $this->lotQuantity($shortDated->id));
        $this->assertSame('6.0000', $this->lotQuantity($longDated->id));

        // Refunding receipt 1 must credit lot A — the lot receipt 1 took. The
        // ledger heuristic alone would credit B (most recently shipped).
        $refund = $this->refundEvent($sale1, $product->id, '10.000', 'restock', sequenceNumber: 3);
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $stock->refresh();
        $this->assertSame('16.0000', $stock->quantity);
        $this->assertSame('10.0000', $this->lotQuantity($shortDated->id), 'the short-dated lot must get its units back');
        $this->assertSame('6.0000', $this->lotQuantity($longDated->id), 'the long-dated lot must not be over-credited');
        $this->assertSame('16.0000', $this->lotSum($product->id), 'Σ lots must equal stock_levels after the refund.');
    }

    public function test_replay_of_the_same_refund_writes_no_second_credit(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '10.0000');
        $lot = $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $sale = $this->saleEvent($product->id, '4.000', sequenceNumber: 1, receiptUuid: $this->uuid(1));
        app(CompanyContext::class)->clear();
        $projector->apply($sale);

        $refund = $this->refundEvent($sale, $product->id, '4.000', 'restock', sequenceNumber: 2);
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $stock->refresh();
        $this->assertSame('10.0000', $stock->quantity);
        $this->assertSame('10.0000', $this->lotQuantity($lot->id));
        $this->assertSame(2, DB::table('inventory_batch_movements')->count(), 'one issue leg + one credit leg, never three');
    }

    // =====================================================================
    // (g) scrap credits no lot — DPA V10 parity
    // =====================================================================

    public function test_scrap_disposition_credits_no_lot(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '10.0000');
        $lot = $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $sale = $this->saleEvent($product->id, '4.000', sequenceNumber: 1, receiptUuid: $this->uuid(1));
        app(CompanyContext::class)->clear();
        $projector->apply($sale);

        $this->assertSame('6.0000', $this->lotQuantity($lot->id));

        // Scrap: restore leg + write-off leg net to zero on the aggregate, and
        // the write-off writes NO lot leg (ReturnScrapWriteOffService docblock:
        // scrapped goods deliberately never re-enter a sellable lot). Crediting
        // the lot on the restore leg would leave Σ lots ABOVE the aggregate.
        $refund = $this->refundEvent($sale, $product->id, '4.000', 'scrap', sequenceNumber: 2);
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $stock->refresh();
        $this->assertSame('6.0000', $stock->quantity, 'scrap nets to zero on the aggregate');
        $this->assertSame('6.0000', $this->lotQuantity($lot->id), 'scrap must not re-credit the lot');
        $this->assertSame('6.0000', $this->lotSum($product->id), 'Σ lots must still equal stock_levels.');
    }

    // =====================================================================
    // FIX ROUND r1 — (i) containment: a failing lot arm never loses the receipt
    //   inventory gate F-1 · fiscal gate F-1 + F-2
    // =====================================================================

    public function test_a_throwing_lot_arm_never_takes_the_sealed_receipt_down(): void
    {
        // Deliberately the MIRROR of `requiresPostgresLotDraw()`: this one runs
        // ONLY where the FEFO draw cannot. On SQLite `consumeBatchesAtomically()`
        // raises a real `QueryException` (`FOR UPDATE … SKIP LOCKED` is
        // unparseable), which is precisely the "any other exception" shape the
        // fiscal gate proved took the whole projection down — it injected a
        // `LogicException` and measured `receipts = 0`. So the driver limitation
        // is not worked around here, it is USED as an honest fault injector, and
        // this is the one containment proof that needs no mock of a final class.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('Needs a driver on which the FEFO draw genuinely throws; on PostgreSQL it succeeds.');
        }

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '10.0000');
        $lot = $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $event = $this->saleEvent($product->id, '4.000');

        Log::spy();
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // The sealed receipt, its lines, its VAT rows and its payment legs all
        // survive. THIS is the contract: a projector may never reject a signed
        // event, and an inventory sub-act is not allowed to become a rejection.
        $this->assertSame(1, DB::table('pos_receipts')->count());
        $this->assertSame(1, DB::table('pos_receipt_lines')->count());
        $this->assertSame(1, DB::table('pos_receipt_payments')->count());

        // The aggregate arm ran and committed — it is upstream of the savepoint.
        $stock->refresh();
        $this->assertSame('6.0000', $stock->quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('reason', 'pos_sale')->count());

        // The lot arm rolled back cleanly inside its own savepoint: no half leg,
        // no orphan allocation row.
        $this->assertSame('10.0000', $this->lotQuantity($lot->id));
        $this->assertSame(0, DB::table('inventory_batch_movements')->count());
        $this->assertSame(0, DB::table('pos_receipt_line_batch_allocations')->count());

        // And it is loud: the tuple is now drifted and the operator is told where
        // to find it.
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'the lot arm failed')
                && str_contains($message, 'inventory:lot-drift-census')
                && ($context['arm'] ?? null) === 'sale'
        );
    }

    public function test_refund_of_a_product_level_line_on_a_variant_bearing_product_still_projects(): void
    {
        // The inventory gate's probe P6 / the fiscal gate's probe P1. A
        // batch-tracked product that has an ACTIVE variant, refunded on a
        // PRODUCT-level line whose sale left no lot provenance, drove
        // `restoreBatchesForReturn()` into `defaultBatchId()`, which REFUSES to
        // mint a product-level DEFAULT lot for such a product
        // (`MissingVariantException`). Deterministic, so every Horizon retry
        // reproduced it and the signed refund dead-lettered: no receipt, no VAT,
        // no payment leg, no drawer movement, for money already handed back.
        //
        // Two independent fixes close it — the containment savepoint, and F-3's
        // `mintDefaultLotForUnattributed: false`, which means the refund arm no
        // longer calls `defaultBatchId()` at all. This test asserts the OUTCOME
        // rather than either mechanism, so it keeps biting if either is undone.
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        ProductVariant::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        $stock = $this->seedStockLevel($product->id, null, '10.0000');
        $lot = $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $sale = $this->saleEvent($product->id, '4.000', sequenceNumber: 1, receiptUuid: $this->uuid(1));
        app(CompanyContext::class)->clear();
        $projector->apply($sale);

        // Rewind the lot side to the PRE-LANE historical state: the sale moved the
        // aggregate and the lot ledger never heard about it. That is exactly the
        // shape of all 27 drifted units on the re-run tenant.
        $this->rewindLotSideToPreLaneState($lot->id, '10.0000');

        $refund = $this->refundEvent($sale, $product->id, '4.000', 'restock', sequenceNumber: 2);
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $this->assertSame(2, DB::table('pos_receipts')->count(), 'the sealed refund must be projected, never dead-lettered');
        $this->assertSame(
            1,
            DB::table('pos_receipts')->where('fiscal_event_id', $refund->id)->count(),
            'the refund receipt row itself must exist',
        );

        $stock->refresh();
        $this->assertSame('10.0000', $stock->quantity, 'the aggregate is restored');
    }

    // =====================================================================
    // FIX ROUND r1 — (j) a provenance hint naming the DEFAULT lot is honoured
    //   inventory gate F-2 (probe P4)
    // =====================================================================

    public function test_refund_credits_the_default_lot_when_that_is_the_lot_the_sale_took(): void
    {
        $this->requiresPostgresLotDraw();

        // The first tenant's dominant shape, not an edge case: every batch-tracked
        // product there carries a DEFAULT lot, and where a dated lot also exists
        // the DEFAULT one has the EARLIER expiry — so FEFO draws DEFAULT first on
        // essentially every POS sale, and every refund of one takes this path.
        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '20.0000');
        $default = $this->seedLot($product->id, 'DEFAULT', now()->addDays(10)->toDateString(), '10.0000');
        $dated = $this->seedLot($product->id, 'LOT-DATED', now()->addDays(200)->toDateString(), '10.0000');

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $sale1 = $this->saleEvent($product->id, '4.000', sequenceNumber: 1, receiptUuid: $this->uuid(1));
        app(CompanyContext::class)->clear();
        $projector->apply($sale1);

        $sale2 = $this->saleEvent($product->id, '8.000', sequenceNumber: 2, receiptUuid: $this->uuid(2));
        app(CompanyContext::class)->clear();
        $projector->apply($sale2);

        $this->assertSame('0.0000', $this->lotQuantity($default->id), 'FEFO drained the earlier-expiry DEFAULT lot first');
        $this->assertSame('8.0000', $this->lotQuantity($dated->id));

        $refund = $this->refundEvent($sale1, $product->id, '4.000', 'restock', sequenceNumber: 3);
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $stock->refresh();
        $this->assertSame('12.0000', $stock->quantity);
        // Pre-fix this credited 2 of the 4 units to LOT-DATED — a lot that never
        // shipped them — because the ceiling map excluded DEFAULT lots, so the
        // hint found no ceiling and fell through to the "most recently shipped"
        // heuristic. Σ lots still reconciled, which is why nothing caught it.
        $this->assertSame('4.0000', $this->lotQuantity($default->id), 'the DEFAULT lot shipped them, so the DEFAULT lot gets them back');
        $this->assertSame('8.0000', $this->lotQuantity($dated->id), 'the dated lot must not be credited units it never shipped');
        $this->assertSame('12.0000', $this->lotSum($product->id));
    }

    // =====================================================================
    // FIX ROUND r1 — (k) refunding a PRE-LANE sale mints no phantom DEFAULT
    //   inventory gate F-3 (probe P1)
    // =====================================================================

    public function test_refund_of_a_pre_lane_sale_mints_no_phantom_default_lot_and_closes_the_drift(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '10.0000');
        $lot = $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        $sale = $this->saleEvent($product->id, '4.000', sequenceNumber: 1, receiptUuid: $this->uuid(1));
        app(CompanyContext::class)->clear();
        $projector->apply($sale);

        $this->rewindLotSideToPreLaneState($lot->id, '10.0000');

        // The historical state: aggregate 6, Σ lots 10 — drift +4.
        $stock->refresh();
        $this->assertSame('6.0000', $stock->quantity);
        $this->assertSame('10.0000', $this->lotSum($product->id));

        $refund = $this->refundEvent($sale, $product->id, '4.000', 'restock', sequenceNumber: 2);
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $stock->refresh();
        $this->assertSame('10.0000', $stock->quantity, 'the aggregate is restored');
        $this->assertSame('10.0000', $this->lotQuantity($lot->id), 'no lot is credited — nothing is attributable');
        $this->assertSame('10.0000', $this->lotSum($product->id));

        // The whole point: restoring the aggregate ALONE closes the drift. The
        // first cut of this lane minted a DEFAULT@today+365 for the full 4 units
        // here, turning a self-healing case into a permanent +4 AND re-creating
        // the exact phantom `inventory:repair-phantom-default-batches` exists to
        // delete — short-dated goods re-labelled untracked so FEFO ships them last.
        $this->assertSame(
            0,
            DB::table('product_batches')->where('batch_number', 'DEFAULT')->count(),
            'a refund must never mint a DEFAULT lot',
        );
        $this->assertSame('0.0000', $this->driftFor($product->id));
    }

    // =====================================================================
    // FIX ROUND r1 — (l) the drift a shortfall leaves is DETECTABLE
    //   inventory gate F-4 · fiscal gate F-5
    // =====================================================================

    public function test_a_lot_shortfall_is_reported_by_the_drift_census(): void
    {
        $this->requiresPostgresLotDraw();

        // The inventory gate's probe P5, and the cleanest shortfall shape there
        // is: the tuple starts perfectly RECONCILED (10 aggregate, 10 in lots) and
        // its only lot is EXPIRED. FEFO's candidate predicate excludes expired
        // lots — deliberately, a parapharmacy must not ship them — so the draw
        // finds nothing while the aggregate happily decrements.
        $product = $this->seedProduct(requiresBatchTracking: true);
        $this->seedStockLevel($product->id, null, '10.0000');
        $this->seedLot($product->id, 'LOT-EXPIRED', now()->subDay()->toDateString(), '10.0000');

        $this->assertSame('0.0000', $this->driftFor($product->id), 'the fixture starts reconciled');

        $event = $this->saleEvent($product->id, '4.000');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // None of the 4 sold units could be drawn from a lot, so the ledger now
        // overstates on-hand by 4. Before this fix round that fact lived in a
        // Log::warning and nowhere else.
        $this->assertSame('4.0000', $this->driftFor($product->id));

        $census = $this->app->make(LotLedgerDriftCensus::class)->driftedTuples($this->companyId);

        $this->assertCount(1, $census);
        $this->assertSame($product->id, $census[0]['product_id']);
        $this->assertSame($this->locationId, $census[0]['location_id']);
        $this->assertSame('4.0000', $census[0]['drift']);
        $this->assertSame('6.0000', $census[0]['aggregate']);
        $this->assertSame('10.0000', $census[0]['lot_total']);
    }

    public function test_the_operator_command_reports_the_shortfall_drift_and_writes_nothing(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $this->seedStockLevel($product->id, null, '10.0000');
        $this->seedLot($product->id, 'LOT-EXPIRED', now()->subDay()->toDateString(), '10.0000');

        $event = $this->saleEvent($product->id, '4.000');
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $movementsBefore = DB::table('stock_movements')->count();
        $lotLegsBefore = DB::table('inventory_batch_movements')->count();

        $this->artisan('inventory:lot-drift-census', ['--tenant' => $this->tenantId])
            ->expectsOutputToContain('DRIFT 4.0000')
            ->expectsOutputToContain('an outbound act moved stock_levels, not the lot ledger')
            ->expectsOutputToContain('Tuples drifted: 1')
            ->expectsOutputToContain('Read-only census: nothing was written.')
            ->assertExitCode(0);

        // A detector that mutates is not a detector.
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertSame($lotLegsBefore, DB::table('inventory_batch_movements')->count());
        $this->assertSame('10.0000', $this->lotSum($product->id));

        // --fail-on-drift is what makes it usable as a scheduled check.
        $this->artisan('inventory:lot-drift-census', [
            '--tenant' => $this->tenantId,
            '--fail-on-drift' => true,
        ])->assertExitCode(1);
    }

    public function test_the_census_command_refuses_to_run_without_an_explicit_scope(): void
    {
        $this->artisan('inventory:lot-drift-census')
            ->expectsOutputToContain('Refusing to run without an explicit scope')
            ->assertExitCode(2);
    }

    public function test_the_census_is_silent_when_every_lot_leg_was_written(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $this->seedStockLevel($product->id, null, '10.0000');
        $this->seedLot($product->id, 'LOT-A', now()->addDays(30)->toDateString(), '10.0000');

        $event = $this->saleEvent($product->id, '4.000');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame([], $this->app->make(LotLedgerDriftCensus::class)->driftedTuples($this->companyId));
    }

    // =====================================================================
    // FIX ROUND r1 — partial refund lot order is contract, not luck
    //   inventory gate F-6
    // =====================================================================

    public function test_partial_refund_credits_the_earliest_expiry_lot_the_line_took(): void
    {
        $this->requiresPostgresLotDraw();

        $product = $this->seedProduct(requiresBatchTracking: true);
        $stock = $this->seedStockLevel($product->id, null, '13.0000');
        $early = $this->seedLot($product->id, 'LOT-EARLY', now()->addDays(30)->toDateString(), '3.0000');
        $late = $this->seedLot($product->id, 'LOT-LATE', now()->addDays(120)->toDateString(), '10.0000');

        $projector = $this->app->make(PosCoreReceiptProjection::class);

        // One line spanning two lots: 3 from LOT-EARLY, 2 from LOT-LATE.
        $sale = $this->saleEvent($product->id, '5.000', sequenceNumber: 1, receiptUuid: $this->uuid(1));
        app(CompanyContext::class)->clear();
        $projector->apply($sale);

        $this->assertSame('0.0000', $this->lotQuantity($early->id));
        $this->assertSame('8.0000', $this->lotQuantity($late->id));

        // Refund only 2 of the 5. Which lot gets them was previously decided by
        // whatever order PostgreSQL returned the allocation rows in.
        $refund = $this->refundEvent($sale, $product->id, '2.000', 'restock', sequenceNumber: 2);
        app(CompanyContext::class)->clear();
        $projector->apply($refund);

        $stock->refresh();
        $this->assertSame('10.0000', $stock->quantity);
        $this->assertSame('2.0000', $this->lotQuantity($early->id), 'the short-dated lot is restored first, so FEFO ships those units NEXT');
        $this->assertSame('8.0000', $this->lotQuantity($late->id));
        $this->assertSame('10.0000', $this->lotSum($product->id));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Rewind the lot side of a just-projected sale to the PRE-LANE historical
     * state: the aggregate moved, the lot ledger never heard about it, and no
     * allocation row was ever written.
     *
     * This is a FIXTURE, not a behaviour: it reproduces, exactly, the state the
     * 27 drifted units on the re-run tenant are in — a sale projected by the
     * code as it stood before this lane. It cannot be produced by running the
     * fixed projection, which is the whole point.
     */
    private function rewindLotSideToPreLaneState(int $batchId, string $quantity): void
    {
        DB::table('inventory_batch_movements')->delete();
        DB::table('pos_receipt_line_batch_allocations')->delete();
        DB::table('inventory_batch_stock')
            ->where('batch_id', $batchId)
            ->where('location_id', $this->locationId)
            ->update(['quantity' => $quantity]);
    }

    /**
     * Σ lots − stock_levels for the product at this test's location, at scale 4.
     * Positive means the lot ledger overstates on-hand.
     */
    private function driftFor(string $productId): string
    {
        /** @var numeric-string $aggregate */
        $aggregate = bcadd((string) DB::table('stock_levels')
            ->where('product_id', $productId)
            ->where('location_id', $this->locationId)
            ->whereNull('variant_id')
            ->value('quantity'), '0', 4); // precision-ok: 4 = canonical quantity storage scale

        return bcsub($this->lotSum($productId), $aggregate, 4); // precision-ok: 4 = canonical quantity storage scale
    }

    /**
     * The raw sealed bytes. `fiscal_events.canonical_bytes` is `bytea` on
     * PostgreSQL, which PDO hands back as a STREAM — casting the resource to a
     * string yields "Resource id #N", a fresh number on every read, so a naive
     * before/after comparison would fail even when the bytes are identical (and
     * would have PASSED vacuously if the bytes really had changed).
     */
    private function sealedBytes(string $eventId): string
    {
        $raw = DB::table('fiscal_events')->where('id', $eventId)->value('canonical_bytes');

        if (is_resource($raw)) {
            $raw = stream_get_contents($raw);
        }

        return (string) $raw;
    }

    /**
     * The FEFO OUTBOUND draw is PostgreSQL-only by construction:
     * {@see FEFOInventoryService::consumeBatchesAtomically()}
     * selects its candidate lots `FOR UPDATE OF ibs SKIP LOCKED`, which SQLite
     * cannot parse. That row-lock IS the concurrency contract (two cashiers must
     * never draw the same lot row), so it is not something to branch away for a
     * test driver. Every test that reaches a batch-tracked SALE therefore skips
     * honestly off PostgreSQL rather than pretending to cover it.
     *
     * The INBOUND arm (`restoreBatchesForReturn`) is deliberately
     * driver-agnostic (gate r4 R4-5), but it is only reachable here through a
     * sale that is not — so the refund tests skip with it.
     */
    private function requiresPostgresLotDraw(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                "FEFO lot consumption uses FOR UPDATE ... SKIP LOCKED; the {$driver} driver cannot execute it. Run this test on PostgreSQL."
            );
        }
    }

    private function uuid(int $n): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $n);
    }

    private function seedProduct(bool $requiresBatchTracking): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'requires_batch_tracking' => $requiresBatchTracking,
        ]);
    }

    private function seedStockLevel(string $productId, ?string $variantId, string $quantity): StockLevel
    {
        return StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'location_id' => $this->locationId,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function seedLot(string $productId, string $batchNumber, string $expiryDate, string $quantity): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'manufacturing_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenantId,
            'batch_id' => $batch->id,
            'location_id' => $this->locationId,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }

    /**
     * Normalised to scale 4: PostgreSQL returns `numeric` as `10.0000` while
     * SQLite returns the same stored value as `10`, so the raw column value is
     * not comparable across the two legs of this suite.
     */
    private function lotQuantity(int $batchId): string
    {
        $raw = (string) DB::table('inventory_batch_stock')
            ->where('batch_id', $batchId)
            ->where('location_id', $this->locationId)
            ->value('quantity');

        /** @var numeric-string $raw */
        return bcadd($raw, '0', 4); // precision-ok: 4 = canonical quantity storage scale
    }

    private function lotSum(string $productId): string
    {
        $sum = DB::table('inventory_batch_stock')
            ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
            ->where('product_batches.product_id', $productId)
            ->where('inventory_batch_stock.location_id', $this->locationId)
            ->sum('inventory_batch_stock.quantity');

        return bcadd((string) $sum, '0', 4); // precision-ok: 4 = canonical quantity storage scale
    }

    private function saleEvent(
        string $productId,
        string $quantity,
        int $sequenceNumber = 1,
        ?string $receiptUuid = null,
    ): FiscalEvent {
        return $this->buildEvent(
            invoiceTypeCode: 'SALE',
            eventVersion: 3,
            productId: $productId,
            quantity: $quantity,
            sequenceNumber: $sequenceNumber,
            receiptUuid: $receiptUuid ?? $this->uuid(1),
            originalLineReferences: null,
            originalReceiptReference: null,
        );
    }

    private function refundEvent(
        FiscalEvent $original,
        string $productId,
        string $quantity,
        string $disposition,
        int $sequenceNumber,
    ): FiscalEvent {
        /** @var array<string, mixed> $payload */
        $payload = $original->payload;
        /** @var string $originalUuid */
        $originalUuid = $payload['receipt_uuid'];

        return $this->buildEvent(
            invoiceTypeCode: 'REFUND',
            eventVersion: 4,
            productId: $productId,
            quantity: $quantity,
            sequenceNumber: $sequenceNumber,
            receiptUuid: $this->uuid(100 + $sequenceNumber),
            originalLineReferences: [[
                'disposition' => $disposition,
                'original_line_index' => 0,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]],
            originalReceiptReference: [
                'fiscal_event_id' => $original->id,
                'original_business_date' => $original->business_date->toDateString(),
                'original_receipt_uuid' => $originalUuid,
                'refund_reason' => 'customer return',
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>|null  $originalLineReferences
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function buildEvent(
        string $invoiceTypeCode,
        int $eventVersion,
        string $productId,
        string $quantity,
        int $sequenceNumber,
        string $receiptUuid,
        ?array $originalLineReferences,
        ?array $originalReceiptReference,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();

        $unitPrice = '10.00';
        /** @var numeric-string $quantity */
        $lineTotal = bcmul($unitPrice, $quantity, 2); // precision-ok: 2 = the fixture currency scale

        $lineItem = [
            'gtin' => null,
            'line_discount_amount' => '0.00',
            'line_discount_reason' => null,
            'line_subtotal' => $lineTotal,
            'line_vat' => '0.00',
            'name' => 'Lot Test Item',
            'non_collected_subtype' => null,
            'product_id' => $productId,
            'quantity' => $quantity,
            'sku' => 'SKU-LOT',
            'tax_category_code' => 'Z',
            'unit_price' => $unitPrice,
            'variant_id' => null,
            'variant_name' => null,
            'variant_sku' => null,
            'vat_rate' => '0.00',
        ];

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [$lineItem],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [[
                'amount' => $lineTotal,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => $receiptUuid,
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $lineTotal,
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => $lineTotal,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $lineTotal,
                'net_amount' => $lineTotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        if ($originalLineReferences !== null) {
            $payload['original_line_references'] = $originalLineReferences;
            $payload['refund_destination'] = 'cash';
            $payload['settlement_allocation'] = null;
        }

        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        $currentHash = hash('sha256', $canonicalBytes.(string) $sequenceNumber);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }
}
