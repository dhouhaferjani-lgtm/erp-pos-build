<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\Vertical;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Enums\SellerType;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceOrder;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\EnablesMarketplaceModule;

class MarketplaceCheckoutTest extends TestCase
{
    // Marketplace ships behind `config('marketplace.enabled')` (default FALSE),
    // which is read at BOOT time to gate route + schedule registration — so this
    // suite has to boot with the flag on rather than set config() at runtime.
    use EnablesMarketplaceModule;
    use RefreshDatabase;

    private Tenant $buyerTenant;

    private Company $buyerCompany;

    private User $buyerUser;

    private Tenant $sellerTenant;

    private Company $sellerCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyerTenant = Tenant::create([
            'name' => 'Buyer',
            'slug' => 'buyer-checkout',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->buyerCompany = Company::create([
            'tenant_id' => $this->buyerTenant->id,
            'name' => 'Buyer Co',
            'legal_name' => 'Buyer Co LLC',
            'tax_id' => 'CHKTAX1',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->sellerTenant = Tenant::create([
            'name' => 'Seller',
            'slug' => 'seller-checkout',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->sellerCompany = Company::create([
            'tenant_id' => $this->sellerTenant->id,
            'name' => 'Seller Co',
            'legal_name' => 'Seller Co LLC',
            'tax_id' => 'CHKTAX2',
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
            'name' => 'Buyer User',
            'email' => 'buyerchk@test.com',
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
    }

    /** @test */
    public function it_checks_out_marketplace_items_creating_order(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'seller_type' => SellerType::ErpTenant,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'price' => 40.000,
            'currency' => 'TND',
        ]);

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->buyerTenant->id,
            'company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
        ]);

        $item = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Marketplace Part',
            'quantity' => 3,
            'unit_price' => 40.000,
            'currency' => 'TND',
            'source' => 'marketplace',
            'marketplace_listing_id' => $listing->id,
        ]);

        $response = $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/marketplace-checkout", [
                'item_ids' => [$item->id],
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('pending', $data[0]['order_status']);
    }

    /** @test */
    public function it_validates_reservations_before_checkout(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'price' => 40.000,
        ]);

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->buyerTenant->id,
            'company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
        ]);

        // Create a location and stock reservation (expired/released)
        $location = Location::create([
            'company_id' => $this->sellerCompany->id,
            'name' => 'Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-EXPRSV',
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'sale_price' => 40.000,
        ]);

        $reservation = StockReservation::create([
            'company_id' => $this->sellerCompany->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1,
            'source_type' => 'marketplace_order',
            'source_id' => fake()->uuid(),
            'expires_at' => now()->subHour(), // Already expired
            'released_at' => now()->subMinutes(30), // Released
        ]);

        $item = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Expired Reservation Part',
            'quantity' => 1,
            'source' => 'marketplace',
            'marketplace_listing_id' => $listing->id,
            'reservation_id' => $reservation->id,
        ]);

        $response = $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/marketplace-checkout", [
                'item_ids' => [$item->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'BUSINESS_ERROR');
    }

    /** @test */
    public function it_creates_po_in_buyer_and_so_in_seller_system(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'seller_type' => SellerType::ErpTenant,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'price' => 60.000,
        ]);

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->buyerTenant->id,
            'company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
        ]);

        $item = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Premium Part',
            'quantity' => 1,
            'unit_price' => 60.000,
            'currency' => 'TND',
            'source' => 'marketplace',
            'marketplace_listing_id' => $listing->id,
        ]);

        $response = $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/marketplace-checkout", [
                'item_ids' => [$item->id],
            ]);

        $response->assertStatus(201);

        $orderId = $response->json('data.0.id');
        /** @var MarketplaceOrder $order */
        $order = MarketplaceOrder::query()->findOrFail($orderId);

        // Buyer PO should exist
        $this->assertNotNull($order->buyer_document_id);
        /** @var Document $buyerDoc */
        $buyerDoc = Document::query()->findOrFail($order->buyer_document_id);
        $this->assertEquals('purchase_order', $buyerDoc->type->value);
        $this->assertEquals($this->buyerCompany->id, $buyerDoc->company_id);

        // Seller SO should exist
        $this->assertNotNull($order->seller_document_id);
        /** @var Document $sellerDoc */
        $sellerDoc = Document::query()->findOrFail($order->seller_document_id);
        $this->assertEquals('sales_order', $sellerDoc->type->value);
        $this->assertEquals($this->sellerCompany->id, $sellerDoc->company_id);
    }
}
