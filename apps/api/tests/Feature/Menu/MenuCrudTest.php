<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Menu\Domain\Entities\MenuCategory;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MenuCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
        Sanctum::actingAs($this->user);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('menus.view', 'sanctum');
        Permission::findOrCreate('menus.manage', 'sanctum');
        $this->user->givePermissionTo('menus.view', 'menus.manage');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMenu(array $overrides = []): Menu
    {
        return Menu::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Menu',
            'is_default' => false,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompositeItem(array $overrides = []): CompositeItem
    {
        return CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CI-' . fake()->unique()->numberBetween(1000, 9999),
            'name' => 'Test Item',
            'vertical_type' => 'fnb',
            'base_price' => '5.00',
            'production_type' => 'made_to_order',
            'is_active' => true,
            'is_available' => true,
            ...$overrides,
        ]);
    }

    // ── Menu CRUD ──────────────────────────────────────────────────────

    public function test_can_create_menu(): void
    {
        $response = $this->postJson('/api/v1/menus', [
            'name' => 'Breakfast Menu',
            'description' => 'Served 7am-11am',
            'is_default' => false,
            'is_active' => true,
            'active_from' => '07:00',
            'active_until' => '11:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Breakfast Menu');
        $response->assertJsonPath('data.description', 'Served 7am-11am');
        $this->assertDatabaseHas('menus', [
            'name' => 'Breakfast Menu',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_can_list_menus(): void
    {
        $this->createMenu(['name' => 'Menu A']);
        $this->createMenu(['name' => 'Menu B']);

        $response = $this->getJson('/api/v1/menus');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_can_filter_menus_by_search(): void
    {
        $this->createMenu(['name' => 'Breakfast Menu']);
        $this->createMenu(['name' => 'Lunch Menu']);

        $response = $this->getJson('/api/v1/menus?search=Breakfast');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Breakfast Menu');
    }

    public function test_can_show_menu(): void
    {
        $menu = $this->createMenu(['name' => 'Show Me']);

        $response = $this->getJson("/api/v1/menus/{$menu->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Show Me');
    }

    public function test_show_returns_400_for_invalid_uuid(): void
    {
        $response = $this->getJson('/api/v1/menus/not-a-uuid');

        $response->assertStatus(400);
    }

    public function test_can_update_menu(): void
    {
        $menu = $this->createMenu(['name' => 'Old Name']);

        $response = $this->patchJson("/api/v1/menus/{$menu->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('menus', ['id' => $menu->id, 'name' => 'New Name']);
    }

    public function test_can_delete_non_default_menu(): void
    {
        $menu = $this->createMenu(['is_default' => false]);

        $response = $this->deleteJson("/api/v1/menus/{$menu->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
    }

    public function test_cannot_delete_default_menu(): void
    {
        $menu = $this->createMenu(['is_default' => true]);

        $response = $this->deleteJson("/api/v1/menus/{$menu->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cannot delete the default menu');
    }

    // ── Default menu enforcement ───────────────────────────────────────

    public function test_creating_default_menu_unsets_previous_default(): void
    {
        $first = $this->createMenu(['name' => 'First Default', 'is_default' => true]);

        $this->postJson('/api/v1/menus', [
            'name' => 'Second Default',
            'is_default' => true,
        ]);

        $first->refresh();
        $this->assertFalse($first->is_default);
        $this->assertDatabaseHas('menus', ['name' => 'Second Default', 'is_default' => true]);
    }

    public function test_updating_menu_to_default_unsets_previous_default(): void
    {
        $default = $this->createMenu(['name' => 'Current Default', 'is_default' => true]);
        $other = $this->createMenu(['name' => 'Other Menu', 'is_default' => false]);

        $this->patchJson("/api/v1/menus/{$other->id}", ['is_default' => true]);

        $default->refresh();
        $this->assertFalse($default->is_default);
    }

    // ── Category CRUD ──────────────────────────────────────────────────

    public function test_can_create_category(): void
    {
        $menu = $this->createMenu();

        $response = $this->postJson("/api/v1/menus/{$menu->id}/categories", [
            'name' => 'Hot Drinks',
            'description' => 'Coffee, tea, etc.',
            'display_order' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Hot Drinks');
        $this->assertDatabaseHas('menu_categories', ['menu_id' => $menu->id, 'name' => 'Hot Drinks']);
    }

    public function test_can_update_category(): void
    {
        $menu = $this->createMenu();
        $category = $menu->categories()->create(['name' => 'Old Name']);

        $response = $this->patchJson("/api/v1/menu-categories/{$category->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
    }

    public function test_can_delete_category(): void
    {
        $menu = $this->createMenu();
        $category = $menu->categories()->create(['name' => 'To Delete']);

        $response = $this->deleteJson("/api/v1/menu-categories/{$category->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('menu_categories', ['id' => $category->id]);
    }

    // ── Category items management ──────────────────────────────────────

    public function test_can_add_item_to_category(): void
    {
        $menu = $this->createMenu();
        $category = $menu->categories()->create(['name' => 'Drinks']);
        $item = $this->createCompositeItem(['name' => 'Cappuccino']);

        $response = $this->postJson("/api/v1/menu-categories/{$category->id}/items", [
            'composite_item_id' => $item->id,
            'override_price' => '4.50',
            'is_available' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('menu_category_items', [
            'menu_category_id' => $category->id,
            'composite_item_id' => $item->id,
        ]);
    }

    public function test_can_remove_item_from_category(): void
    {
        $menu = $this->createMenu();
        $category = $menu->categories()->create(['name' => 'Drinks']);
        $item = $this->createCompositeItem();

        // Add via API so the pivot row gets a proper UUID id
        $this->postJson("/api/v1/menu-categories/{$category->id}/items", [
            'composite_item_id' => $item->id,
        ]);

        $response = $this->deleteJson("/api/v1/menu-categories/{$category->id}/items/{$item->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('menu_category_items', [
            'menu_category_id' => $category->id,
            'composite_item_id' => $item->id,
        ]);
    }

    public function test_can_sync_category_items(): void
    {
        $menu = $this->createMenu();
        $category = $menu->categories()->create(['name' => 'Drinks']);
        $item1 = $this->createCompositeItem(['name' => 'Espresso']);
        $item2 = $this->createCompositeItem(['name' => 'Latte']);
        $item3 = $this->createCompositeItem(['name' => 'Americano']);

        // Add item1 via API
        $this->postJson("/api/v1/menu-categories/{$category->id}/items", [
            'composite_item_id' => $item1->id,
        ]);

        // Sync with item2 and item3 (removes item1)
        $response = $this->putJson("/api/v1/menu-categories/{$category->id}/items", [
            'items' => [
                ['composite_item_id' => $item2->id, 'override_price' => '3.00', 'is_available' => true],
                ['composite_item_id' => $item3->id, 'override_price' => null, 'is_available' => false],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseMissing('menu_category_items', ['composite_item_id' => $item1->id]);
        $this->assertDatabaseHas('menu_category_items', ['composite_item_id' => $item2->id]);
        $this->assertDatabaseHas('menu_category_items', ['composite_item_id' => $item3->id]);
    }

    // ── Permission enforcement ─────────────────────────────────────────

    public function test_unauthorized_user_cannot_create_menu(): void
    {
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        Sanctum::actingAs($unprivileged);

        $response = $this->postJson('/api/v1/menus', [
            'name' => 'Should Fail',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_create_category(): void
    {
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        Sanctum::actingAs($unprivileged);

        $menu = $this->createMenu();

        $response = $this->postJson("/api/v1/menus/{$menu->id}/categories", [
            'name' => 'Should Fail',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_add_item(): void
    {
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        Sanctum::actingAs($unprivileged);

        $menu = $this->createMenu();
        $category = $menu->categories()->create(['name' => 'Drinks']);
        $item = $this->createCompositeItem();

        $response = $this->postJson("/api/v1/menu-categories/{$category->id}/items", [
            'composite_item_id' => $item->id,
        ]);

        $response->assertStatus(403);
    }

    // ── Validation ─────────────────────────────────────────────────────

    public function test_create_menu_requires_name(): void
    {
        $response = $this->postJson('/api/v1/menus', []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('error.errors'));
    }

    public function test_active_until_must_be_after_active_from(): void
    {
        $response = $this->postJson('/api/v1/menus', [
            'name' => 'Invalid Time',
            'active_from' => '14:00',
            'active_until' => '10:00',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('active_until', $response->json('error.errors'));
    }

    public function test_available_days_must_be_1_to_7(): void
    {
        $response = $this->postJson('/api/v1/menus', [
            'name' => 'Invalid Days',
            'available_days' => [0, 8],
        ]);

        $response->assertStatus(422);
    }

    public function test_create_category_requires_name(): void
    {
        $menu = $this->createMenu();

        $response = $this->postJson("/api/v1/menus/{$menu->id}/categories", []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('error.errors'));
    }

    public function test_add_item_requires_valid_composite_item_id(): void
    {
        $menu = $this->createMenu();
        $category = $menu->categories()->create(['name' => 'Drinks']);

        $response = $this->postJson("/api/v1/menu-categories/{$category->id}/items", [
            'composite_item_id' => 'not-a-uuid',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('composite_item_id', $response->json('error.errors'));
    }
}
