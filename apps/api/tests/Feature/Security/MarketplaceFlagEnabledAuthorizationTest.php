<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Vertical;
use App\Modules\Cart\Domain\Models\CatalogCart;
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
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\EnablesMarketplaceModule;

/**
 * Marketplace kill-switch — ENABLED half, behaviour rather than wiring.
 *
 * {@see MarketplaceFlagEnabledTest} asserts the route/middleware wiring by
 * inspecting middleware strings. This class exercises the two things that the
 * string assertions cannot prove:
 *
 *  1. the `can:marketplace.browse` gate actually DENIES (403) a role that does
 *     not hold the permission — the pin that will catch the seeder change when
 *     the fleet-admin redesign narrows `marketplace.*`
 *     (docs/superpowers/tickets/2026-08-05-marketplace-admin-surface-redesign.md);
 *  2. the Cart→Marketplace application path closed by
 *     {@see MarketplaceCartItemGatingTest} re-opens intact when the flag is on
 *     (the guard is a kill-switch, not a removal of the feature).
 */
final class MarketplaceFlagEnabledAuthorizationTest extends TestCase
{
    use EnablesMarketplaceModule;
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

        $this->sellerTenant = Tenant::create([
            'name' => 'Seller Tenant',
            'slug' => 'seller-tenant-mpauth',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->sellerCompany = Company::create([
            'tenant_id' => $this->sellerTenant->id,
            'name' => 'Seller Co',
            'legal_name' => 'Seller Co LLC',
            'tax_id' => 'MPAUTHS',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->buyerTenant = Tenant::create([
            'name' => 'Buyer Tenant',
            'slug' => 'buyer-tenant-mpauth',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->buyerCompany = Company::create([
            'tenant_id' => $this->buyerTenant->id,
            'name' => 'Buyer Co',
            'legal_name' => 'Buyer Co LLC',
            'tax_id' => 'MPAUTHB',
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
            'email' => 'buyer@mpauth.test',
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

    public function test_the_flag_is_on_for_this_class(): void
    {
        $this->assertTrue(config('marketplace.enabled'));
    }

    public function test_a_role_without_marketplace_browse_is_denied_the_listings_index(): void
    {
        $role = Role::create(['name' => 'marketplace-denied', 'guard_name' => 'sanctum']);

        $denied = User::create([
            'tenant_id' => $this->buyerTenant->id,
            'name' => 'Denied',
            'email' => 'denied@mpauth.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $denied->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $denied->id,
            'company_id' => $this->buyerCompany->id,
            'role' => MembershipRole::Viewer,
        ]);

        $this->assertFalse($denied->can('marketplace.browse'));

        $this->actingAs($denied, 'sanctum')
            ->getJson('/api/v1/marketplace/listings?country=TN')
            ->assertForbidden();
    }

    public function test_cart_items_store_accepts_marketplace_payloads_when_enabled(): void
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
            'code' => 'WH-MPAUTH',
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

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->buyerTenant->id,
            'company_id' => $this->buyerCompany->id,
            'user_id' => $this->buyerUser->id,
        ]);

        $response = $this->actingAs($this->buyerUser, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/items", [
                'source' => 'marketplace',
                'article_name' => 'Marketplace Part',
                'quantity' => '5',
                'marketplace_listing_id' => $listing->id,
            ]);

        $response->assertCreated();
        $this->assertSame($listing->id, $response->json('data.marketplace_listing_id'));
        $this->assertDatabaseHas('stock_reservations', [
            'product_id' => $product->id,
            'source_type' => 'marketplace_order',
        ]);
    }
}
