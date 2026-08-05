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
use App\Modules\Marketplace\Domain\Models\MarketplaceListing;
use App\Modules\Marketplace\Domain\Models\MarketplaceSeller;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\EnablesMarketplaceModule;

class PriceComparisonTest extends TestCase
{
    // Marketplace ships behind `config('marketplace.enabled')` (default FALSE),
    // which is read at BOOT time to gate route + schedule registration — so this
    // suite has to boot with the flag on rather than set config() at runtime.
    use EnablesMarketplaceModule;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-mechanic-pc',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAXPC123',
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
            'email' => 'user@pricecomp.test',
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
    public function it_finds_cheaper_marketplace_alternatives(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'country_code' => 'TN',
            'seller_status' => SellerStatus::Active,
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'BRAKE-100',
            'price' => 30.000, // Cheaper than current
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'BRAKE-100',
            'price' => 35.000, // Still cheaper
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/price-comparison?country=TN&article_number=BRAKE-100&current_price=50.000');

        $response->assertStatus(200)
            ->assertJsonPath('data.current_price', '50.000')
            ->assertJsonPath('data.best_marketplace_price', '30.000')
            ->assertJsonPath('data.savings_amount', '20.000')
            ->assertJsonPath('data.available_listings_count', 2);
    }

    /** @test */
    public function it_returns_null_when_no_cheaper_option(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'country_code' => 'TN',
            'seller_status' => SellerStatus::Active,
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'EXPENSIVE-PART',
            'price' => 100.000, // More expensive than current
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/price-comparison?country=TN&article_number=EXPENSIVE-PART&current_price=50.000');

        $response->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    /** @test */
    public function it_calculates_savings_correctly(): void
    {
        $seller = MarketplaceSeller::factory()->create([
            'country_code' => 'TN',
            'seller_status' => SellerStatus::Active,
        ]);

        MarketplaceListing::factory()->available()->create([
            'seller_id' => $seller->id,
            'country_code' => 'TN',
            'article_number' => 'CALC-PART',
            'price' => 80.000,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/price-comparison?country=TN&article_number=CALC-PART&current_price=100.000');

        $response->assertStatus(200);

        $data = $response->json('data');
        // Savings: 100 - 80 = 20
        $this->assertEquals('20.000', $data['savings_amount']);
        // Savings percent: (20 / 100) * 100 = 20.00
        $this->assertEquals('20.00', $data['savings_percent']);
    }
}
