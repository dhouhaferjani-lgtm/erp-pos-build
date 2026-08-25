<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\ReverseWriteOffService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Loyalty\Application\Services\ProgramBootstrapService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Application\Services\CompanyTaxProvisioningService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\ParapharmacySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Campaign W2-7, sibling seams.
 *
 * The July-2 commit `9162dfdb0` ("close 4 default-batch invariant leaky seams")
 * introduced the SAME `targetQuantity: (string) $stockLevel->quantity` shape in
 * four places at once. The reservation seam is pinned by
 * {@see ImplicitReservationFefoLotTest}; the other three live here, because each
 * one re-mints a phantom `DEFAULT` lot on a product that already holds real
 * dated lots — and would therefore RE-BREAK exactly the data
 * `inventory:repair-phantom-default-batches` was written to repair.
 *
 * One test per seam. In every one the fixture is the same: real lots already
 * account for part of the aggregate quantity, and the DEFAULT lot may carry only
 * `stock_levels.quantity − Σ(real lots)`.
 *
 * Note the counting seam is NOT a fourth call site: `InventoryCountingCompleted`
 * lands on `ApplyStockAdjustmentsOnCountingCompleted`, which drives
 * `StockAdjustmentService::adjust()`. It is pinned separately anyway, because the
 * shared call site is reached through a different entry point with a different
 * delta shape, and a regression there is invisible from the adjustment tests.
 */
final class SiblingSeamsUntrackedRemainderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'W2-7 Sibling Seams Tenant',
            'slug' => 'w27-seams-'.bin2hex(random_bytes(4)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W2-7 Sibling Seams Company',
            'legal_name' => 'W2-7 Sibling Seams Company SARL',
            'tax_id' => 'W27S-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'W27S-WH',
            'name' => 'W2-7 Sibling Seams Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'W27S-CREM',
            'name' => 'Crème hydratante Bébé 200ml',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W2-7 Sibling Seams User',
            'email' => 'w27-seams-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
    }

    /** @param  numeric-string  $quantity */
    private function seedRealLot(string $quantity): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'LOT-CRÈME-2026A',
            'expiry_date' => now()->addDays(45)->toDateString(),
            'manufacturing_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.0000',
        ]);

        return $batch;
    }

    /** @param  numeric-string  $quantity */
    private function seedStockLevel(string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function defaultBatch(): ?Batch
    {
        return Batch::query()
            ->where('product_id', $this->product->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();
    }

    /** @return numeric-string */
    private function defaultLotQuantity(): string
    {
        $batch = $this->defaultBatch();

        if ($batch === null) {
            return '0.0000';
        }

        /** @var numeric-string $quantity */
        $quantity = bcadd(
            (string) BatchStock::where('batch_id', $batch->id)
                ->where('location_id', $this->location->id)
                ->value('quantity'),
            '0',
            4,
        );

        return $quantity;
    }

    /** @return numeric-string */
    private function totalBatchQuantity(): string
    {
        /** @var numeric-string $total */
        $total = bcadd(
            (string) BatchStock::query()
                ->join('product_batches', 'inventory_batch_stock.batch_id', '=', 'product_batches.id')
                ->where('product_batches.product_id', $this->product->id)
                ->where('inventory_batch_stock.location_id', $this->location->id)
                ->sum('inventory_batch_stock.quantity'),
            '0',
            4,
        );

        return $total;
    }

    // ───────────────── seam 1 — StockAdjustmentService::receive() ─────────────────

    public function test_implicit_receive_backs_only_the_untracked_remainder(): void
    {
        $this->seedRealLot('30.0000');
        $this->seedStockLevel('30.0000');

        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '4.0000',
            reference: 'W27S-RECEIVE',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $this->assertSame('34.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(
            0,
            bccomp('4.0000', $this->defaultLotQuantity(), 4),
            'DEFAULT must hold 34 − 30 = 4 (the lotless receipt), never the whole 34.',
        );
        $this->assertSame(0, bccomp('34.0000', $this->totalBatchQuantity(), 4));
    }

    // ───────────────── seam 2 — StockAdjustmentService::adjust() ─────────────────

    public function test_implicit_positive_adjustment_backs_only_the_untracked_remainder(): void
    {
        $this->seedRealLot('30.0000');
        $this->seedStockLevel('30.0000');

        app(StockAdjustmentService::class)->adjust(
            productId: $this->product->id,
            locationId: $this->location->id,
            newQuantity: '33.0000',
            reason: 'W2-7 sibling seam adjustment',
            userId: $this->user->id,
            expectedCompanyId: $this->company->id,
        );

        $this->assertSame('33.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(
            0,
            bccomp('3.0000', $this->defaultLotQuantity(), 4),
            'DEFAULT must hold 33 − 30 = 3, never the whole 33.',
        );
        $this->assertSame(0, bccomp('33.0000', $this->totalBatchQuantity(), 4));
    }

    // ─────────── seam 3 — inventory counting (via the completed listener) ───────────

    public function test_positive_counting_variance_backs_only_the_untracked_remainder(): void
    {
        $this->seedRealLot('30.0000');
        $this->seedStockLevel('30.0000');

        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'CNT-W27S',
            'status' => CountingStatus::Finalized,
            'created_by_user_id' => $this->user->id,
        ]);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '30.0000',
            'final_qty' => '32.0000',
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
        ]);

        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: 'CNT-W27S',
            itemsCount: 1,
            totalVariance: '2.0000',
            completedBy: $this->user->id,
            completedAt: now()->toIso8601String(),
        ));

        $this->assertSame('32.0000', StockLevel::where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame(
            0,
            bccomp('2.0000', $this->defaultLotQuantity(), 4),
            'DEFAULT must hold the 2-unit counting surplus, not a second copy of the 30 real units.',
        );
        $this->assertSame(0, bccomp('32.0000', $this->totalBatchQuantity(), 4));
    }

    // ───────── seam 4 — flipping requires_batch_tracking on (ProductController) ─────────

    public function test_flipping_batch_tracking_on_backfills_only_the_untracked_remainder(): void
    {
        // The flag is what the endpoint flips; real lots exist independently of it.
        $this->product->update(['requires_batch_tracking' => false]);
        $this->seedRealLot('30.0000');
        $this->seedStockLevel('33.0000');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->actingAs($this->user)
            ->withHeader('X-Company-Id', $this->company->id)
            ->patchJson("/api/v1/products/{$this->product->id}", ['requires_batch_tracking' => true])
            ->assertStatus(200);

        $this->assertTrue($this->product->refresh()->requires_batch_tracking);
        $this->assertSame(
            0,
            bccomp('3.0000', $this->defaultLotQuantity(), 4),
            'The backfill must mint 33 − 30 = 3, never a second copy of the 30 units the real lot holds.',
        );
        $this->assertSame(0, bccomp('33.0000', $this->totalBatchQuantity(), 4));
    }
    // ───────── seam 5 — the parapharmacy provisioning seeder (gate r1 finding 6) ─────────

    /**
     * Gate r1 finding 6 — the missed seam. `ParapharmacySeeder::
     * seedBatchesForBatchTrackedProducts()` passed the aggregate
     * `$stock->quantity` as the DEFAULT lot's target, and its own docblock
     * advertises the method as re-runnable ("safe to call again after more stock
     * is seeded"). Re-running it on a tenant that has since received real dated
     * lots therefore re-minted exactly the phantom this lane repairs. Not a
     * request path — it is the parapharmacy vertical's PROVISIONING path, which
     * is the vertical this campaign is onboarding.
     */
    public function test_the_parapharmacy_seeder_backfill_backs_only_the_untracked_remainder(): void
    {
        $this->seedRealLot('30.0000');
        $this->seedStockLevel('33.0000');

        // The seeder's own constructor dependencies come from the container
        // (seeders are container-resolved); only the one protected method under
        // test is exposed.
        $seeder = new class($this->app->make(ProgramBootstrapService::class), $this->app->make(CompanyTaxProvisioningService::class), $this->app->make(ChartOfAccountsService::class)) extends ParapharmacySeeder
        {
            public function reconcileDefaultBatches(Company $company): void
            {
                $this->seedBatchesForBatchTrackedProducts($company);
            }
        };

        $command = new Command;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $seeder->setContainer($this->app)->setCommand($command);
        $seeder->reconcileDefaultBatches($this->company);

        $this->assertSame(
            0,
            bccomp('3.0000', $this->defaultLotQuantity(), 4),
            'Re-running the provisioning seeder must mint 33 − 30 = 3, never a second copy of the real lot.',
        );
        $this->assertSame(0, bccomp('33.0000', $this->totalBatchQuantity(), 4));
    }
    // ───────── seam 6 — write-off reversal ordering (gate r1 finding 8) ─────────

    /**
     * Gate r1 finding 8 — `ReverseWriteOffService::reverse()` restores the
     * AGGREGATE first (`StockAdjustmentService::receive(batchId: null)`) and
     * credits the ORIGINAL real lot afterwards. At helper time the reversed units
     * are not yet inside any lot, so the untracked-remainder helper backed them
     * with a DEFAULT lot and step 3 then booked the SAME units into the real lot:
     * `Σ lots` ended up above `stock_levels.quantity` by exactly the reversed
     * quantity.
     *
     * The reviewer's probe (real lot 26, aggregate 26, reverse 4) produced
     * `Sigma lots 34 vs aggregate 30`. `receive(creditsLotItself: true)` is the fix:
     * a caller that credits the lot itself suppresses the implicit top-up.
     *
     * The pre-existing `ReverseWriteOffServiceTest` cannot catch this — its product
     * never sets `requires_batch_tracking`, so the helper returns early and the
     * seam is untested there.
     */
    public function test_reversing_a_write_off_does_not_double_book_the_reversed_units(): void
    {
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $lot = $this->seedRealLot('30.0000');
        $stockLevel = $this->seedStockLevel('30.0000');

        // Write 4 units off the real lot, exactly as the write-off path does.
        $writeOff = app(StockAdjustmentService::class)->issue(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: '4.0000',
            reference: 'W27S-WRITEOFF',
            userId: $this->user->id,
            batchId: $lot->id,
            expectedCompanyId: $this->company->id,
            reason: MovementReason::WriteOff,
        );

        $stockLevel->refresh();
        $this->assertSame(0, bccomp('26.0000', (string) $stockLevel->quantity, 4));
        $this->assertSame(0, bccomp('26.0000', $this->totalBatchQuantity(), 4));

        app(ReverseWriteOffService::class)->reverse($writeOff, $this->user->id);

        $this->assertSame(
            0,
            bccomp('30.0000', (string) StockLevel::query()->sole()->quantity, 4),
            'the aggregate is restored',
        );
        $this->assertSame(
            0,
            bccomp('30.0000', $this->totalBatchQuantity(), 4),
            'the lot ledger must match the aggregate — the reversed units belong to the ORIGINAL lot only.',
        );
        $this->assertNull(
            $this->defaultBatch(),
            'no DEFAULT lot may be minted for units the reversal credits back to a real lot.',
        );
    }
}
