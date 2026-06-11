<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use Database\Seeders\ParapharmacyMultiBranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-branch parapharmacy demo fixture.
 *
 * Proves the seeder provisions a 3-location topology (1 warehouse + 2 POS
 * shops) within a single tenant + company, distributes stock across all
 * three, attaches one POS terminal per shop, and scopes the per-shop cashiers
 * to a single location so a Paris cashier cannot read Lyon stock.
 */
final class ParapharmacyMultiBranchSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_one_warehouse_and_two_pos_shops(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        // Exactly 3 locations, all under the same single company.
        $this->assertSame(3, Location::count(), 'Multi-branch seeder must create exactly 3 locations');
        $this->assertSame(1, Location::distinct()->count('company_id'), 'All locations must share one company');

        $warehouses = Location::where('type', LocationType::Warehouse)->get();
        $shops = Location::where('type', LocationType::Shop)->get();

        $this->assertCount(1, $warehouses, 'Exactly one warehouse expected');
        $this->assertCount(2, $shops, 'Exactly two shops expected');

        // The warehouse is NOT POS-enabled; both shops ARE.
        $this->assertFalse((bool) $warehouses->first()->pos_enabled, 'Warehouse must not be POS-enabled');
        $this->assertTrue($shops->every(fn (Location $l): bool => (bool) $l->pos_enabled), 'Both shops must be POS-enabled');

        // Named shops present.
        $shopNames = $shops->pluck('name')->all();
        $this->assertContains('PharmaBio Paris', $shopNames);
        $this->assertContains('PharmaBio Lyon', $shopNames);
    }

    public function test_stock_exists_at_all_three_locations(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $warehouse = Location::where('type', LocationType::Warehouse)->firstOrFail();
        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();

        $this->assertGreaterThan(0, StockLevel::where('location_id', $warehouse->id)->count(), 'Warehouse must hold stock');
        $this->assertGreaterThan(0, StockLevel::where('location_id', $paris->id)->count(), 'Paris shop must hold stock');
        $this->assertGreaterThan(0, StockLevel::where('location_id', $lyon->id)->count(), 'Lyon shop must hold stock');

        // Bulk of inventory sits at the warehouse: it should carry more stock
        // rows than either individual shop (warehouse 90% vs shop ~60%).
        $warehouseRows = StockLevel::where('location_id', $warehouse->id)->count();
        $this->assertGreaterThan(
            StockLevel::where('location_id', $paris->id)->count(),
            $warehouseRows,
            'Warehouse should carry more stock rows than the Paris shop',
        );
    }

    public function test_one_pos_terminal_per_shop(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $this->assertSame(2, Terminal::count(), 'Exactly two terminals (one per shop) expected');

        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();
        $warehouse = Location::where('type', LocationType::Warehouse)->firstOrFail();

        $this->assertSame(1, Terminal::where('location_id', $paris->id)->count(), 'Paris must have one terminal');
        $this->assertSame(1, Terminal::where('location_id', $lyon->id)->count(), 'Lyon must have one terminal');
        $this->assertSame(0, Terminal::where('location_id', $warehouse->id)->count(), 'Warehouse must have no POS terminal');

        $this->assertTrue(
            Terminal::where('type', TerminalType::Physical)->count() === 2,
            'Both terminals must be physical POS devices',
        );
    }

    public function test_owner_and_manager_can_access_all_locations(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        foreach (['owner@pharmabio.fr', 'manager@pharmabio.fr'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $membership = UserCompanyMembership::where('user_id', $user->id)->firstOrFail();

            // NULL allowed_location_ids = access to every location.
            $this->assertNull(
                $membership->allowed_location_ids,
                "{$email} must retain all-location access for cross-branch transfers",
            );

            foreach (Location::all() as $location) {
                $this->assertTrue(
                    $membership->canAccessLocation($location),
                    "{$email} must be able to access {$location->name}",
                );
            }
        }
    }

    public function test_paris_cashier_cannot_read_lyon_stock(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();
        $warehouse = Location::where('type', LocationType::Warehouse)->firstOrFail();

        $parisCashier = User::where('email', 'paris.cashier@pharmabio.fr')->firstOrFail();
        $membership = UserCompanyMembership::where('user_id', $parisCashier->id)->firstOrFail();

        // Scoped to exactly the Paris shop.
        $this->assertSame([$paris->id], $membership->allowed_location_ids, 'Paris cashier must be scoped to Paris only');
        $this->assertTrue($membership->canAccessLocation($paris), 'Paris cashier can access Paris');
        $this->assertFalse($membership->canAccessLocation($lyon), 'Paris cashier must NOT access Lyon');
        $this->assertFalse($membership->canAccessLocation($warehouse), 'Paris cashier must NOT access the warehouse');

        // The location scope, applied to a real stock query, must hide Lyon
        // stock from the Paris cashier and surface Paris stock.
        $allowed = $membership->allowed_location_ids;
        $this->assertNotNull($allowed);

        $visibleStock = StockLevel::whereIn('location_id', $allowed)->get();
        $this->assertGreaterThan(0, $visibleStock->count(), 'Paris cashier must see Paris stock');
        $this->assertTrue(
            $visibleStock->every(fn (StockLevel $s): bool => $s->location_id === $paris->id),
            'Every stock row visible to the Paris cashier must belong to the Paris shop',
        );
        $this->assertSame(
            0,
            StockLevel::whereIn('location_id', $allowed)->where('location_id', $lyon->id)->count(),
            'No Lyon stock row may be visible through the Paris cashier scope',
        );
    }

    public function test_lyon_cashier_is_scoped_to_lyon(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();
        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();

        $lyonCashier = User::where('email', 'lyon.cashier@pharmabio.fr')->firstOrFail();
        $membership = UserCompanyMembership::where('user_id', $lyonCashier->id)->firstOrFail();

        $this->assertSame([$lyon->id], $membership->allowed_location_ids, 'Lyon cashier must be scoped to Lyon only');
        $this->assertTrue($membership->canAccessLocation($lyon));
        $this->assertFalse($membership->canAccessLocation($paris));
    }

    public function test_each_shop_has_a_distinct_branch_tax_id_resolved_via_resolver(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $lyon = Location::where('name', 'PharmaBio Lyon')->firstOrFail();
        $warehouse = Location::where('type', LocationType::Warehouse)->firstOrFail();

        // Each shop carries its own establishment SIRET; they differ.
        $this->assertNotNull($paris->tax_id, 'Paris shop must have a per-branch tax id');
        $this->assertNotNull($lyon->tax_id, 'Lyon shop must have a per-branch tax id');
        $this->assertNotSame($paris->tax_id, $lyon->tax_id, 'Paris and Lyon must have distinct branch tax ids');

        // The warehouse leaves tax_id NULL → it inherits the company tax id.
        $this->assertNull($warehouse->tax_id, 'Warehouse must inherit company tax id (NULL override)');

        $resolver = new TaxIdentityResolver;

        // Shops resolve to their OWN SIRET; the warehouse resolves to the company's.
        $this->assertSame($paris->tax_id, $resolver->resolve($paris)->taxId);
        $this->assertSame($lyon->tax_id, $resolver->resolve($lyon)->taxId);

        $companyTaxId = $warehouse->company()->firstOrFail()->tax_id;
        $this->assertSame($companyTaxId, $resolver->resolve($warehouse)->taxId, 'Warehouse must inherit company tax id');
        $this->assertNotSame($resolver->resolve($paris)->taxId, $resolver->resolve($warehouse)->taxId);
    }

    public function test_seeds_orthopedic_variant_products_with_one_sku_per_size(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        // A size attribute flagged as a variant axis, with one value per size.
        $sizeAttribute = ProductAttribute::where('code', 'size')->firstOrFail();
        $this->assertTrue((bool) $sizeAttribute->is_variant_axis, 'Size must be a variant axis');
        $this->assertGreaterThanOrEqual(
            5,
            ProductAttributeValue::where('attribute_id', $sizeAttribute->id)->count(),
            'At least 5 size values (EU 38–42)',
        );

        // The orthopedic shoe parent has one variant per size, with distinct SKUs.
        $shoe = Product::where('sku', 'PB-ORT-SHOE')->firstOrFail();
        $variants = ProductVariant::where('product_id', $shoe->id)->get();
        $this->assertGreaterThanOrEqual(5, $variants->count(), 'Shoe must have one variant per size (>=5)');
        $this->assertSame($variants->count(), $variants->pluck('sku')->unique()->count(), 'Variant SKUs must be unique');
        $this->assertSame(1, $variants->where('is_default', true)->count(), 'Exactly one default variant');
        $this->assertContains('PB-ORT-SHOE-40', $variants->pluck('sku')->all(), 'Size 40 SKU expected');

        // Each variant is linked to a size attribute value.
        $this->assertSame(
            $variants->count(),
            ProductVariantAttributeValue::whereIn('variant_id', $variants->pluck('id'))->count(),
            'Every variant must link to its size attribute value',
        );

        // Variant stock lands at a shop (variant_id populated).
        $paris = Location::where('name', 'PharmaBio Paris')->firstOrFail();
        $this->assertGreaterThan(
            0,
            StockLevel::where('location_id', $paris->id)->whereNotNull('variant_id')->count(),
            'Paris shop must hold variant stock rows',
        );
    }

    public function test_seeds_a_chargeable_house_account_customer(): void
    {
        $this->seed(ParapharmacyMultiBranchSeeder::class);

        $customer = Partner::where('email', 'compte@clinique-saint-louis.fr')->firstOrFail();

        $this->assertTrue($customer->isCustomer(), 'House account must be a customer partner');
        $this->assertSame(CustomerAccountStatus::Active, $customer->account_status, 'House account must be active');
        $this->assertTrue($customer->hasActiveCreditLimit(), 'House account must have a non-zero credit limit (chargeable)');
    }
}
