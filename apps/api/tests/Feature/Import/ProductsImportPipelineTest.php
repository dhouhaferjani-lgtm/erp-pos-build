<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ProductsImportPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Products Pipeline Tenant',
            'slug' => 'products-pipeline-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Products Pipeline Company',
            'legal_name' => 'Products Pipeline Company LLC',
            'tax_id' => 'TAX-PROD-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Import User',
            'email' => 'products-import@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3100',
            'name' => 'Inventory Asset',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Storage::fake('local');
    }

    public function test_products_import_resolves_prices_and_posts_opening_stock_with_warnings(): void
    {
        $duplicate = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Duplicate Opening Product',
            'sku' => 'DUP-OPEN',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
        ]);

        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $duplicate->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Opening,
            'reason' => MovementReason::OpeningBalance,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
            'unit_cost' => '2.000000',
            'total_cost' => '2.000000',
            'reference' => 'PRE-OPENING',
            'user_id' => $this->user->id,
            'is_historical' => true,
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', implode("\n", [
            'name,sku,type,sale_price_incl_tax,sale_price_excl_tax,margin,quantity,location_code,purchase_price,tax_rate',
            'Happy Product,HAPPY,part,7.140,,,5.0000,MAIN,3.000,',
            'No Cost Product,NOCOST,part,,,,2.0000,MAIN,,',
            'Service Product,SERV,service,,,,4.0000,MAIN,1.000,',
            'Duplicate Opening Product,DUP-OPEN,part,,,,3.0000,MAIN,2.000,',
            'HT Product,HTPRICE,part,,10.000,,0,MAIN,,',
            'Margin Conflict Product,MARGIN,part,20.000,,30,0,MAIN,10.000,',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
                'options' => [
                    'price_authority' => 'ht',
                    'location_code' => 'MAIN',
                ],
            ]);

        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $executeResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute");

        $executeResponse->assertOk();
        $executeResponse->assertJsonPath('data.warning_rows', 4);

        $happy = Product::where('sku', 'HAPPY')->firstOrFail();
        $this->assertSame('7.140', $happy->sale_price);
        $this->assertSame('3.000000', $happy->refresh()->cost_price);
        $this->assertSame(1, StockMovement::where('product_id', $happy->id)->where('movement_type', MovementType::Opening)->count());
        $this->assertSame('5.0000', StockLevel::where('product_id', $happy->id)->firstOrFail()->quantity);

        $htProduct = Product::where('sku', 'HTPRICE')->firstOrFail();
        $this->assertSame('11.900', $htProduct->sale_price);

        $job = ImportJob::findOrFail($jobId);
        $this->assertInstanceOf(ImportJob::class, $job);
        $rows = $job->rows()->orderBy('row_number')->get()->keyBy('row_number');

        $this->assertSame('ok', $rows[1]->data['_results']['opening_stock'] ?? null);
        $this->assertSame('default', $rows[5]->data['_results']['tax_source'] ?? null);
        $this->assertSame('qty_without_cost', $rows[2]->warnings[0]['code'] ?? null);
        $this->assertSame('quantity_ignored_service', $rows[3]->warnings[0]['code'] ?? null);
        $this->assertSame('opening_exists', $rows[4]->warnings[0]['code'] ?? null);
        $this->assertSame('price_conflict', $rows[6]->warnings[0]['code'] ?? null);
        $this->assertSame(1, StockMovement::where('product_id', $duplicate->id)->where('movement_type', MovementType::Opening)->count());
    }

    public function test_batch_tracked_product_quantity_creates_opening_movement_with_default_lot(): void
    {
        // Parapharmacy verticals default requires_batch_tracking=true for every
        // product — opening stock must still import (the posting service backs
        // it with a DEFAULT lot), otherwise quantity import is a no-op for the
        // whole vertical.
        Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Lot Tracked Product',
            'sku' => 'LOT-1',
            'type' => ProductType::Part,
            'tax_rate' => '19.00',
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 365,
        ]);

        $file = UploadedFile::fake()->createWithContent('products-lot.csv', implode("\n", [
            'name,sku,type,quantity,location_code,purchase_price',
            'Lot Tracked Product,LOT-1,part,6.0000,MAIN,2.500',
        ]));

        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'products',
            ]);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.warning_rows', 0);

        $product = Product::where('sku', 'LOT-1')->firstOrFail();
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('movement_type', MovementType::Opening)->count());
        $this->assertSame('6.0000', StockLevel::where('product_id', $product->id)->firstOrFail()->quantity);
        $this->assertSame(
            1,
            Batch::where('product_id', $product->id)->count(),
            'batch-tracked opening stock must be backed by a default lot'
        );

        $row = ImportJob::findOrFail($jobId)->rows()->firstOrFail();
        $this->assertSame('ok', $row->data['_results']['opening_stock'] ?? null);
        $this->assertNull($row->warnings);
    }
}
