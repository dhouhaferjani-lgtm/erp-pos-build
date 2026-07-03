<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StockReservationDefaultBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_without_explicit_batch_on_batch_tracked_product_uses_default_lot(): void
    {
        $tenant = Tenant::create([
            'name' => 'Default Batch Reservation Tenant',
            'slug' => 'default-batch-reservation',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Default Batch Reservation Company',
            'legal_name' => 'Default Batch Reservation Company LLC',
            'tax_id' => 'DBR-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $location = Location::create([
            'company_id' => $company->id,
            'code' => 'WH-DBR',
            'name' => 'Default Batch Reservation Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'sku' => 'DBR-001',
            'name' => 'Default Batch Reservation Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 60,
        ]);
        StockLevel::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '9.0000',
            'reserved' => '0.0000',
        ]);

        $reservation = app(StockReservationService::class)->reserve(
            company: $company,
            productId: $product->id,
            locationId: $location->id,
            quantity: '4.0000',
            sourceType: ReservationSource::ManualHold,
            sourceId: 'manual-default-batch',
        );

        $batch = Batch::where('product_id', $product->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->first();

        $this->assertNotNull($batch, 'implicit reservation must mint or reuse the DEFAULT lot');
        if ($batch === null) {
            return;
        }

        $batchStock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $location->id)
            ->firstOrFail();

        $this->assertSame($batch->id, $reservation->batch_id);
        $this->assertSame(0, bccomp('9.0000', (string) $batchStock->quantity, 4));
        $this->assertSame(0, bccomp('4.0000', (string) $batchStock->reserved_quantity, 4));
        $this->assertSame('0.0000', StockLevel::where('product_id', $product->id)->value('reserved'));
    }
}
