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
use Database\Factories\Catalog\ProductVariantFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecipeLineComponentVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private CompositeItem $item;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
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

        $this->actingAs($this->user);

        $this->item = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'TEST-RECIPE-VAR',
            'name' => 'Test Recipe Variant Item',
            'base_price' => 10.00,
        ]);

        $this->recipe = Recipe::create([
            'composite_item_id' => $this->item->id,
            'version' => 1,
            'is_active' => true,
        ]);
    }

    public function test_recipe_line_can_reference_variant_when_component_is_product(): void
    {
        $product = Product::factory()->for($this->tenant)->for($this->company)->create();

        $variant = ProductVariantFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-RED',
            'sku' => 'SKU-RED-001',
        ]);

        $line = RecipeLine::create([
            'recipe_id' => $this->recipe->id,
            'component_type' => 'product',
            'component_id' => $product->id,
            'component_variant_id' => $variant->id,
            'quantity' => 1,
            'wastage_percent' => 0,
        ]);

        $this->assertNotNull($line->id);
        $this->assertSame($variant->id, $line->component_variant_id);

        // Verify round-trip from DB
        $line->refresh();
        $this->assertSame($variant->id, $line->component_variant_id);
    }

    public function test_recipe_line_rejects_variant_when_component_is_composite(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint enforcement requires PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        $composite = CompositeItem::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SUB-COMPOSITE',
            'name' => 'Sub Composite',
            'base_price' => 5.00,
        ]);

        // Create a product + variant to supply a valid FK reference;
        // the constraint violation is the CHECK, not the FK.
        $product = Product::factory()->for($this->tenant)->for($this->company)->create();
        $variant = ProductVariantFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-CHECK',
            'sku' => 'SKU-CHECK-001',
        ]);

        // component_type = 'composite_item' with a non-null component_variant_id
        // must violate recipe_lines_variant_requires_product CHECK.
        RecipeLine::create([
            'recipe_id' => $this->recipe->id,
            'component_type' => 'composite_item',
            'component_id' => $composite->id,
            'component_variant_id' => $variant->id,
            'quantity' => 1,
            'wastage_percent' => 0,
        ]);
    }
}
