<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context for the test
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_can_list_categories_with_pagination(): void
    {
        Category::factory()->count(30)->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/categories?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'slug', 'depth']],
                'meta' => ['per_page', 'has_more'],
                'links' => ['next', 'prev'],
            ]);
    }

    public function test_can_get_category_tree(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->company->id]);
        $child = Category::factory()->childOf($parent)->create();
        $grandchild = Category::factory()->childOf($child)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/categories/tree');

        $response->assertOk();

        $tree = $response->json('data');
        $this->assertCount(1, $tree);
        $this->assertEquals($parent->id, $tree[0]['id']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertEquals($child->id, $tree[0]['children'][0]['id']);
    }

    public function test_can_create_category(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'Electronics',
                'description' => 'Electronic products',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('categories', [
            'company_id' => $this->company->id,
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);
    }

    public function test_can_create_child_category(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'Smartphones',
                'parent_id' => $parent->id,
            ]);

        $response->assertCreated();

        $child = Category::find($response->json('data.id'));
        $this->assertEquals($parent->id, $child->parent_id);
        $this->assertEquals(1, $child->depth);
        $this->assertStringStartsWith($parent->path, $child->path);
    }

    public function test_cannot_set_category_as_own_parent(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'parent_id' => $category->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_create_circular_reference(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->company->id]);
        $child = Category::factory()->childOf($parent)->create();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$parent->id}", [
                'parent_id' => $child->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_cannot_delete_category_with_products(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(422);
    }

    public function test_cannot_delete_category_with_children(): void
    {
        $parent = Category::factory()->create(['company_id' => $this->company->id]);
        Category::factory()->childOf($parent)->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$parent->id}");

        $response->assertStatus(422);
    }

    public function test_breadcrumb_is_correct(): void
    {
        $root = Category::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Electronics',
        ]);
        $child = Category::factory()->childOf($root)->create(['name' => 'Phones']);
        $grandchild = Category::factory()->childOf($child)->create(['name' => 'Smartphones']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/categories/{$grandchild->id}");

        $breadcrumb = $response->json('data.breadcrumb');

        $this->assertCount(3, $breadcrumb);
        $this->assertEquals('Electronics', $breadcrumb[0]['name']);
        $this->assertEquals('Phones', $breadcrumb[1]['name']);
        $this->assertEquals('Smartphones', $breadcrumb[2]['name']);
    }

    public function test_category_isolation_between_companies(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherCategory = Category::factory()->create(['company_id' => $otherCompany->id]);
        $ourCategory = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/categories');

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($ourCategory->id));
        $this->assertFalse($ids->contains($otherCategory->id));
    }

    public function test_can_update_category(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'name' => 'Updated Name',
                'description' => 'Updated description',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);
    }

    public function test_can_delete_empty_category(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_can_show_single_category(): void
    {
        $category = Category::factory()->create(['company_id' => $this->company->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/categories/{$category->id}");

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ]);
    }

    public function test_category_path_updates_when_parent_changes(): void
    {
        $parent1 = Category::factory()->create(['company_id' => $this->company->id]);
        $parent2 = Category::factory()->create(['company_id' => $this->company->id]);
        $child = Category::factory()->childOf($parent1)->create();

        $this->assertStringStartsWith($parent1->path, $child->fresh()->path);

        // Move child to parent2
        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$child->id}", [
                'parent_id' => $parent2->id,
            ]);

        $this->assertStringStartsWith($parent2->path, $child->fresh()->path);
    }

    public function test_descendant_paths_update_when_ancestor_moves(): void
    {
        $root = Category::factory()->create(['company_id' => $this->company->id]);
        $child = Category::factory()->childOf($root)->create();
        $grandchild = Category::factory()->childOf($child)->create();

        $newParent = Category::factory()->create(['company_id' => $this->company->id]);

        // Move child (and its descendants) under newParent
        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$child->id}", [
                'parent_id' => $newParent->id,
            ]);

        $child->refresh();
        $grandchild->refresh();

        // Check that grandchild's path includes newParent's path
        $this->assertStringContainsString((string) $newParent->id, $grandchild->path);
        $this->assertStringContainsString((string) $child->id, $grandchild->path);
    }

    public function test_can_reorder_categories(): void
    {
        $cat1 = Category::factory()->create(['company_id' => $this->company->id, 'sort_order' => 0]);
        $cat2 = Category::factory()->create(['company_id' => $this->company->id, 'sort_order' => 1]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories/reorder', [
                'categories' => [
                    ['id' => $cat2->id, 'sort_order' => 0, 'parent_id' => null],
                    ['id' => $cat1->id, 'sort_order' => 1, 'parent_id' => null],
                ],
            ]);

        $response->assertOk();
        $this->assertEquals(0, $cat2->fresh()->sort_order);
        $this->assertEquals(1, $cat1->fresh()->sort_order);
    }

    public function test_category_tree_has_three_levels(): void
    {
        $root = Category::factory()->create(['company_id' => $this->company->id, 'name' => 'Root']);
        $level1 = Category::factory()->childOf($root)->create(['name' => 'Level 1']);
        $level2 = Category::factory()->childOf($level1)->create(['name' => 'Level 2']);
        $level3 = Category::factory()->childOf($level2)->create(['name' => 'Level 3']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/categories/tree');

        $response->assertOk();

        $tree = $response->json('data');
        $this->assertEquals('Root', $tree[0]['name']);
        $this->assertEquals('Level 1', $tree[0]['children'][0]['name']);
        $this->assertEquals('Level 2', $tree[0]['children'][0]['children'][0]['name']);
        $this->assertEquals('Level 3', $tree[0]['children'][0]['children'][0]['children'][0]['name']);
    }

    public function test_validation_requires_name(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'description' => 'Test',
            ]);

        // Validation should fail with 422 when name is missing
        $response->assertStatus(422);
    }

    public function test_parent_must_belong_to_same_company(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherCategory = Category::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'name' => 'Test',
                'parent_id' => $otherCategory->id,
            ]);

        $response->assertStatus(404);
    }
}
