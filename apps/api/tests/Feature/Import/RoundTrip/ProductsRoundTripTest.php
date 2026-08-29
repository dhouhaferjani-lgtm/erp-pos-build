<?php

declare(strict_types=1);

namespace Tests\Feature\Import\RoundTrip;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ProductsRoundTripTest extends TestCase
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
            'name' => 'Products Round Trip Tenant',
            'slug' => 'products-round-trip-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Products Round Trip Company',
            'legal_name' => 'Products Round Trip Company LLC',
            'tax_id' => 'TAX-PRODUCTS-ROUND-TRIP',
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
            'name' => 'Products Import Admin',
            'email' => 'products-round-trip@example.com',
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
        app(UnitsProvisioningService::class)->provisionForCompany($this->company);
        Storage::fake('local');
    }

    /**
     * @param  list<string>  $lines
     */
    #[DataProvider('numberConventionProvider')]
    public function test_products_round_trip_preserves_eu_and_us_numbers(array $lines, string $sku, string $name): void
    {
        $this->runImport($lines, ImportType::Products->value);

        $product = Product::query()->where('company_id', $this->company->id)->where('sku', $sku)->firstOrFail();
        $this->assertSame($name, $product->name);
        $this->assertSame(ProductType::Part, $product->type);
        $this->assertNotNull($product->sale_price);
        $this->assertSame(0, bccomp($this->numericString($product->sale_price), '12.500', 3));
        $this->assertSame(0, bccomp($this->numericString($product->cost_price), '7.250', 3));

        $stockLevel = StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp($stockLevel->quantity, '3.0000', 4));

        $movements = StockMovement::query()->where('product_id', $product->id)->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame(MovementType::Opening, $movement->movement_type);
        $this->assertSame(0, bccomp($movement->quantity, '3.0000', 4));
    }

    public function test_products_placement_sub_path_auto_creates_nodes_and_places_the_product(): void
    {
        $this->runImport(
            [
                'name,sku,type,location_code,placement_path',
                'Placed Product,PLACED-1,part,MAIN,A1 > R2 > B7',
            ],
            ImportType::Products->value,
            [
                'placement_mode' => 'auto_create',
                'placement_node_types' => [
                    LocationNodeType::Aisle->value,
                    LocationNodeType::Rack->value,
                    LocationNodeType::Bin->value,
                ],
            ],
        );

        $product = Product::query()->where('company_id', $this->company->id)->where('sku', 'PLACED-1')->firstOrFail();
        $aisle = LocationNode::query()->where('location_id', $this->location->id)->where('path', 'A1')->firstOrFail();
        $rack = LocationNode::query()->where('location_id', $this->location->id)->where('path', 'A1/R2')->firstOrFail();
        $bin = LocationNode::query()->where('location_id', $this->location->id)->where('path', 'A1/R2/B7')->firstOrFail();

        $this->assertSame(LocationNodeType::Aisle, $aisle->node_type);
        $this->assertSame(LocationNodeType::Rack, $rack->node_type);
        $this->assertSame($aisle->id, $rack->parent_id);
        $this->assertSame(LocationNodeType::Bin, $bin->node_type);
        $this->assertSame($rack->id, $bin->parent_id);
        $this->assertDatabaseHas('product_placements', [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'node_id' => $bin->id,
            'deleted_at' => null,
        ]);
        $this->assertSame(1, ProductPlacement::query()->where('product_id', $product->id)->count());
    }

    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function numberConventionProvider(): array
    {
        return [
            'European semicolon and decimal comma' => [
                [
                    'name;sku;type;sale_price_excl_tax;quantity;location_code;purchase_price;tax_rate',
                    'Produit EU;EU-1;part;12,500;3,0000;MAIN;7,250;0',
                ],
                'EU-1',
                'Produit EU',
            ],
            'US comma and decimal point' => [
                [
                    'name,sku,type,sale_price_excl_tax,quantity,location_code,purchase_price,tax_rate',
                    'Product US,US-1,part,12.500,3.0000,MAIN,7.250,0',
                ],
                'US-1',
                'Product US',
            ],
        ];
    }

    /**
     * @return numeric-string
     */
    private function numericString(string|int|float|null $value): string
    {
        self::assertIsString($value, 'Rule 19: persisted money/quantity must be returned as a string, never a float.');
        if (! is_numeric($value)) {
            self::fail('Expected a numeric persisted decimal value.');
        }

        return (string) $value;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string|bool|list<string>>  $options
     */
    private function runImport(array $lines, string $type, array $options = []): string
    {
        $payload = [
            'file' => UploadedFile::fake()->createWithContent(
                $type.'-'.bin2hex(random_bytes(4)).'.csv',
                implode("\n", $lines),
            ),
            'type' => $type,
        ];
        if ($options !== []) {
            $payload['options'] = $options;
        }

        $createResponse = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/imports', $payload);
        $createResponse->assertCreated();
        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.failed_rows', 0);

        return $jobId;
    }
}
