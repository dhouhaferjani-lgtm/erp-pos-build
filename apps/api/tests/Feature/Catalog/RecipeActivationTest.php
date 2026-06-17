<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecipeActivationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private CompositeItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['enabled_extras' => ['CompositeItems']]);
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'composite-items.view',
            'composite-items.create',
            'composite-items.update',
            'composite-items.delete',
            'composite-items.manage-recipes',
            'modifier-groups.view',
            'modifier-groups.manage',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);

        $this->item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'TEST-ITEM',
            'name' => 'Test Item',
            'base_price' => 10.00,
        ]);
    }

    /** @test */
    public function it_can_create_a_recipe(): void
    {
        $response = $this->postJson("/api/v1/composite-items/{$this->item->id}/recipes", [
            'version_name' => 'Original Recipe',
            'yield_quantity' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.version', 1);
        $response->assertJsonPath('data.version_name', 'Original Recipe');
        $response->assertJsonPath('data.is_active', true);
    }

    /** @test */
    public function it_activates_a_recipe_and_deactivates_others(): void
    {
        $recipe1 = Recipe::create([
            'composite_item_id' => $this->item->id,
            'version' => 1,
            'version_name' => 'Version 1',
            'is_active' => true,
        ]);

        $recipe2 = Recipe::create([
            'composite_item_id' => $this->item->id,
            'version' => 2,
            'version_name' => 'Version 2',
            'is_active' => false,
        ]);

        // Activate recipe 2
        $response = $this->postJson("/api/v1/recipes/{$recipe2->id}/activate");

        $response->assertOk();
        $response->assertJsonPath('data.is_active', true);

        // Verify recipe 1 is now inactive
        $recipe1->refresh();
        $this->assertFalse($recipe1->is_active);

        // Verify default_recipe_id was updated
        $this->item->refresh();
        $this->assertEquals($recipe2->id, $this->item->default_recipe_id);
    }

    /** @test */
    public function it_auto_increments_recipe_version(): void
    {
        Recipe::create([
            'composite_item_id' => $this->item->id,
            'version' => 1,
        ]);

        $response = $this->postJson("/api/v1/composite-items/{$this->item->id}/recipes", [
            'version_name' => 'New Version',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.version', 2);
    }

    /** @test */
    public function it_can_add_recipe_lines(): void
    {
        $recipe = Recipe::create([
            'composite_item_id' => $this->item->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $product = Product::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create();

        $response = $this->postJson("/api/v1/recipes/{$recipe->id}/lines", [
            'component_id' => $product->id,
            'quantity' => 2.5,
            'wastage_percent' => 5,
            'is_optional' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.quantity', '2.5000');
        $response->assertJsonPath('data.wastage_percent', '5.00');
    }

    /** @test */
    public function it_can_update_and_delete_recipe_lines(): void
    {
        $recipe = Recipe::create([
            'composite_item_id' => $this->item->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $product = Product::factory()
            ->for($this->tenant)
            ->for($this->company)
            ->create();

        $line = RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $product->id,
            'quantity' => 1,
            'wastage_percent' => 0,
        ]);

        // Update
        $updateResponse = $this->patchJson("/api/v1/recipes/{$recipe->id}/lines/{$line->id}", [
            'quantity' => 3,
        ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.quantity', '3.0000');

        // Delete
        $deleteResponse = $this->deleteJson("/api/v1/recipes/{$recipe->id}/lines/{$line->id}");
        $deleteResponse->assertNoContent();

        $this->assertDatabaseMissing('recipe_lines', ['id' => $line->id]);
    }

    /** @test */
    public function it_can_create_and_manage_variants(): void
    {
        // Create
        $response = $this->postJson("/api/v1/composite-items/{$this->item->id}/variants", [
            'code' => 'SMALL',
            'name' => 'Small',
            'price_adjustment_type' => 'absolute',
            'price_adjustment' => -1.50,
            'recipe_multiplier' => 0.75,
            'is_default' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'SMALL');

        $variantId = $response->json('data.id');

        // Update
        $updateResponse = $this->patchJson("/api/v1/variants/{$variantId}", [
            'name' => 'Small Size',
            'is_default' => true,
        ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Small Size');
        $updateResponse->assertJsonPath('data.is_default', true);

        // Delete
        $deleteResponse = $this->deleteJson("/api/v1/variants/{$variantId}");
        $deleteResponse->assertNoContent();
    }

    /** @test */
    public function it_can_manage_modifier_groups(): void
    {
        // Create modifier group
        $groupResponse = $this->postJson('/api/v1/modifier-groups', [
            'code' => 'MILK-TYPE',
            'name' => 'Milk Type',
            'selection_type' => 'single',
            'min_selections' => 1,
            'max_selections' => 1,
            'is_required' => true,
        ]);
        $groupResponse->assertStatus(201);
        $groupId = $groupResponse->json('data.id');

        // Add modifier to group
        $modifierResponse = $this->postJson("/api/v1/modifier-groups/{$groupId}/modifiers", [
            'code' => 'OAT-MILK',
            'name' => 'Oat Milk',
            'price_adjustment' => 0.50,
        ]);
        $modifierResponse->assertStatus(201);

        // Assign group to composite item
        $assignResponse = $this->postJson("/api/v1/composite-items/{$this->item->id}/modifier-groups", [
            'modifier_group_id' => $groupId,
        ]);
        $assignResponse->assertOk();

        // Verify assignment
        $this->assertTrue($this->item->modifierGroups()->where('modifier_groups.id', $groupId)->exists());

        // Remove assignment
        $removeResponse = $this->deleteJson("/api/v1/composite-items/{$this->item->id}/modifier-groups/{$groupId}");
        $removeResponse->assertNoContent();
        $this->assertFalse($this->item->modifierGroups()->where('modifier_groups.id', $groupId)->exists());
    }
}
