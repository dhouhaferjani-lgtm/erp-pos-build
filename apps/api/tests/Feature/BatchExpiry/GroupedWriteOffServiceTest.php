<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffData;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffLine;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffMovementResult;
use App\Modules\BatchExpiry\Application\DTOs\GroupedWriteOffResult;
use App\Modules\BatchExpiry\Application\Services\GroupedWriteOffService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Entities\GroupedWriteOff;
use App\Modules\BatchExpiry\Domain\Exceptions\InsufficientBatchStockException;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * B3a — GroupedWriteOffService: atomic, all-or-nothing, idempotent multi-lot
 * write-off with a canonical cross-domain lock order.
 *
 * sqlite caveat (documented in the task report): sqlite :memory: does NOT exhibit
 * real row-lock contention, so these tests cannot deterministically interleave two
 * live transactions. They instead prove:
 *   - the canonical lock-ACQUISITION ORDER deterministically (planLockOrder), and
 *   - the logical no-oversell / all-or-nothing / idempotency invariants.
 * True row-lock deadlock-freedom is a PostgreSQL property; see the report.
 */
final class GroupedWriteOffServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $productA;

    private Product $productB;

    private Batch $batchA;

    private Batch $batchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Grouped WriteOff Tenant',
            'slug' => 'grouped-wo-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Grouped WriteOff Company',
            'legal_name' => 'Grouped WriteOff Company LLC',
            'tax_id' => 'GWO-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Grouped WriteOff User',
            'email' => 'grouped-wo-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-GWO-01',
            'name' => 'Grouped WriteOff Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->productA = $this->seedProduct('GWO-PROD-A');
        $this->productB = $this->seedProduct('GWO-PROD-B');

        $this->batchA = $this->seedBatchWithStock($this->productA, 'BATCH-A', '10.0000');
        $this->batchB = $this->seedBatchWithStock($this->productB, 'BATCH-B', '10.0000');

        // GL accounts so the per-line write-off can post its journal entry cleanly.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '601',
            'name' => 'Cost of Goods Sold',
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

    private function service(): GroupedWriteOffService
    {
        return app(GroupedWriteOffService::class);
    }

    private function seedProduct(string $sku): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '2.000',
        ]);
    }

    private function seedBatchWithStock(Product $product, string $batchNumber, string $quantity): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->addYear(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }

    private function batchStockQuantity(Batch $batch): string
    {
        return (string) BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->value('quantity');
    }

    private function stockLevelQuantity(Product $product): string
    {
        return (string) StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->whereNull('variant_id')
            ->value('quantity');
    }

    /**
     * GATE: canonical lock-acquisition order is deterministic regardless of the
     * order lines are supplied in. stock_levels (product_ids) ascending FIRST,
     * then inventory_batch_stock (batch_ids) ascending.
     */
    public function test_lock_acquisition_order_is_canonical(): void
    {
        // Supply lines in the REVERSE of canonical (batch_id) order on purpose.
        $data = new GroupedWriteOffData(
            locationId: $this->warehouse->id,
            lines: [
                new GroupedWriteOffLine(batchId: (int) $this->batchB->id, quantity: '1.0000'),
                new GroupedWriteOffLine(batchId: (int) $this->batchA->id, quantity: '1.0000'),
            ],
            reason: MovementReason::Expiry,
            idempotencyKey: 'order-key',
        );

        /** @var Collection<int, Batch> $batches */
        $batches = collect([
            (int) $this->batchA->id => $this->batchA,
            (int) $this->batchB->id => $this->batchB,
        ]);

        $plan = $this->service()->planLockOrder($data, $batches);

        // batch_stock: batch ids ascending (B1 < B2 by auto-increment).
        $this->assertSame(
            [(int) $this->batchA->id, (int) $this->batchB->id],
            $plan['batch_stock'],
            'inventory_batch_stock must be locked in ascending batch_id order.',
        );

        // stock_levels: product ids ascending (UUID string sort), deduped.
        $expectedProducts = collect([(string) $this->productA->id, (string) $this->productB->id])
            ->sort()
            ->values()
            ->all();
        $this->assertSame(
            $expectedProducts,
            $plan['stock_levels'],
            'stock_levels must be locked in ascending (product_id) order.',
        );
    }

    /**
     * GATE: a second grouped write-off whose combined demand exceeds the
     * availability LEFT by a prior group rolls back wholly; the first remains
     * intact and no stock goes negative.
     *
     * NOTE: this is a SEQUENTIAL (single-connection) test — it proves the logical
     * no-oversell invariant only. It does NOT prove concurrency: it cannot
     * interleave two live transactions, so it cannot demonstrate that genuinely
     * concurrent groups locking these rows in opposite order neither deadlock nor
     * oversell. That requires a Postgres two-connection deadlock/oversell proof
     * (a known CI gap; see the deadlock-freedom scope note in the task report and
     * AtomicFEFOConsumptionConcurrencyTest for the established pattern).
     */
    public function test_second_group_fails_when_availability_depleted(): void
    {
        $service = $this->service();

        // Group A: write off 6 from each lot. B1=4, B2=4 remain.
        $service->writeOffGroup(
            new GroupedWriteOffData(
                locationId: $this->warehouse->id,
                lines: [
                    new GroupedWriteOffLine(batchId: (int) $this->batchA->id, quantity: '6.0000'),
                    new GroupedWriteOffLine(batchId: (int) $this->batchB->id, quantity: '6.0000'),
                ],
                reason: MovementReason::Expiry,
                idempotencyKey: 'group-a',
            ),
            $this->user->id,
        );

        $this->assertSame('4.0000', $this->batchStockQuantity($this->batchA));
        $this->assertSame('4.0000', $this->batchStockQuantity($this->batchB));

        // Group B: OPPOSITE line order, 6 from each — but only 4 remain in each.
        // The whole group must roll back (no oversell, no negative stock).
        $threw = false;
        try {
            $service->writeOffGroup(
                new GroupedWriteOffData(
                    locationId: $this->warehouse->id,
                    lines: [
                        new GroupedWriteOffLine(batchId: (int) $this->batchB->id, quantity: '6.0000'),
                        new GroupedWriteOffLine(batchId: (int) $this->batchA->id, quantity: '6.0000'),
                    ],
                    reason: MovementReason::Expiry,
                    idempotencyKey: 'group-b',
                ),
                $this->user->id,
            );
        } catch (InsufficientBatchStockException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Group B should have thrown — combined demand exceeds availability.');

        // Group A intact; Group B fully rolled back; nothing negative.
        $this->assertSame('4.0000', $this->batchStockQuantity($this->batchA));
        $this->assertSame('4.0000', $this->batchStockQuantity($this->batchB));
        $this->assertDatabaseMissing('grouped_write_offs', ['idempotency_key' => 'group-b']);
    }

    /**
     * GATE: replaying the SAME idempotency_key applies the stock change ONCE and
     * returns the SAME result the second time (no second decrement).
     */
    public function test_idempotent_replay_applies_once(): void
    {
        $service = $this->service();

        $data = new GroupedWriteOffData(
            locationId: $this->warehouse->id,
            lines: [new GroupedWriteOffLine(batchId: (int) $this->batchA->id, quantity: '3.0000')],
            reason: MovementReason::Expiry,
            idempotencyKey: 'replay-key',
        );

        $first = $service->writeOffGroup($data, $this->user->id);
        $this->assertFalse($first->replayed);
        $this->assertSame('7.0000', $this->batchStockQuantity($this->batchA));

        $second = $service->writeOffGroup($data, $this->user->id);
        $this->assertTrue($second->replayed, 'Second call with same key must be a replay.');

        // Stock unchanged by the replay.
        $this->assertSame('7.0000', $this->batchStockQuantity($this->batchA));

        // Same movement id returned both times; exactly one movement created.
        $this->assertSame(
            $first->movements[0]->movementId,
            $second->movements[0]->movementId,
            'Replay must return the same movement id.',
        );
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('product_id', $this->productA->id)
                ->where('reason', MovementReason::Expiry->value)
                ->count(),
            'Replay must not create a second movement.',
        );
        $this->assertSame(1, \DB::table('grouped_write_offs')->where('idempotency_key', 'replay-key')->count());
    }

    /**
     * GATE: a group where ONE lot is short rolls back ALL lots — no movement and no
     * stock change for any line.
     */
    public function test_all_or_nothing_one_short_rolls_back_all(): void
    {
        $service = $this->service();

        $threw = false;
        try {
            $service->writeOffGroup(
                new GroupedWriteOffData(
                    locationId: $this->warehouse->id,
                    lines: [
                        new GroupedWriteOffLine(batchId: (int) $this->batchA->id, quantity: '5.0000'),
                        new GroupedWriteOffLine(batchId: (int) $this->batchB->id, quantity: '9999.0000'),
                    ],
                    reason: MovementReason::Expiry,
                    idempotencyKey: 'short-key',
                ),
                $this->user->id,
            );
        } catch (InsufficientBatchStockException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A short lot must throw.');

        // Neither lot changed; no movements; no idempotency record.
        $this->assertSame('10.0000', $this->batchStockQuantity($this->batchA));
        $this->assertSame('10.0000', $this->batchStockQuantity($this->batchB));

        // AGGREGATE stock_levels unchanged too — the rolled-back group must not have
        // decremented the product-level aggregate for ANY line (issue() runs before
        // the short lot throws inside the same transaction).
        $this->assertSame('10.0000', $this->stockLevelQuantity($this->productA));
        $this->assertSame('10.0000', $this->stockLevelQuantity($this->productB));

        $this->assertSame(
            0,
            StockMovement::query()->where('reason', MovementReason::Expiry->value)->count(),
            'No movement may be created when any lot is short.',
        );
        $this->assertDatabaseMissing('grouped_write_offs', ['idempotency_key' => 'short-key']);
    }

    /**
     * GATE: every resulting stock_movements row carries the correct reason and the
     * resolved unit_cost/total_cost (per A1/B2).
     */
    public function test_reason_and_cost_on_every_movement(): void
    {
        $service = $this->service();

        $result = $service->writeOffGroup(
            new GroupedWriteOffData(
                locationId: $this->warehouse->id,
                lines: [
                    new GroupedWriteOffLine(batchId: (int) $this->batchA->id, quantity: '2.0000'),
                    new GroupedWriteOffLine(batchId: (int) $this->batchB->id, quantity: '3.0000'),
                ],
                reason: MovementReason::Damage,
                idempotencyKey: 'cost-key',
            ),
            $this->user->id,
        );

        $this->assertCount(2, $result->movements);

        foreach ($result->movements as $movementResult) {
            $movement = StockMovement::query()->findOrFail($movementResult->movementId);

            // reason persisted as Damage on every line.
            $this->assertSame(
                MovementReason::Damage,
                $movement->reason,
                'Every grouped write-off movement must carry the Damage reason.',
            );

            // unit_cost = product cost_price at COST_SCALE=6.
            $this->assertSame('2.000000', (string) $movement->unit_cost);

            // total_cost = unit_cost × quantity at COST_SCALE=6.
            $expectedTotal = bcmul('2.000000', $movementResult->quantity, 6);
            $this->assertSame($expectedTotal, (string) $movement->total_cost);

            // Result DTO mirrors the persisted row. The DTO cost strings are read
            // via getRawOriginal() (bypassing the decimal:6 cast), so this asserts
            // the persisted cost retains FULL scale ("2.000000"), not a float-shed
            // "2"/"2.0" — guarding the precision contract.
            $this->assertSame('2.000000', $movementResult->unitCost);
            $this->assertSame($expectedTotal, $movementResult->totalCost);
        }
    }

    /**
     * GATE (IMPORTANT A — idempotency TOCTOU): when a grouped_write_offs record
     * already exists for the key, the service REPLAYS the stored result and mutates
     * NO stock — never throwing a spurious InsufficientBatchStockException.
     *
     * This is the logic the after-locks double-check relies on: a concurrent winner
     * O commits its record; the duplicate request re-reads it via findExisting() and
     * replays via GroupedWriteOffResult::fromArray() WITHOUT touching stock. Here we
     * stand in for O by pre-inserting a committed record.
     *
     * sqlite caveat: a single in-memory connection CANNOT reproduce the real
     * blocking interleave (duplicate blocks in acquireLocks() until O commits, then
     * re-reads the now-visible record UNDER the locks). It proves only the shared
     * replay logic — findExisting() -> fromArray() with no mutation. The true
     * two-connection blocking proof requires Postgres (see the task report).
     */
    public function test_replay_returns_stored_result_without_mutating_stock(): void
    {
        // Stand in for a concurrent winner O that already applied + COMMITTED.
        $storedResult = new GroupedWriteOffResult(
            idempotencyKey: 'double-check-key',
            replayed: false,
            movements: [
                new GroupedWriteOffMovementResult(
                    batchId: (int) $this->batchA->id,
                    movementId: (string) Str::uuid(),
                    quantity: '5.0000',
                    unitCost: '2.000000',
                    totalCost: '10.000000',
                ),
            ],
        );

        GroupedWriteOff::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->warehouse->id,
            'idempotency_key' => 'double-check-key',
            'reason' => MovementReason::Expiry->value,
            'result' => $storedResult->toArray(),
        ]);

        $stockBefore = $this->batchStockQuantity($this->batchA);
        $movementsBefore = StockMovement::query()->count();

        $result = $this->service()->writeOffGroup(
            new GroupedWriteOffData(
                locationId: $this->warehouse->id,
                lines: [new GroupedWriteOffLine(batchId: (int) $this->batchA->id, quantity: '5.0000')],
                reason: MovementReason::Expiry,
                idempotencyKey: 'double-check-key',
            ),
            $this->user->id,
        );

        // Replayed from the stored record: flagged, same movement id, NO mutation.
        $this->assertTrue($result->replayed, 'An existing record must be replayed, not re-applied.');
        $this->assertSame(
            $storedResult->movements[0]->movementId,
            $result->movements[0]->movementId,
            'Replay must return the stored movement id.',
        );
        $this->assertSame($stockBefore, $this->batchStockQuantity($this->batchA), 'Replay must not touch stock.');
        $this->assertSame($movementsBefore, StockMovement::query()->count(), 'Replay must not create a movement.');
        $this->assertSame(
            1,
            \DB::table('grouped_write_offs')->where('idempotency_key', 'double-check-key')->count(),
            'Replay must not insert a second ledger row.',
        );
    }
}
