<?php

declare(strict_types=1);

namespace Tests\Feature\PurchaseHub;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchaseHubOfferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Parapharmacy',
            'slug' => 'test-parapharmacy-phub',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Parapharmacy Company',
            'legal_name' => 'Test Parapharmacy SARL',
            'tax_id' => 'TAX-TN-PHUB-01',
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
            'email' => 'phub-offer-test@test.tn',
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

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);
    }

    /** @test */
    public function it_lists_offers_from_platform(): void
    {
        Http::fake([
            'platform.test/api/v1/purchase-hub/tenant/offers*' => Http::response([
                'data' => [
                    [
                        'id' => 'campaign-001',
                        'title' => 'Spring Promo',
                        'supplier_name' => 'Bioderma',
                        'status' => 'active',
                    ],
                    [
                        'id' => 'campaign-002',
                        'title' => 'Summer Sale',
                        'supplier_name' => 'La Roche-Posay',
                        'status' => 'active',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/purchase-hub/offers');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Spring Promo')
            ->assertJsonPath('data.1.supplier_name', 'La Roche-Posay')
            ->assertJsonStructure(['data', 'meta' => ['timestamp']]);
    }

    /** @test */
    public function it_returns_502_when_platform_unavailable(): void
    {
        Http::fake([
            'platform.test/api/v1/purchase-hub/tenant/offers*' => Http::response(
                ['error' => 'Internal Server Error'],
                500
            ),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/purchase-hub/offers');

        $response->assertStatus(502)
            ->assertJsonPath('data', null);
    }

    /** @test */
    public function it_shows_single_offer_from_platform(): void
    {
        Http::fake([
            'platform.test/api/v1/purchase-hub/tenant/offers/campaign-001*' => Http::response([
                'data' => [
                    'id' => 'campaign-001',
                    'title' => 'Spring Promo',
                    'supplier_name' => 'Bioderma',
                    'status' => 'active',
                    'items' => [
                        ['id' => 'item-001', 'product_name' => 'Crealine H2O', 'unit_price' => 12.50],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/purchase-hub/offers/campaign-001');

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Spring Promo')
            ->assertJsonPath('data.items.0.product_name', 'Crealine H2O');
    }
}
