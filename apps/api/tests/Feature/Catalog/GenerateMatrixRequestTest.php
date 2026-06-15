<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\ProductFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Request-layer validation tests for Task A1.
 *
 * Covers: axes value-subset shape, gross-cap 422, value-id ownership,
 * and backwards-compat legacy attribute_ids shape.
 */
class GenerateMatrixRequestTest extends TestCase
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
        $this->company = Company::factory()->for($this->tenant)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'catalog.variants.view',
            'catalog.variants.create',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    /**
     * A valid axes payload with a value subset (2 out of 3 values) must pass
     * validation. A1 owns request validation only; controller/service wiring is
     * A4's job, so we only assert the response is NOT a 422.
     */
    public function test_accepts_axes_with_value_subset(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $attr = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'colour',
            'is_variant_axis' => true,
        ]);

        $v1 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attr->id,
            'code' => 'red',
            'label' => 'Red',
        ]);
        $v2 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attr->id,
            'code' => 'blue',
            'label' => 'Blue',
        ]);
        // Third value — intentionally NOT included in the request subset.
        ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attr->id,
            'code' => 'green',
            'label' => 'Green',
        ]);

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'axes' => [
                ['attribute_id' => $attr->id, 'value_ids' => [$v1->id, $v2->id]],
            ],
        ]);

        // A1 only owns request validation; A4 wires the response. Assert validation passed.
        $this->assertNotSame(422, $resp->status());
    }

    /**
     * Two axes whose Cartesian product exceeds 200 must be rejected with 422
     * and a validation error on the 'combinations' key.
     */
    public function test_rejects_gross_product_over_cap_with_422(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Build axis A with 15 values.
        $attrA = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'size',
            'is_variant_axis' => true,
        ]);
        $valueIdsA = [];
        for ($i = 1; $i <= 15; $i++) {
            $v = ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'attribute_id' => $attrA->id,
                'code' => "s{$i}",
                'label' => "Size {$i}",
            ]);
            $valueIdsA[] = $v->id;
        }

        // Build axis B with 14 values — 15 × 14 = 210 > 200.
        $attrB = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'material',
            'is_variant_axis' => true,
        ]);
        $valueIdsB = [];
        for ($i = 1; $i <= 14; $i++) {
            $v = ProductAttributeValueFactory::new()->create([
                'tenant_id' => $this->tenant->id,
                'attribute_id' => $attrB->id,
                'code' => "m{$i}",
                'label' => "Material {$i}",
            ]);
            $valueIdsB[] = $v->id;
        }

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'axes' => [
                ['attribute_id' => $attrA->id, 'value_ids' => $valueIdsA],
                ['attribute_id' => $attrB->id, 'value_ids' => $valueIdsB],
            ],
        ]);

        $resp->assertStatus(422);
        $this->assertApiValidationErrors($resp, ['combinations']);
    }

    /**
     * A value_id that belongs to a different attribute must be rejected with 422.
     */
    public function test_rejects_value_id_not_belonging_to_attribute(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $attrA = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'attr-a',
            'is_variant_axis' => true,
        ]);
        $attrB = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'attr-b',
            'is_variant_axis' => true,
        ]);

        // Value that belongs to attrB — passed in attrA's axis.
        $valueFromB = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attrB->id,
            'code' => 'foreign',
            'label' => 'Foreign',
        ]);

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'axes' => [
                ['attribute_id' => $attrA->id, 'value_ids' => [$valueFromB->id]],
            ],
        ]);

        $resp->assertStatus(422);
        $this->assertApiValidationErrors($resp, ['axes.0.value_ids']);
    }

    /**
     * Two axes referencing the SAME attribute_id must be rejected with 422 and a
     * validation error on the 'axes' key — each attribute may appear only once.
     */
    public function test_rejects_duplicate_attribute_axis(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $attr = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'dup-attr',
            'is_variant_axis' => true,
        ]);

        $v1 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attr->id,
            'code' => 'a',
            'label' => 'A',
        ]);
        $v2 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attr->id,
            'code' => 'b',
            'label' => 'B',
        ]);

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'axes' => [
                ['attribute_id' => $attr->id, 'value_ids' => [$v1->id]],
                ['attribute_id' => $attr->id, 'value_ids' => [$v2->id]],
            ],
        ]);

        $resp->assertStatus(422);
        $this->assertApiValidationErrors($resp, ['axes']);
    }

    /**
     * The legacy { attribute_ids: [...] } shape must not produce a validation
     * error — backwards compatibility must be preserved.
     */
    public function test_legacy_attribute_ids_shape_still_accepted(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $attr = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'legacy-attr',
            'is_variant_axis' => true,
        ]);
        ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $attr->id,
            'code' => 'val1',
            'label' => 'Value 1',
        ]);

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'attribute_ids' => [$attr->id],
        ]);

        $resp->assertStatus(201);
    }
}
