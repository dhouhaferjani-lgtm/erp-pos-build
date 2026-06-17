<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\Catalog\ProductVariantFactory;
use Database\Factories\ProductFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature coverage for Task B2 — attribute_values exposed in variant DTO
 * with eager-loading (no N+1).
 */
class ProductVariantDtoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo(['catalog.variants.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    public function test_list_variants_includes_attribute_values_in_dto(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $attr1 = ProductAttributeFactory::new()->create(['tenant_id' => $this->tenant->id, 'code' => 'size']);
        $attr2 = ProductAttributeFactory::new()->create(['tenant_id' => $this->tenant->id, 'code' => 'color']);

        // 3 variants, each with 2 junction rows
        for ($i = 1; $i <= 3; $i++) {
            $variant = ProductVariantFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'product_id' => $product->id,
                'variant_code' => "V-{$i}",
                'sku' => "SKU-{$i}",
            ]);

            $val1 = ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'attribute_id' => $attr1->id,
                'code' => "s-{$i}",
            ]);
            $val2 = ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'attribute_id' => $attr2->id,
                'code' => "c-{$i}",
            ]);

            ProductVariantAttributeValue::create([
                'variant_id' => $variant->id,
                'attribute_id' => $attr1->id,
                'attribute_value_id' => $val1->id,
            ]);
            ProductVariantAttributeValue::create([
                'variant_id' => $variant->id,
                'attribute_id' => $attr2->id,
                'attribute_value_id' => $val2->id,
            ]);
        }

        DB::enableQueryLog();

        $resp = $this->getJson("/api/v1/products/{$product->id}/variants");

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $resp->assertOk();
        $resp->assertJsonCount(3, 'data');

        // Each variant must carry its attribute_values
        $resp->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'attribute_values' => [
                        '*' => ['attribute_id', 'attribute_value_id'],
                    ],
                ],
            ],
        ]);

        // First variant must have exactly 2 attribute_value entries
        $first = $resp->json('data.0');
        $this->assertCount(2, $first['attribute_values']);

        // No N+1: total queries must not scale with variant count.
        // Budget accounts for: middleware auth, permission check, company context,
        // variants query (1), attributeValues eager-load (1). Cap at 10 — if N+1
        // existed, 3 variants would add 3 extra attributeValues queries bringing
        // the total to 13+, which would fail this guard.
        $this->assertLessThanOrEqual(10, $queryCount, "Expected ≤10 queries but got {$queryCount} (possible N+1)");
    }
}
