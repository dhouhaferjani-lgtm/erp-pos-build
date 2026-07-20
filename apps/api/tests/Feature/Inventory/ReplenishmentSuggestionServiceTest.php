<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\ReplenishmentSuggestionService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReplenishmentSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private ReplenishmentSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-01',
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->service = app(ReplenishmentSuggestionService::class);
    }

    private function makeUnit(int $decimalPlaces, RoundingMethod $rounding): Unit
    {
        return Unit::factory()->create([
            'decimal_places' => $decimalPlaces,
            'rounding_method' => $rounding,
        ]);
    }

    private function makeProduct(string $sku, ?string $unitId): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'unit_id' => $unitId,
        ]);
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string|null  $min
     * @param  numeric-string|null  $max
     */
    private function stock(Product $product, string $quantity, ?string $min, ?string $max): void
    {
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0',
            'min_quantity' => $min,
            'max_quantity' => $max,
        ]);
    }

    /**
     * @param  list<Product>  $products
     * @return array<string, string>
     */
    private function suggest(array $products): array
    {
        return $this->service->suggestionsForLocation(
            $this->tenant->id,
            $this->company->id,
            $this->location->id,
            array_map(
                static fn (Product $p): array => ['product_id' => $p->id, 'variant_id' => null],
                $products,
            ),
        );
    }

    public function test_piece_product_suggestion_is_whole_number(): void
    {
        $unit = $this->makeUnit(0, RoundingMethod::HalfUp);
        $product = $this->makeProduct('PIECE-01', $unit->id);
        $this->stock($product, '5.0000', null, '12.0000');

        $result = $this->suggest([$product]);

        self::assertSame('7', $result[$product->id.'|']);
    }

    public function test_kg_product_suggestion_pads_to_three_decimals(): void
    {
        $unit = $this->makeUnit(3, RoundingMethod::HalfUp);
        $product = $this->makeProduct('KG-01', $unit->id);
        $this->stock($product, '0.5000', null, '2.0000');

        $result = $this->suggest([$product]);

        self::assertSame('1.500', $result[$product->id.'|']);
    }

    public function test_piece_product_without_min_max_floors_to_one_whole(): void
    {
        $unit = $this->makeUnit(0, RoundingMethod::HalfUp);
        $product = $this->makeProduct('PIECE-02', $unit->id);
        $this->stock($product, '3.0000', null, null);

        $result = $this->suggest([$product]);

        self::assertSame('1', $result[$product->id.'|']);
    }

    public function test_unitless_product_without_min_max_falls_back_to_scale_four(): void
    {
        $product = $this->makeProduct('NOUNIT-01', null);
        $this->stock($product, '3.0000', null, null);

        $result = $this->suggest([$product]);

        self::assertSame('1.0000', $result[$product->id.'|']);
    }

    public function test_unit_metadata_is_fetched_in_a_single_query(): void
    {
        $pieceUnit = $this->makeUnit(0, RoundingMethod::HalfUp);
        $kgUnit = $this->makeUnit(3, RoundingMethod::HalfUp);

        $products = [];
        for ($i = 0; $i < 5; $i++) {
            $product = $this->makeProduct('PIECE-Q'.$i, $pieceUnit->id);
            $this->stock($product, '1.0000', null, '10.0000');
            $products[] = $product;
        }
        for ($i = 0; $i < 5; $i++) {
            $product = $this->makeProduct('KG-Q'.$i, $kgUnit->id);
            $this->stock($product, '0.5000', null, '3.0000');
            $products[] = $product;
        }

        DB::enableQueryLog();
        $this->suggest($products);
        $unitQueries = collect(DB::getQueryLog())
            ->filter(static fn (array $q): bool => str_contains($q['query'], 'units'))
            ->count();
        DB::disableQueryLog();

        self::assertSame(1, $unitQueries);
    }
}
