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
use App\Modules\Marketplace\Domain\Enums\ListingStatus;
use App\Modules\Marketplace\Domain\Enums\SellerStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

class MarketplaceListingTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-mechanic',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
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
            'name' => 'Test User',
            'email' => 'user@test.com',
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
    public function it_lists_marketplace_listings_by_country_and_article_number(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'country_code' => 'TN',
            'seller_status' => SellerStatus::Active,
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'BOSCH-1234',
            'product_name' => 'Brake Pads Bosch',
            'price' => 45.500,
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'FR', // Different country
            'article_number' => 'BOSCH-1234',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/listings?country=TN&article_number=BOSCH-1234');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.article_number', 'BOSCH-1234')
            ->assertJsonPath('data.0.product_name', 'Brake Pads Bosch');
    }

    /** @test */
    public function it_returns_anonymous_listings_without_seller_identity(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'country_code' => 'TN',
            'seller_status' => SellerStatus::Active,
            'display_name' => 'Secret Seller Corp',
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'TRW-5678',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/listings?country=TN&article_number=TRW-5678');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Verify seller identity is NOT exposed
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('seller_id', $data);
        $this->assertArrayNotHasKey('seller_name', $data);
        $this->assertArrayNotHasKey('seller', $data);
    }

    /** @test */
    public function it_filters_by_barcode(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'country_code' => 'TN',
            'seller_status' => SellerStatus::Active,
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'barcode' => '4006633103893',
            'product_name' => 'Oil Filter',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/listings?country=TN&barcode=4006633103893');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Oil Filter');
    }

    /** @test */
    public function it_only_returns_active_available_listings(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'country_code' => 'TN',
            'seller_status' => SellerStatus::Active,
        ]);

        // Active and in stock
        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'COMMON-PART',
        ]);

        // Out of stock
        MarketplaceListing::factory()->outOfStock()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'COMMON-PART',
        ]);

        // Delisted
        MarketplaceListing::factory()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'COMMON-PART',
            'listing_status' => ListingStatus::Delisted,
            'quantity_available' => 50,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/listings?country=TN&article_number=COMMON-PART');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function it_requires_marketplace_browse_permission(): void
    {
        // Create a user with no permissions
        $noPermUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Perm User',
            'email' => 'noperm@test.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $response = $this->actingAs($noPermUser, 'sanctum')
            ->getJson('/api/v1/marketplace/listings?country=TN&article_number=TEST');

        $response->assertStatus(403);
    }
}
