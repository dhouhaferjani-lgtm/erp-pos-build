<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\Vertical;
use App\Modules\Cart\Application\Services\CartService;
use App\Modules\Cart\Domain\Enums\CartItemSource;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Enums\SellerType;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $sellerTenant;

    private Company $sellerCompany;

    private Tenant $buyerTenant;

    private Company $buyerCompany;

    private User $buyerUser;

    private MarketplaceSeller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        // Seller setup
        $this->sellerTenant = Tenant::create([
            'name' => 'Seller Tenant',
            'slug' => 'seller-tenant-sr',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->sellerCompany = Company::create([
            'tenant_id' => $this->sellerTenant->id,
            'name' => 'Seller Co',
            'legal_name' => 'Seller Co LLC',
            'tax_id' => 'SRTAX',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Buyer setup
        $this->buyerTenant = Tenant::create([
            'name' => 'Buyer Tenant',
            'slug' => 'buyer-tenant-sr',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->buyerCompany = Company::create([
            'tenant_id' => $this->buyerTenant->id,
            'name' => 'Buyer Co',
            'legal_name' => 'Buyer Co LLC',
            'tax_id' => 'BRTAX',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->buyerTenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->buyerUser = User::create([
            'tenant_id' => $this->buyerTenant->id,
            'name' => 'Buyer',
            'email' => 'buyer@sr.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->buyerUser->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->buyerUser->id,
            'company_id' => $this->buyerCompany->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->buyerCompany->id);

        $this->seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'seller_type' => SellerType::ErpTenant,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
    }

    /** @test */
    public function it_creates_reservation_on_add_to_cart(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'sale_price' => 50.000,
        ]);

        $location = Location::create([
            'company_id' => $this->sellerCompany->id,
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-MAIN',
        ]);

        StockLevel::create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100.00,
            'reserved' => 0.00,
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $this->seller->id,
            'country_code' => 'TN',
            'source_product_id' => $product->id,
            'price' => 50.000,
        ]);

        $cartService = app(CartService::class);

        $cart = $cartService->createCart($this->buyerUser, $this->buyerCompany, 'Test Cart');

        $item = $cartService->addItem($cart, [
            'source' => CartItemSource::Marketplace->value,
            'article_name' => 'Test Part',
            'quantity' => '5',
            'marketplace_listing_id' => $listing->id,
        ]);

        $this->assertNotNull($item->reservation_id);
        $this->assertNotNull($item->reservation_expires_at);

        // Verify reservation exists in stock_reservations
        $this->assertDatabaseHas('stock_reservations', [
            'id' => $item->reservation_id,
            'product_id' => $product->id,
            'quantity' => '5.0000',
            'source_type' => 'marketplace_order',
        ]);
    }

    /** @test */
    public function it_releases_reservation_on_remove_from_cart(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'sale_price' => 50.000,
        ]);

        $location = Location::create([
            'company_id' => $this->sellerCompany->id,
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-MAIN2',
        ]);

        StockLevel::create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100.00,
            'reserved' => 0.00,
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $this->seller->id,
            'country_code' => 'TN',
            'source_product_id' => $product->id,
            'price' => 50.000,
        ]);

        $cartService = app(CartService::class);
        $cart = $cartService->createCart($this->buyerUser, $this->buyerCompany);

        $item = $cartService->addItem($cart, [
            'source' => CartItemSource::Marketplace->value,
            'article_name' => 'Test Part',
            'quantity' => '3',
            'marketplace_listing_id' => $listing->id,
        ]);

        $reservationId = $item->reservation_id;
        $this->assertNotNull($reservationId);

        // Remove the item
        $cartService->removeItem($item);

        // Reservation should be released
        $this->assertDatabaseHas('stock_reservations', [
            'id' => $reservationId,
        ]);

        /** @var \App\Modules\Inventory\Domain\StockReservation $reservation */
        $reservation = \App\Modules\Inventory\Domain\StockReservation::query()->findOrFail($reservationId);
        $this->assertNotNull($reservation->released_at);
    }

    /** @test */
    public function it_enforces_re_reserve_anti_abuse_limit(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'sale_price' => 50.000,
        ]);

        $location = Location::create([
            'company_id' => $this->sellerCompany->id,
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-ABUSE',
        ]);

        StockLevel::create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1000.00,
            'reserved' => 0.00,
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $this->seller->id,
            'country_code' => 'TN',
            'source_product_id' => $product->id,
            'price' => 50.000,
        ]);

        // Pre-set the cache to simulate 3 previous reserve attempts
        Cache::put(
            "marketplace_reserve:{$this->buyerUser->id}:{$listing->id}",
            3,
            now()->endOfDay(),
        );

        $cartService = app(CartService::class);
        $cart = $cartService->createCart($this->buyerUser, $this->buyerCompany);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Re-reserve limit exceeded');

        $cartService->addItem($cart, [
            'source' => CartItemSource::Marketplace->value,
            'article_name' => 'Test Part',
            'quantity' => '1',
            'marketplace_listing_id' => $listing->id,
        ]);
    }

    /** @test */
    public function it_rejects_reservation_when_stock_insufficient(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'sale_price' => 50.000,
        ]);

        $location = Location::create([
            'company_id' => $this->sellerCompany->id,
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-LOW',
        ]);

        StockLevel::create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 2.00,
            'reserved' => 0.00,
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $this->seller->id,
            'country_code' => 'TN',
            'source_product_id' => $product->id,
            'price' => 50.000,
        ]);

        $cartService = app(CartService::class);
        $cart = $cartService->createCart($this->buyerUser, $this->buyerCompany);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $cartService->addItem($cart, [
            'source' => CartItemSource::Marketplace->value,
            'article_name' => 'Test Part',
            'quantity' => '50', // Way more than available
            'marketplace_listing_id' => $listing->id,
        ]);
    }
}
