<?php

declare(strict_types=1);

namespace Tests\Feature\Service\Api;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * API tests for Service endpoints.
 */
class ServiceApiTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create user-company membership
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);
    }

    // ============================================
    // Service Index Tests
    // ============================================

    #[Test]
    public function it_lists_services_for_company(): void
    {
        Service::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/services');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'pricing_type',
                        'base_price',
                        'currency',
                        'is_active',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ])
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_filters_services_by_active_status(): void
    {
        Service::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        Service::factory()->inactive()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/services?active=true');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function it_filters_services_by_pricing_type(): void
    {
        Service::factory()->flatRate()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->hourly()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/services?pricing_type=hourly');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function it_searches_services_by_name_or_code(): void
    {
        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
            'name' => 'Oil Change',
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-002',
            'name' => 'Brake Service',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/services?search=oil');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Oil Change');
    }

    // ============================================
    // Service Show Tests
    // ============================================

    #[Test]
    public function it_shows_a_single_service(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
            'name' => 'Oil Change',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/services/{$service->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'code',
                    'name',
                    'description',
                    'pricing_type',
                    'base_price',
                    'currency',
                    'is_active',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.code', 'SRV-001')
            ->assertJsonPath('data.name', 'Oil Change');
    }

    #[Test]
    public function it_returns_404_for_non_existent_service(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/services/non-existent-id');

        $response->assertNotFound();
    }

    // ============================================
    // Service Create Tests
    // ============================================

    #[Test]
    public function it_creates_a_service(): void
    {
        $data = [
            'code' => 'SRV-001',
            'name' => 'Oil Change',
            'description' => 'Full synthetic oil change',
            'pricing_type' => 'flat_rate',
            'base_price' => '45.00',
            'currency' => 'TND',
            'default_duration_minutes' => 30,
            'tax_rate' => '19.00',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/services', $data);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'SRV-001')
            ->assertJsonPath('data.name', 'Oil Change');

        $this->assertDatabaseHas('services', [
            'code' => 'SRV-001',
            'name' => 'Oil Change',
            'company_id' => $this->company->id,
        ]);
    }

    #[Test]
    public function it_validates_required_fields_when_creating(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/services', []);

        $this->assertApiValidationErrors($response, ['code', 'name', 'pricing_type', 'base_price']);
    }

    #[Test]
    public function it_prevents_duplicate_service_codes(): void
    {
        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/services', [
                'code' => 'SRV-001',
                'name' => 'Another Service',
                'pricing_type' => 'flat_rate',
                'base_price' => '50.00',
            ]);

        $this->assertApiValidationErrors($response, ['code']);
    }

    // ============================================
    // Service Update Tests
    // ============================================

    #[Test]
    public function it_updates_a_service(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SRV-001',
            'name' => 'Oil Change',
            'base_price' => '45.00',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/services/{$service->id}", [
                'name' => 'Premium Oil Change',
                'base_price' => '65.00',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Premium Oil Change')
            ->assertJsonPath('data.base_price', '65.00');

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'name' => 'Premium Oil Change',
        ]);
    }

    // ============================================
    // Service Delete Tests
    // ============================================

    #[Test]
    public function it_deletes_a_service(): void
    {
        $service = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/services/{$service->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }
}
