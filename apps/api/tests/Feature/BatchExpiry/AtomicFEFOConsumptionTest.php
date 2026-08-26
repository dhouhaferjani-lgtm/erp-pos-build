<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2.1.16b — FEFOInventoryService::consumeBatchesAtomically.
 *
 * The atomic FEFO consumption primitive closes a real concurrency hole: the
 * read-only suggestBatchesForSale gives two cashiers identical suggestions and
 * a sale could shortfall yet still commit. consumeBatchesAtomically locks the
 * candidate batch_stock rows (FOR UPDATE ... SKIP LOCKED) and either fully
 * consumes (strict) or rolls the whole pass back.
 */
class AtomicFEFOConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private FEFOInventoryService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // DRIVER-AWARE (inherited red, 8-error BatchExpiry cluster). Every test
        // in this class exercises
        // `FEFOInventoryService::consumeBatchesAtomically()`, whose candidate
        // SELECT is hand-written PostgreSQL (`FOR UPDATE OF ibs SKIP LOCKED`) —
        // SQLite has no row-lock syntax at all and dies with
        // `near "FOR": syntax error`, so these six cases were pure noise on the
        // SQLite leg and proved nothing. The class is green on PostgreSQL and
        // is listed in the `backend-test-pgsql` CI filter, which is where the
        // primitive it covers actually runs.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'consumeBatchesAtomically() issues PostgreSQL-only `FOR UPDATE ... SKIP LOCKED` SQL.'
            );
        }

        $this->service = app(FEFOInventoryService::class);
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_atomic_consume_returns_locked_batches(): void
    {
        $batch = $this->createBatchWithStock(variantId: null, expiryDays: 30, quantity: 5);
        $movementId = $this->createMovement();

        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '3',
            movementId: $movementId,
        );

        $this->assertSame('0.0000', $result->shortfall);
        $this->assertFalse($result->hasShortfall());
        $this->assertCount(1, $result->consumed);
        $this->assertSame((int) $batch->id, $result->consumed[0]->batchId);
        // The result DTO reports the POSITIVE magnitude consumed.
        $this->assertSame('3.0000', $result->consumed[0]->quantityConsumed);

        $stock = BatchStock::where('batch_id', $batch->id)->first();
        $this->assertNotNull($stock);
        $this->assertSame('2.0000', (string) $stock->quantity);

        // Exactly one batch movement row was written.
        $this->assertSame(1, DB::table('inventory_batch_movements')
            ->where('movement_id', $movementId)->count());

        // Signed-ledger convention: a consume is an issue, so the ledger ROW stores
        // the NEGATIVE magnitude (matching BatchStockService::issueBatchStock()).
        $movementRow = DB::table('inventory_batch_movements')
            ->where('movement_id', $movementId)->first();
        $this->assertNotNull($movementRow);
        $this->assertSame('-3.0000', (string) $movementRow->quantity);
    }

    public function test_strict_fulfillment_throws_on_shortfall(): void
    {
        $batch = $this->createBatchWithStock(variantId: null, expiryDays: 30, quantity: 1);
        $movementId = $this->createMovement();

        try {
            $this->service->consumeBatchesAtomically(
                tenantId: $this->tenant->id,
                productId: $this->product->id,
                locationId: $this->location->id,
                quantity: '5',
                movementId: $movementId,
                strictFulfillment: true,
            );
            $this->fail('Expected InsufficientBatchStockException was not thrown.');
        } catch (InsufficientBatchStockException $e) {
            $this->assertSame('4.0000', $e->shortfall);
        }

        // Transaction rolled back: stock untouched, no movement rows written.
        $stock = BatchStock::where('batch_id', $batch->id)->first();
        $this->assertNotNull($stock);
        $this->assertSame('1.0000', (string) $stock->quantity);
        $this->assertSame(0, DB::table('inventory_batch_movements')
            ->where('movement_id', $movementId)->count());
    }

    public function test_non_strict_returns_shortfall_without_throwing(): void
    {
        $batch = $this->createBatchWithStock(variantId: null, expiryDays: 30, quantity: 1);
        $movementId = $this->createMovement();

        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5',
            movementId: $movementId,
            strictFulfillment: false,
        );

        $this->assertTrue($result->hasShortfall());
        $this->assertSame('4.0000', $result->shortfall);
        $this->assertCount(1, $result->consumed);
        $this->assertSame('1.0000', $result->consumed[0]->quantityConsumed);

        $stock = BatchStock::where('batch_id', $batch->id)->first();
        $this->assertNotNull($stock);
        $this->assertSame('0.0000', (string) $stock->quantity);
        $this->assertSame(1, DB::table('inventory_batch_movements')
            ->where('movement_id', $movementId)->count());
    }

    public function test_variant_scoped_consume_only_touches_variant_batches(): void
    {
        $variantA = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);
        $variantB = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $batchA = $this->createBatchWithStock(variantId: $variantA->id, expiryDays: 30, quantity: 5);
        $batchB = $this->createBatchWithStock(variantId: $variantB->id, expiryDays: 10, quantity: 5);
        $movementId = $this->createMovement();

        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '3',
            movementId: $movementId,
            variantId: $variantA->id,
        );

        $this->assertCount(1, $result->consumed);
        $this->assertSame((int) $batchA->id, $result->consumed[0]->batchId);

        // variantA decremented, variantB untouched.
        $this->assertSame('2.0000', (string) BatchStock::where('batch_id', $batchA->id)->value('quantity'));
        $this->assertSame('5.0000', (string) BatchStock::where('batch_id', $batchB->id)->value('quantity'));
    }

    public function test_fefo_ordering_consumes_earliest_expiry_first(): void
    {
        $late = $this->createBatchWithStock(variantId: null, expiryDays: 30, quantity: 5);
        $early = $this->createBatchWithStock(variantId: null, expiryDays: 5, quantity: 5);
        $movementId = $this->createMovement();

        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '7',
            movementId: $movementId,
        );

        $this->assertCount(2, $result->consumed);
        // Earliest expiry consumed first and fully.
        $this->assertSame((int) $early->id, $result->consumed[0]->batchId);
        $this->assertSame('5.0000', $result->consumed[0]->quantityConsumed);
        $this->assertSame((int) $late->id, $result->consumed[1]->batchId);
        $this->assertSame('2.0000', $result->consumed[1]->quantityConsumed);
    }

    public function test_generated_sql_uses_for_update_skip_locked(): void
    {
        // Best-effort proof the lock clause is present in the issued SQL.
        // A true two-process SKIP-LOCKED test runs in the dedicated test below.
        $this->createBatchWithStock(variantId: null, expiryDays: 30, quantity: 5);
        $movementId = $this->createMovement();

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            $captured[] = $query->sql;
        });

        $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '1',
            movementId: $movementId,
        );

        $foundLockClause = false;
        foreach ($captured as $sql) {
            if (str_contains($sql, 'FOR UPDATE OF ibs SKIP LOCKED')) {
                $foundLockClause = true;
                break;
            }
        }
        $this->assertTrue($foundLockClause, 'Atomic consume SELECT must use FOR UPDATE OF ibs SKIP LOCKED.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createBatchWithStock(
        ?string $variantId,
        ?int $expiryDays,
        float $quantity,
        bool $isRecalled = false,
    ): Batch {
        static $counter = 0;
        $counter++;

        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variantId,
            'batch_number' => 'ATOMIC-'.$counter,
            // W4-1: null means the lot records NO expiry — a real shape on the
            // launch tenant, where the whole opening catalogue is undated.
            'expiry_date' => $expiryDays === null ? null : now()->addDays($expiryDays),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => $isRecalled,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
        ]);

        return $batch;
    }

    // -----------------------------------------------------------------
    // W4-1 — undated lots. This method is the SERVER-AUTHORITATIVE lot draw for
    // BOTH the POS sale (PosCoreReceiptProjection) and the delivery note, and the
    // lane rewrote both its WHERE predicate and its ORDER BY. On day one of the
    // launch tenant EVERY lot is undated, so if either were wrong the result is
    // not a mis-sort — it is a strict-fulfilment REFUSAL on every batch-tracked
    // sale and delivery. Nothing in the suite exercised a NULL expiry here.
    // -----------------------------------------------------------------

    public function test_a_lot_with_no_expiry_is_consumable_not_invisible(): void
    {
        $undated = $this->createBatchWithStock(variantId: null, expiryDays: null, quantity: 5);
        $movementId = $this->createMovement();

        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '5',
            movementId: $movementId,
        );

        $this->assertSame('0.0000', $result->shortfall, 'an undated lot is NOT an expired lot — excluding it would '
            .'refuse every batch-tracked sale on a tenant whose whole opening catalogue is undated');
        $this->assertCount(1, $result->consumed);
        $this->assertSame((int) $undated->id, $result->consumed[0]->batchId);
        $this->assertNull($result->consumed[0]->expiryDate, 'the consumed DTO carries the absence of an expiry');

        $this->assertSame('0.0000', (string) BatchStock::where('batch_id', $undated->id)->first()?->quantity);
    }

    public function test_dated_lots_are_drawn_before_the_undated_one(): void
    {
        // Created undated FIRST so insertion order — and PostgreSQL's own default,
        // which happens to agree here — cannot be what makes this pass.
        $undated = $this->createBatchWithStock(variantId: null, expiryDays: null, quantity: 10);
        $late = $this->createBatchWithStock(variantId: null, expiryDays: 90, quantity: 10);
        $early = $this->createBatchWithStock(variantId: null, expiryDays: 10, quantity: 10);

        $movementId = $this->createMovement();

        // 25 of 30: the draw must stop INSIDE the undated lot, which is only true
        // if it is ranked last.
        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '25',
            movementId: $movementId,
        );

        $this->assertSame('0.0000', $result->shortfall);
        $this->assertSame(
            [(int) $early->id, (int) $late->id, (int) $undated->id],
            array_map(static fn ($row): int => $row->batchId, $result->consumed),
            'FEFO: earliest expiry first, undated LAST. Before W4-1 the undated lot carried a fabricated '
            .'cutover+365 date that sorted it FIRST and forced it out of the door ahead of short-dated stock.',
        );

        $this->assertSame('0.0000', (string) BatchStock::where('batch_id', $early->id)->first()?->quantity);
        $this->assertSame('0.0000', (string) BatchStock::where('batch_id', $late->id)->first()?->quantity);
        $this->assertSame('5.0000', (string) BatchStock::where('batch_id', $undated->id)->first()?->quantity,
            'the undated lot absorbs only the remainder');
    }

    public function test_an_expired_lot_stays_invisible_while_the_undated_one_does_not(): void
    {
        $expired = $this->createBatchWithStock(variantId: null, expiryDays: -5, quantity: 50);
        $undated = $this->createBatchWithStock(variantId: null, expiryDays: null, quantity: 4);

        $movementId = $this->createMovement();

        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '4',
            movementId: $movementId,
        );

        $this->assertSame(
            [(int) $undated->id],
            array_map(static fn ($row): int => $row->batchId, $result->consumed),
            'admitting NULL must not also admit a PASSED expiry — a parapharmacy must never ship expired goods',
        );
        $this->assertSame('50.0000', (string) BatchStock::where('batch_id', $expired->id)->first()?->quantity);
    }

    public function test_several_undated_lots_are_all_fully_consumable(): void
    {
        $first = $this->createBatchWithStock(variantId: null, expiryDays: null, quantity: 3);
        $second = $this->createBatchWithStock(variantId: null, expiryDays: null, quantity: 4);

        $movementId = $this->createMovement();

        $result = $this->service->consumeBatchesAtomically(
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '7',
            movementId: $movementId,
        );

        $this->assertSame('0.0000', $result->shortfall);
        $this->assertSame(
            [(int) $first->id, (int) $second->id],
            array_map(static fn ($row): int => $row->batchId, $result->consumed),
            'with no expiry to rank on the tie falls to created_at — the oldest stock goes first, deterministically',
        );
        $this->assertSame('0.0000', (string) BatchStock::where('batch_id', $first->id)->first()?->quantity);
        $this->assertSame('0.0000', (string) BatchStock::where('batch_id', $second->id)->first()?->quantity);
    }

    /**
     * Create a real stock_movements row so movement_id FK constraints hold.
     */
    private function createMovement(): string
    {
        $id = (string) Str::uuid();
        DB::table('stock_movements')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => 'sale',
            'quantity' => '0.00',
            'quantity_before' => '0.00',
            'quantity_after' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
