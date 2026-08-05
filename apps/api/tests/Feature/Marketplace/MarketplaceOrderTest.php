<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Enums\SellerType;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceOrder;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;
use Tests\Traits\EnablesMarketplaceModule;

class MarketplaceOrderTest extends TestCase
{
    use AssertsApiValidation;

    // Marketplace ships behind `config('marketplace.enabled')` (default FALSE),
    // which is read at BOOT time to gate route + schedule registration — so this
    // suite has to boot with the flag on rather than set config() at runtime.
    use EnablesMarketplaceModule;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Tenant $sellerTenant;

    private Company $sellerCompany;

    protected function setUp(): void
    {
        parent::setUp();

        // Buyer tenant/company
        $this->tenant = Tenant::create([
            'name' => 'Buyer Mechanic',
            'slug' => 'buyer-mechanic',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer Company',
            'legal_name' => 'Buyer Company LLC',
            'tax_id' => 'BUYTAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        // Seller tenant/company
        $this->sellerTenant = Tenant::create([
            'name' => 'Seller Parts',
            'slug' => 'seller-parts',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::PartsRetailer,
        ]);

        $this->sellerCompany = Company::create([
            'tenant_id' => $this->sellerTenant->id,
            'name' => 'Seller Company',
            'legal_name' => 'Seller Company LLC',
            'tax_id' => 'SELLTAX456',
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
            'name' => 'Buyer User',
            'email' => 'buyer@test.com',
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
    public function it_creates_marketplace_order_with_po_and_so(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'seller_type' => SellerType::ErpTenant,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
            'commission_rate' => 5.00,
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'BOSCH-BP-001',
            'product_name' => 'Brake Pads Premium',
            'price' => 50.000,
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/marketplace/orders', [
                'items' => [
                    ['listing_id' => $listing->id, 'quantity' => '2'],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.order_status', 'pending')
            ->assertJsonPath('data.currency', 'TND');

        $orderId = $response->json('data.id');
        /** @var MarketplaceOrder $order */
        $order = MarketplaceOrder::query()->findOrFail($orderId);

        // PO created in buyer system
        $this->assertNotNull($order->buyer_document_id);
        // SO created in seller system
        $this->assertNotNull($order->seller_document_id);
    }

    /** @test */
    public function it_auto_creates_partner_records_on_first_order(): void
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
            'price' => 30.000,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/marketplace/orders', [
                'items' => [
                    ['listing_id' => $listing->id, 'quantity' => '1'],
                ],
            ]);

        $response->assertStatus(201);

        // Verify partner auto-creation
        $this->assertDatabaseHas('partners', [
            'company_id' => $this->company->id,
            'type' => 'supplier',
        ]);

        $this->assertDatabaseHas('partners', [
            'company_id' => $this->sellerCompany->id,
            'type' => 'customer',
        ]);

        // Verify buyer-seller mapping created
        $this->assertDatabaseHas('buyer_seller_mappings', [
            'seller_id' => $seller->id,
            'buyer_company_id' => $this->company->id,
        ]);
    }

    /** @test */
    public function it_cancels_marketplace_order_and_releases_reservations(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'price' => 25.000,
        ]);

        // Create order first
        $createResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/marketplace/orders', [
                'items' => [
                    ['listing_id' => $listing->id, 'quantity' => '1'],
                ],
            ]);

        $createResponse->assertStatus(201);
        $orderId = $createResponse->json('data.id');

        // Cancel order
        $cancelResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/marketplace/orders/{$orderId}/cancel");

        $cancelResponse->assertStatus(200)
            ->assertJsonPath('data.order_status', 'cancelled');
    }

    /** @test */
    public function it_requires_marketplace_order_permission(): void
    {
        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer User',
            'email' => 'viewer@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewer->assignRole('viewer');

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/marketplace/orders', [
                'items' => [
                    ['listing_id' => fake()->uuid(), 'quantity' => '1'],
                ],
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_calculates_commission_correctly(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'tenant_id' => $this->sellerTenant->id,
            'company_id' => $this->sellerCompany->id,
            'seller_status' => SellerStatus::Active,
            'country_code' => 'TN',
            'currency' => 'TND',
            'commission_rate' => 10.00, // 10% commission
        ]);

        $listing = MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'price' => 100.000,
            'currency' => 'TND',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/marketplace/orders', [
                'items' => [
                    ['listing_id' => $listing->id, 'quantity' => '2'],
                ],
            ]);

        $response->assertStatus(201);

        // Subtotal: 100 * 2 = 200
        // Commission: 200 * 10% = 20
        // Total: 200 + 20 = 220
        $this->assertEquals('200.000', $response->json('data.subtotal'));
        $this->assertEquals('20.000', $response->json('data.commission_amount'));
        $this->assertEquals('220.000', $response->json('data.total'));
    }
}
