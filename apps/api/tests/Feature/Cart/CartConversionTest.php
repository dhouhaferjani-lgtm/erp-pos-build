<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\Vertical;
use App\Modules\Cart\Domain\Enums\CartStatus;
use App\Modules\Cart\Domain\Models\CatalogCart;
use App\Modules\Cart\Domain\Models\CatalogCartItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CartConversionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-conv-mech',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'CONVTAX',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Conv User',
            'email' => 'conv@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function it_converts_catalog_items_to_purchase_order(): void
    {
        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $item1 = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Brake Pads',
            'article_number' => 'BP-001',
            'supplier_brand' => 'Bosch',
            'quantity' => 2,
            'unit_price' => 45.000,
            'currency' => 'TND',
            'source' => 'catalog',
        ]);

        $item2 = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Oil Filter',
            'article_number' => 'OF-002',
            'supplier_brand' => 'Bosch',
            'quantity' => 1,
            'unit_price' => 15.000,
            'currency' => 'TND',
            'source' => 'catalog',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/convert", [
                'item_ids' => [$item1->id, $item2->id],
                'type' => 'purchase_order',
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertEquals('purchase_order', $data[0]['type']);

        // Cart should be converted
        $cart->refresh();
        $this->assertEquals(CartStatus::Converted, $cart->status);
    }

    /** @test */
    public function it_converts_to_sales_order_with_customer(): void
    {
        $customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
            'country_code' => 'TN',
        ]);

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $item = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Spark Plugs',
            'quantity' => 4,
            'unit_price' => 8.500,
            'currency' => 'TND',
            'source' => 'catalog',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/convert", [
                'item_ids' => [$item->id],
                'type' => 'sales_order',
                'customer_id' => $customer->id,
            ]);

        $response->assertStatus(201);
        $this->assertEquals('sales_order', $response->json('data.type'));
    }

    /** @test */
    public function it_groups_items_by_supplier_for_multiple_pos(): void
    {
        $supplier1 = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier A',
            'type' => PartnerType::Supplier,
            'country_code' => 'TN',
        ]);

        $supplier2 = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Supplier B',
            'type' => PartnerType::Supplier,
            'country_code' => 'TN',
        ]);

        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $item1 = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Part from A',
            'quantity' => 1,
            'unit_price' => 20.000,
            'currency' => 'TND',
            'source' => 'catalog',
            'preferred_supplier_partner_id' => $supplier1->id,
        ]);

        $item2 = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Part from B',
            'quantity' => 1,
            'unit_price' => 30.000,
            'currency' => 'TND',
            'source' => 'catalog',
            'preferred_supplier_partner_id' => $supplier2->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/convert", [
                'item_ids' => [$item1->id, $item2->id],
                'type' => 'purchase_order',
            ]);

        $response->assertStatus(201);

        // Should create 2 POs (one per supplier)
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    /** @test */
    public function it_sets_cart_status_to_partial_when_marketplace_items_remain(): void
    {
        $cart = CatalogCart::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $catalogItem = CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Catalog Part',
            'quantity' => 1,
            'unit_price' => 20.000,
            'currency' => 'TND',
            'source' => 'catalog',
        ]);

        // This marketplace item won't be converted
        CatalogCartItem::create([
            'cart_id' => $cart->id,
            'article_name' => 'Marketplace Part',
            'quantity' => 1,
            'unit_price' => 30.000,
            'currency' => 'TND',
            'source' => 'marketplace',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/catalog-carts/{$cart->id}/convert", [
                'item_ids' => [$catalogItem->id],
                'type' => 'purchase_order',
            ]);

        $response->assertStatus(201);

        $cart->refresh();
        $this->assertEquals(CartStatus::Partial, $cart->status);
    }
}
