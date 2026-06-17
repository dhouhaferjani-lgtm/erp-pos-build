<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\CompositeItemAvailabilityService;
use App\Modules\Catalog\Application\Services\RecipeCostCalculationService;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\Recipe;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for the F&B Combo / Fixed Bundle feature.
 *
 * Covers:
 * - Creating CompositeItems with pricing_mode=fixed_bundle
 * - Adding CompositeItem components (component_type=composite_item)
 * - Circular reference prevention (MAX_RECURSION_DEPTH guard)
 * - Cost calculation with nested CompositeItems
 * - Availability check with nested CompositeItems
 */
class CompositeItemComboTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private RecipeCostCalculationService $costService;

    private CompositeItemAvailabilityService $availabilityService;

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
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->costService = app(RecipeCostCalculationService::class);
        $this->availabilityService = app(CompositeItemAvailabilityService::class);

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_create_a_composite_item_with_fixed_bundle_pricing_mode(): void
    {
        $response = $this->postJson('/api/v1/composite-items', [
            'code' => 'COMBO-MEAL',
            'name' => 'Combo Meal',
            'base_price' => 12.50,
            'vertical_type' => 'fnb',
            'production_type' => 'made_to_order',
            'pricing_mode' => 'fixed_bundle',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.pricing_mode', 'fixed_bundle');

        $this->assertDatabaseHas('composite_items', [
            'code' => 'COMBO-MEAL',
            'pricing_mode' => 'fixed_bundle',
            'company_id' => $this->company->id,
        ]);
    }

    /** @test */
    public function it_defaults_pricing_mode_to_standard(): void
    {
        $response = $this->postJson('/api/v1/composite-items', [
            'code' => 'STANDARD-ITEM',
            'name' => 'Standard Item',
            'base_price' => 5.00,
            'vertical_type' => 'fnb',
            'production_type' => 'made_to_order',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.pricing_mode', 'standard');
    }

    /** @test */
    public function it_can_update_pricing_mode_from_standard_to_fixed_bundle(): void
    {
        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'UPDATE-MODE',
            'name' => 'Update Mode Item',
            'base_price' => 10.00,
            'pricing_mode' => PricingMode::Standard,
        ]);

        $response = $this->patchJson("/api/v1/composite-items/{$item->id}", [
            'pricing_mode' => 'fixed_bundle',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.pricing_mode', 'fixed_bundle');

        $item->refresh();
        $this->assertEquals(PricingMode::FixedBundle, $item->pricing_mode);
    }

    /** @test */
    public function it_rejects_invalid_pricing_mode(): void
    {
        $response = $this->postJson('/api/v1/composite-items', [
            'code' => 'BAD-MODE',
            'name' => 'Bad Mode Item',
            'base_price' => 5.00,
            'pricing_mode' => 'invalid_mode',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_can_add_a_composite_item_as_a_recipe_component(): void
    {
        // Create sub-item (Espresso)
        $espresso = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ESPRESSO',
            'name' => 'Espresso',
            'base_price' => 3.00,
            'tax_rate' => 10.00,
        ]);

        // Create combo item
        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'COMBO-1',
            'name' => 'Breakfast Combo',
            'base_price' => 12.00,
            'pricing_mode' => PricingMode::FixedBundle,
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/recipes/{$recipe->id}/lines", [
            'component_type' => 'composite_item',
            'component_id' => $espresso->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.component_type', 'composite_item');
        $response->assertJsonPath('data.component_name', 'Espresso');

        $this->assertDatabaseHas('recipe_lines', [
            'recipe_id' => $recipe->id,
            'component_type' => 'composite_item',
            'component_id' => $espresso->id,
        ]);
    }

    /** @test */
    public function it_calculates_cost_with_nested_composite_item_components(): void
    {
        // Create leaf products
        $coffee = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Coffee Beans',
            'cost_price' => '0.5000',
        ]);

        $milk = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Milk',
            'cost_price' => '0.3000',
        ]);

        $bread = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Bread',
            'cost_price' => '1.0000',
        ]);

        // Create sub-item: Latte (coffee + milk)
        $latte = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'LATTE',
            'name' => 'Latte',
            'base_price' => 5.00,
        ]);

        $latteRecipe = Recipe::create([
            'composite_item_id' => $latte->id,
            'version' => 1,
            'is_active' => true,
        ]);

        RecipeLine::create([
            'recipe_id' => $latteRecipe->id,
            'component_type' => 'product',
            'component_id' => $coffee->id,
            'quantity' => 2, // 2 units of coffee
        ]);

        RecipeLine::create([
            'recipe_id' => $latteRecipe->id,
            'component_type' => 'product',
            'component_id' => $milk->id,
            'quantity' => 1,
        ]);

        // Create combo: Breakfast = 1 Latte + 1 Bread
        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BREAKFAST',
            'name' => 'Breakfast Combo',
            'base_price' => 8.00,
            'pricing_mode' => PricingMode::FixedBundle,
        ]);

        $comboRecipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        RecipeLine::create([
            'recipe_id' => $comboRecipe->id,
            'component_type' => 'composite_item',
            'component_id' => $latte->id,
            'quantity' => 1,
        ]);

        RecipeLine::create([
            'recipe_id' => $comboRecipe->id,
            'component_type' => 'product',
            'component_id' => $bread->id,
            'quantity' => 1,
        ]);

        // Act
        $costData = $this->costService->calculate($comboRecipe);

        // Latte cost = 2 * 0.50 + 1 * 0.30 = 1.30
        // Bread cost = 1.00
        // Total = 2.30
        $this->assertEquals('2.3000', $costData->total_cost);
        $this->assertCount(2, $costData->lines);
    }

    /** @test */
    public function it_checks_availability_with_nested_composite_items(): void
    {
        $location = Location::factory()->for($this->company)->create();

        // Create leaf products with stock
        $coffee = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Coffee Beans',
            'cost_price' => '0.5000',
        ]);

        $milk = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Milk',
            'cost_price' => '0.3000',
        ]);

        $bread = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Bread',
            'cost_price' => '1.0000',
        ]);

        // Stock: 10 coffee, 5 milk, 3 bread
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $coffee->id,
            'location_id' => $location->id,
            'quantity' => '10.0000',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $milk->id,
            'location_id' => $location->id,
            'quantity' => '5.0000',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $bread->id,
            'location_id' => $location->id,
            'quantity' => '3.0000',
        ]);

        // Create sub-item: Latte = 2 coffee + 1 milk
        $latte = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'LATTE-AVAIL',
            'name' => 'Latte',
            'base_price' => 5.00,
        ]);

        $latteRecipe = Recipe::create([
            'composite_item_id' => $latte->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $latte->update(['default_recipe_id' => $latteRecipe->id]);

        RecipeLine::create([
            'recipe_id' => $latteRecipe->id,
            'component_type' => 'product',
            'component_id' => $coffee->id,
            'quantity' => 2,
        ]);

        RecipeLine::create([
            'recipe_id' => $latteRecipe->id,
            'component_type' => 'product',
            'component_id' => $milk->id,
            'quantity' => 1,
        ]);

        // Create combo: Breakfast = 1 Latte + 1 Bread
        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BREAKFAST-AVAIL',
            'name' => 'Breakfast Combo',
            'base_price' => 8.00,
            'pricing_mode' => PricingMode::FixedBundle,
        ]);

        $comboRecipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $comboRecipe->id]);

        RecipeLine::create([
            'recipe_id' => $comboRecipe->id,
            'component_type' => 'composite_item',
            'component_id' => $latte->id,
            'quantity' => 1,
        ]);

        RecipeLine::create([
            'recipe_id' => $comboRecipe->id,
            'component_type' => 'product',
            'component_id' => $bread->id,
            'quantity' => 1,
        ]);

        // Act
        $availability = $this->availabilityService->checkAvailability($combo, $location->id);

        // Combo needs: 2 coffee (from latte) + 1 milk (from latte) + 1 bread
        // Coffee: 10 / 2 = 5
        // Milk: 5 / 1 = 5
        // Bread: 3 / 1 = 3 (limiting)
        // Max producible = 3
        $this->assertEquals(3, $availability['available_quantity']);
        $this->assertEquals('Bread', $availability['limiting_component']);
        $this->assertCount(3, $availability['components']);
    }

    /** @test */
    public function it_returns_availability_via_api_with_nested_components(): void
    {
        $location = Location::factory()->for($this->company)->create();

        $coffee = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Coffee',
            'cost_price' => '0.5000',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $coffee->id,
            'location_id' => $location->id,
            'quantity' => '10.0000',
        ]);

        $latte = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'LATTE-API',
            'name' => 'Latte API',
            'base_price' => 5.00,
        ]);

        $latteRecipe = Recipe::create([
            'composite_item_id' => $latte->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $latte->update(['default_recipe_id' => $latteRecipe->id]);

        RecipeLine::create([
            'recipe_id' => $latteRecipe->id,
            'component_type' => 'product',
            'component_id' => $coffee->id,
            'quantity' => 2,
        ]);

        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'COMBO-API',
            'name' => 'Combo API',
            'base_price' => 8.00,
            'pricing_mode' => PricingMode::FixedBundle,
        ]);

        $comboRecipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $combo->update(['default_recipe_id' => $comboRecipe->id]);

        RecipeLine::create([
            'recipe_id' => $comboRecipe->id,
            'component_type' => 'composite_item',
            'component_id' => $latte->id,
            'quantity' => 1,
        ]);

        $response = $this->getJson("/api/v1/composite-items/{$combo->id}/availability?location_id={$location->id}");

        $response->assertOk();
        $response->assertJsonPath('data.available_quantity', 5); // 10 coffee / 2 per latte
    }

    /** @test */
    public function it_handles_deeply_nested_composite_items_without_infinite_loop(): void
    {
        // Create a chain of composite items that doesn't loop but is deep
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Base Product',
            'cost_price' => '1.0000',
        ]);

        $items = [];
        $previousItem = null;

        // Create 5 levels of nesting
        for ($i = 0; $i < 5; $i++) {
            $item = CompositeItem::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => "DEEP-{$i}",
                'name' => "Deep Item {$i}",
                'base_price' => 10.00,
            ]);

            $recipe = Recipe::create([
                'composite_item_id' => $item->id,
                'version' => 1,
                'is_active' => true,
            ]);

            $item->update(['default_recipe_id' => $recipe->id]);

            if ($previousItem !== null) {
                RecipeLine::create([
                    'recipe_id' => $recipe->id,
                    'component_type' => 'composite_item',
                    'component_id' => $previousItem->id,
                    'quantity' => 1,
                ]);
            } else {
                RecipeLine::create([
                    'recipe_id' => $recipe->id,
                    'component_type' => 'product',
                    'component_id' => $product->id,
                    'quantity' => 1,
                ]);
            }

            $items[] = $item;
            $previousItem = $item;
        }

        // Calculate cost on the deepest item
        $topItem = end($items);
        $topRecipe = $topItem->activeRecipe;

        $costData = $this->costService->calculate($topRecipe);

        // Cost should cascade down to the base product at 1.00
        $this->assertEquals('1.0000', $costData->total_cost);
    }

    /** @test */
    public function it_returns_recipe_line_data_with_component_type_and_name_for_composite_items(): void
    {
        $subItem = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SUB-ITEM',
            'name' => 'Sub Item',
            'base_price' => 5.00,
        ]);

        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'COMBO-DTO',
            'name' => 'Combo DTO Test',
            'base_price' => 10.00,
            'pricing_mode' => PricingMode::FixedBundle,
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        $this->postJson("/api/v1/recipes/{$recipe->id}/lines", [
            'component_type' => 'composite_item',
            'component_id' => $subItem->id,
            'quantity' => 1,
        ]);

        // Fetch the recipe with lines to verify DTO serialization
        $response = $this->getJson("/api/v1/recipes/{$recipe->id}");

        $response->assertOk();
        $response->assertJsonPath('data.lines.0.component_type', 'composite_item');
        $response->assertJsonPath('data.lines.0.component_name', 'Sub Item');
        $response->assertJsonPath('data.lines.0.component_sku', 'SUB-ITEM');
    }

    /** @test */
    public function it_rejects_direct_circular_reference(): void
    {
        $combo = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SELF-REF',
            'name' => 'Self Reference',
            'base_price' => 10.00,
            'pricing_mode' => PricingMode::FixedBundle,
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $combo->id,
            'version' => 1,
            'is_active' => true,
        ]);

        // Try to add the item as its own component — should be rejected
        $response = $this->postJson("/api/v1/recipes/{$recipe->id}/lines", [
            'component_type' => 'composite_item',
            'component_id' => $combo->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.component_id.0', __('catalog.circular_reference'));
    }

    /** @test */
    public function it_rejects_indirect_circular_reference(): void
    {
        // A -> B -> C, then try to add A as component of C
        $itemA = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-A',
            'name' => 'Item A',
            'base_price' => 5.00,
        ]);

        $itemB = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-B',
            'name' => 'Item B',
            'base_price' => 8.00,
        ]);

        $itemC = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'ITEM-C',
            'name' => 'Item C',
            'base_price' => 12.00,
            'pricing_mode' => PricingMode::FixedBundle,
        ]);

        // A has a recipe with a product (leaf)
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Leaf Product',
            'cost_price' => '1.0000',
        ]);

        $recipeA = Recipe::create([
            'composite_item_id' => $itemA->id,
            'version' => 1,
            'is_active' => true,
        ]);
        $itemA->update(['default_recipe_id' => $recipeA->id]);

        RecipeLine::create([
            'recipe_id' => $recipeA->id,
            'component_type' => 'product',
            'component_id' => $product->id,
            'quantity' => 1,
        ]);

        // B's recipe contains A
        $recipeB = Recipe::create([
            'composite_item_id' => $itemB->id,
            'version' => 1,
            'is_active' => true,
        ]);
        $itemB->update(['default_recipe_id' => $recipeB->id]);

        RecipeLine::create([
            'recipe_id' => $recipeB->id,
            'component_type' => 'composite_item',
            'component_id' => $itemA->id,
            'quantity' => 1,
        ]);

        // C's recipe contains B
        $recipeC = Recipe::create([
            'composite_item_id' => $itemC->id,
            'version' => 1,
            'is_active' => true,
        ]);
        $itemC->update(['default_recipe_id' => $recipeC->id]);

        RecipeLine::create([
            'recipe_id' => $recipeC->id,
            'component_type' => 'composite_item',
            'component_id' => $itemB->id,
            'quantity' => 1,
        ]);

        // Now try to add C as a component of A — this creates A -> C -> B -> A (cycle)
        $response = $this->postJson("/api/v1/recipes/{$recipeA->id}/lines", [
            'component_type' => 'composite_item',
            'component_id' => $itemC->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.component_id.0', __('catalog.circular_reference'));
    }

    /** @test */
    public function it_rejects_circular_reference_on_update(): void
    {
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Placeholder',
            'cost_price' => '1.0000',
        ]);

        $itemA = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CIRC-A',
            'name' => 'Circ A',
            'base_price' => 5.00,
        ]);

        $itemB = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CIRC-B',
            'name' => 'Circ B',
            'base_price' => 8.00,
        ]);

        // A's recipe contains B
        $recipeA = Recipe::create([
            'composite_item_id' => $itemA->id,
            'version' => 1,
            'is_active' => true,
        ]);
        $itemA->update(['default_recipe_id' => $recipeA->id]);

        RecipeLine::create([
            'recipe_id' => $recipeA->id,
            'component_type' => 'composite_item',
            'component_id' => $itemB->id,
            'quantity' => 1,
        ]);

        // B's recipe has a product line
        $recipeB = Recipe::create([
            'composite_item_id' => $itemB->id,
            'version' => 1,
            'is_active' => true,
        ]);
        $itemB->update(['default_recipe_id' => $recipeB->id]);

        $line = RecipeLine::create([
            'recipe_id' => $recipeB->id,
            'component_type' => 'product',
            'component_id' => $product->id,
            'quantity' => 1,
        ]);

        // Try to update B's line to reference A (creating B -> A -> B cycle)
        $response = $this->patchJson("/api/v1/recipes/{$recipeB->id}/lines/{$line->id}", [
            'component_type' => 'composite_item',
            'component_id' => $itemA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.component_id.0', __('catalog.circular_reference'));
    }
}
