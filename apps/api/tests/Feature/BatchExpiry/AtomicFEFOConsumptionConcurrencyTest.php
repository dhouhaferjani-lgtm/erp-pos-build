<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2.1.16b — true two-connection SKIP LOCKED proof.
 *
 * This test does NOT use RefreshDatabase: it commits seed data on the default
 * connection so a second, independent PostgreSQL connection can see it, then:
 *
 *   1. Connection B opens a transaction and `SELECT ... FOR UPDATE` the single
 *      batch_stock row — holding a row lock.
 *   2. The default connection calls consumeBatchesAtomically(strict=true). Its
 *      `FOR UPDATE OF ibs SKIP LOCKED` skips the locked row, finds no other
 *      stock, and (strict) throws InsufficientBatchStockException.
 *   3. Connection B rolls back; we assert the stock is untouched.
 *
 * Skipped on non-pgsql drivers (SKIP LOCKED is a PostgreSQL/MySQL feature and
 * the assertion is meaningless on sqlite).
 *
 * Seeded rows are removed in tearDown because RefreshDatabase is not in play.
 */
class AtomicFEFOConsumptionConcurrencyTest extends TestCase
{
    private const SECOND_CONNECTION = 'pgsql_concurrency_probe';

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $productId;

    private int $batchId;

    private int $batchStockId;

    private string $movementId;

    private bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SKIP LOCKED concurrency proof requires PostgreSQL.');
        }

        // A second, independent connection to the SAME test database. It does not
        // share the default connection's transaction, so it can hold a real lock.
        Config::set('database.connections.'.self::SECOND_CONNECTION, Config::get('database.connections.pgsql'));

        $this->seedCommitted();
    }

    protected function tearDown(): void
    {
        if ($this->seeded) {
            DB::table('inventory_batch_movements')->where('movement_id', $this->movementId)->delete();
            DB::table('inventory_batch_stock')->where('id', $this->batchStockId)->delete();
            DB::table('product_batches')->where('id', $this->batchId)->delete();
            DB::table('stock_movements')->where('id', $this->movementId)->delete();
            DB::table('products')->where('id', $this->productId)->delete();
            DB::table('locations')->where('id', $this->locationId)->delete();
            DB::table('companies')->where('id', $this->companyId)->delete();
            DB::table('tenants')->where('id', $this->tenantId)->delete();
        }

        DB::purge(self::SECOND_CONNECTION);

        parent::tearDown();
    }

    public function test_skip_locked_skips_a_row_locked_by_another_connection(): void
    {
        /** @var FEFOInventoryService $service */
        $service = app(FEFOInventoryService::class);

        // Connection B: open a transaction and lock the single batch_stock row.
        $probe = DB::connection(self::SECOND_CONNECTION);
        $probe->beginTransaction();
        $locked = $probe->select(
            'SELECT id FROM inventory_batch_stock WHERE id = ? FOR UPDATE',
            [$this->batchStockId],
        );
        $this->assertCount(1, $locked, 'Probe connection failed to lock the batch_stock row.');

        try {
            // Default connection: strict consume must SKIP the locked row, find no
            // other stock, and throw (rather than block forever or shortfall-commit).
            $threw = false;
            try {
                $service->consumeBatchesAtomically(
                    tenantId: $this->tenantId,
                    productId: $this->productId,
                    locationId: $this->locationId,
                    quantity: '1',
                    movementId: $this->movementId,
                    strictFulfillment: true,
                );
            } catch (InsufficientBatchStockException $e) {
                $threw = true;
                $this->assertSame('1.0000', $e->shortfall);
            }

            $this->assertTrue(
                $threw,
                'Strict consume should have thrown InsufficientBatchStockException because the only batch_stock row was locked and SKIP LOCKED skipped it.',
            );
        } finally {
            // Release the lock no matter what.
            $probe->rollBack();
        }

        // The locked row was never touched (consume rolled back; probe rolled back).
        $this->assertSame(
            '5.0000',
            (string) DB::table('inventory_batch_stock')->where('id', $this->batchStockId)->value('quantity'),
        );
        $this->assertSame(
            0,
            DB::table('inventory_batch_movements')->where('movement_id', $this->movementId)->count(),
        );
    }

    /**
     * Seed (and COMMIT) the minimal graph needed for the proof. Because we are
     * not inside RefreshDatabase's wrapping transaction, these rows are durable
     * and visible to the second connection — and must be cleaned up in tearDown.
     *
     * Factories handle the many NOT NULL company/location/product columns; we
     * record the resulting ids so tearDown can delete them precisely.
     */
    private function seedCommitted(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->tenantId = (string) $tenant->id;
        $this->companyId = (string) $company->id;
        $this->locationId = (string) $location->id;
        $this->productId = (string) $product->id;
        $this->movementId = (string) Str::uuid();
        $now = now();

        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $this->productId,
            'variant_id' => null,
            'batch_number' => 'PROBE-'.Str::random(6),
            'expiry_date' => $now->copy()->addDays(30),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);
        $this->batchId = (int) $batch->id;

        $batchStock = BatchStock::create([
            'tenant_id' => $this->tenantId,
            'batch_id' => $this->batchId,
            'location_id' => $this->locationId,
            'quantity' => '5.0000',
            'reserved_quantity' => '0.0000',
        ]);
        $this->batchStockId = (int) $batchStock->id;

        DB::table('stock_movements')->insert([
            'id' => $this->movementId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'movement_type' => 'sale',
            'quantity' => '0.00',
            'quantity_before' => '0.00',
            'quantity_after' => '0.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->seeded = true;
    }
}
