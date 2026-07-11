<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the opt-in `autoAllocateBatchesFefo` flag on InitiateTransferData:
 *  - default (false) preserves the existing "require batch allocations" invariant;
 *  - true auto-allocates the source's batches earliest-expiry-first;
 *  - true with insufficient *sellable* batch stock raises InsufficientStockException.
 */
final class StockTransferAutoAllocateFefoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Location $shop;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'FEFO Auto Alloc Tenant',
            'slug' => 'fefo-auto-alloc-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'FEFO Co',
            'legal_name' => 'FEFO Co LLC',
            'tax_id' => 'TAX-FEFO',
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
            'name' => 'FEFO User',
            'email' => 'fefo@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-FEFO',
            'name' => 'FEFO Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->shop = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SH-FEFO',
            'name' => 'FEFO Shop',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD-FEFO',
            'name' => 'Batch Serum',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);
    }

    public function test_flag_false_batch_product_without_allocations_still_throws(): void
    {
        $batch = $this->createBatch('LOT-DEFAULT', now()->addMonths(6)->toDateString());
        $this->seedBatchStock($batch, '10.0000');

        // Existing behavior preserved: without the opt-in flag, a batch-tracked
        // line that carries no explicit allocations is rejected as before.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch-tracked products require batch allocations.');

        $this->service()->initiate($this->data([
            new InitiateTransferLineData($this->product->id, '4.0000'),
        ], autoAllocate: false));
    }

    public function test_flag_true_auto_allocates_fefo_across_batches(): void
    {
        $early = $this->createBatch('LOT-EARLY', now()->addMonths(2)->toDateString());
        $late = $this->createBatch('LOT-LATE', now()->addMonths(9)->toDateString());
        $this->seedBatchStock($early, '3.0000');
        $this->seedBatchStock($late, '5.0000');

        $transfer = $this->service()->initiate($this->data([
            new InitiateTransferLineData($this->product->id, '4.0000'),
        ], autoAllocate: true));

        $this->assertSame(TransferStatus::InTransit, $transfer->status);

        $line = $transfer->lines->first();
        $this->assertNotNull($line);

        $allocations = $line->batchAllocations
            ->load('batch')
            ->sortBy(fn ($a) => (string) $a->batch->expiry_date)
            ->values();

        $this->assertCount(2, $allocations);
        $this->assertSame((int) $early->id, $allocations[0]->batch_id);
        $this->assertSame('3.0000', (string) $allocations[0]->quantity);
        $this->assertSame((int) $late->id, $allocations[1]->batch_id);
        $this->assertSame('1.0000', (string) $allocations[1]->quantity);
    }

    public function test_flag_true_raises_insufficient_stock_when_sellable_batches_short(): void
    {
        $sellable = $this->createBatch('LOT-SELLABLE', now()->addMonths(2)->toDateString());
        $recalled = $this->createBatch('LOT-RECALLED', now()->addMonths(9)->toDateString());
        $this->seedBatchStock($sellable, '2.0000');
        $this->seedBatchStock($recalled, '5.0000');
        $recalled->recall('supplier recall');

        $this->expectException(InsufficientStockException::class);

        $this->service()->initiate($this->data([
            new InitiateTransferLineData($this->product->id, '4.0000'),
        ], autoAllocate: true));
    }

    private function service(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    /**
     * @param  list<InitiateTransferLineData>  $lines
     */
    private function data(array $lines, bool $autoAllocate): InitiateTransferData
    {
        return new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->warehouse->id,
            destinationLocationId: $this->shop->id,
            initiatedByUserId: $this->user->id,
            lines: $lines,
            autoAllocateBatchesFefo: $autoAllocate,
        );
    }

    private function createBatch(string $batchNumber, string $expiryDate): Batch
    {
        /** @var Batch $batch */
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => $batchNumber,
            'manufacturing_date' => now()->subMonth()->toDateString(),
            'expiry_date' => $expiryDate,
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        return $batch;
    }

    private function seedBatchStock(Batch $batch, string $quantity): void
    {
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->warehouse->id,
            quantity: $quantity,
            reference: 'BATCH-SEED',
            userId: $this->user->id,
            batchId: (int) $batch->id,
            expectedCompanyId: $this->company->id,
        );
    }
}
