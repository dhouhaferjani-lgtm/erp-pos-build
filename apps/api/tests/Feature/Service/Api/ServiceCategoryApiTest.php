<?php

declare(strict_types=1);

namespace Tests\Feature\Service\Api;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Service\Domain\Service;
use App\Modules\Service\Domain\ServiceCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API tests for ServiceCategory endpoints.
 */
class ServiceCategoryApiTest extends TestCase
{
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
    // Category Index Tests
    // ============================================

    #[Test]
    public function it_lists_categories_for_company(): void
    {
        ServiceCategory::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/service-categories');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'sort_order',
                        'is_active',
                        'services_count',
                    ],
                ],
            ])
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_lists_only_root_categories(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/service-categories?root_only=true');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ============================================
    // Category Show Tests
    // ============================================

    #[Test]
    public function it_shows_a_single_category(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/service-categories/{$category->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'sort_order',
                    'is_active',
                ],
            ])
            ->assertJsonPath('data.name', 'Maintenance');
    }

    // ============================================
    // Category Tree Tests
    // ============================================

    #[Test]
    public function it_returns_category_tree(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Changes',
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/service-categories/tree');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'children',
                    ],
                ],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Maintenance')
            ->assertJsonCount(1, 'data.0.children');
    }

    // ============================================
    // Category Create Tests
    // ============================================

    #[Test]
    public function it_creates_a_category(): void
    {
        $data = [
            'name' => 'Maintenance',
            'description' => 'All maintenance services',
            'sort_order' => 10,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/service-categories', $data);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Maintenance')
            ->assertJsonPath('data.sort_order', 10);

        $this->assertDatabaseHas('service_categories', [
            'name' => 'Maintenance',
            'company_id' => $this->company->id,
        ]);
    }

    #[Test]
    public function it_creates_a_nested_category(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/service-categories', [
                'name' => 'Oil Changes',
                'parent_id' => $parent->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Oil Changes')
            ->assertJsonPath('data.parent_id', $parent->id);
    }

    #[Test]
    public function it_validates_required_fields_when_creating_category(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/service-categories', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function it_prevents_duplicate_category_names(): void
    {
        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/service-categories', [
                'name' => 'Maintenance',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    // ============================================
    // Category Update Tests
    // ============================================

    #[Test]
    public function it_updates_a_category(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Maintenance',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/service-categories/{$category->id}", [
                'name' => 'Scheduled Maintenance',
                'description' => 'Updated description',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Scheduled Maintenance')
            ->assertJsonPath('data.description', 'Updated description');
    }

    // ============================================
    // Category Delete Tests
    // ============================================

    #[Test]
    public function it_deletes_a_category_without_services(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/service-categories/{$category->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
    }

    #[Test]
    public function it_prevents_deleting_category_with_services(): void
    {
        $category = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/service-categories/{$category->id}");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'CATEGORY_HAS_SERVICES');
    }

    #[Test]
    public function it_prevents_deleting_category_with_children(): void
    {
        $parent = ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        ServiceCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/service-categories/{$parent->id}");

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'CATEGORY_HAS_CHILDREN');
    }
}
