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

class PurchaseHubOrderTest extends TestCase
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
            'slug' => 'test-parapharmacy-phub-order',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Parapharmacy Order Co',
            'legal_name' => 'Test Parapharmacy Order SARL',
            'tax_id' => 'TAX-TN-PHUB-02',
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
            'email' => 'phub-order-test@test.tn',
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
    public function it_places_order_through_platform(): void
    {
        Http::fake([
            'platform.test/api/v1/purchase-hub/tenant/orders' => Http::response([
                'data' => [
                    'id' => 'order-001',
                    'campaign_id' => 'campaign-001',
                    'status' => 'pending',
                    'total' => 125.00,
                    'items' => [
                        ['campaign_item_id' => 'item-001', 'quantity' => 10, 'unit_price' => 12.50],
                    ],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-hub/orders', [
                'campaign_id' => 'campaign-001',
                'items' => [
                    ['campaign_item_id' => 'item-001', 'quantity' => 10],
                ],
                'notes' => 'Urgent delivery needed',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.id', 'order-001')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', 125);
    }

    /** @test */
    public function it_validates_order_request(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-hub/orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['campaign_id', 'items'], 'error.errors');
    }

    /** @test */
    public function it_returns_502_when_order_fails(): void
    {
        Http::fake([
            'platform.test/api/v1/purchase-hub/tenant/orders' => Http::response(
                ['error' => 'Internal Server Error'],
                500
            ),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-hub/orders', [
                'campaign_id' => 'campaign-001',
                'items' => [
                    ['campaign_item_id' => 'item-001', 'quantity' => 5],
                ],
            ]);

        $response->assertStatus(502)
            ->assertJsonPath('error.code', 'ORDER_FAILED');
    }

    /** @test */
    public function it_lists_orders_from_platform(): void
    {
        Http::fake([
            'platform.test/api/v1/purchase-hub/tenant/orders*' => Http::response([
                'data' => [
                    ['id' => 'order-001', 'status' => 'pending', 'total' => 125.00],
                    ['id' => 'order-002', 'status' => 'confirmed', 'total' => 250.00],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/purchase-hub/orders');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_receives_webhook(): void
    {
        $response = $this->postJson('/api/webhooks/purchase-hub', [
            'event' => 'order.status_changed',
            'payload' => [
                'order_id' => 'order-001',
                'new_status' => 'confirmed',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'received');
    }
}
