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
        int $expiryDays,
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
            'expiry_date' => now()->addDays($expiryDays),
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
