<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\Services\RecipeCostCalculationService;
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

class RecipeCostTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private RecipeCostCalculationService $costService;

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

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_calculates_recipe_cost_from_component_cost_prices(): void
    {
        // Arrange: Create products with known cost_price
        $espresso = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Espresso Shot',
            'cost_price' => '0.5000',
        ]);

        $milk = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Steamed Milk',
            'cost_price' => '0.2000',
        ]);

        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CAPPUCCINO',
            'name' => 'Cappuccino',
            'base_price' => 5.00,
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $item->id,
            'version' => 1,
            'is_active' => true,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $espresso->id,
            'quantity' => 2, // 2 shots
            'wastage_percent' => 0,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $milk->id,
            'quantity' => 1,
            'wastage_percent' => 0,
        ]);

        // Act
        $costData = $this->costService->calculate($recipe);

        // Assert: 2 * 0.50 + 1 * 0.20 = 1.20
        $this->assertEquals('1.2000', $costData->total_cost);
        $this->assertCount(2, $costData->lines);
        $this->assertEquals('1.0000', $costData->lines[0]['line_cost']); // espresso
        $this->assertEquals('0.2000', $costData->lines[1]['line_cost']); // milk

        // Verify recipe model was updated
        $recipe->refresh();
        $this->assertEquals('1.2000', $recipe->calculated_cost);
    }

    /** @test */
    public function it_applies_wastage_percent_to_cost_calculation(): void
    {
        $flour = Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => 'Flour',
            'cost_price' => '1.0000',
        ]);

        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BREAD',
            'name' => 'Bread',
            'base_price' => 3.00,
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $item->id,
            'version' => 1,
            'is_active' => true,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $flour->id,
            'quantity' => 1,
            'wastage_percent' => 10, // 10% wastage
        ]);

        // Act
        $costData = $this->costService->calculate($recipe);

        // Assert: 1 * (1 + 0.10) * 1.00 = 1.10
        $this->assertEquals('1.1000', $costData->total_cost);
    }

    /** @test */
    public function it_calculates_cost_via_api(): void
    {
        $product = Product::factory()->for($this->tenant)->for($this->company)->create([
            'cost_price' => '2.0000',
        ]);

        $item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'TEST-COST-API',
            'name' => 'Cost API Test',
            'base_price' => 10.00,
        ]);

        $recipe = Recipe::create([
            'composite_item_id' => $item->id,
            'version' => 1,
            'is_active' => true,
        ]);

        RecipeLine::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'product',
            'component_id' => $product->id,
            'quantity' => 3,
            'wastage_percent' => 0,
        ]);

        $response = $this->postJson("/api/v1/recipes/{$recipe->id}/calculate-cost");

        $response->assertOk();
        $response->assertJsonPath('data.total_cost', '6.0000');
    }
}
