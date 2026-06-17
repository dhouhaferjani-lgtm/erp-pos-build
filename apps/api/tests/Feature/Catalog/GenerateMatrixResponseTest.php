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

/**
 * Task A4 — controller wires axes + meta-counts response.
 *
 * Real DB + seeded permissions; full middleware stack. Requires PG for the
 * variant unique constraints used downstream.
 */
class GenerateMatrixResponseTest extends TestCase
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

    public function test_response_keeps_data_array_and_adds_meta_counts(): void
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'TEE',
        ]);

        $size = ProductAttributeFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'size',
            'is_variant_axis' => true,
        ]);
        $small = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $size->id,
            'code' => 's',
            'label' => 'Small',
        ]);
        $medium = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_id' => $size->id,
            'code' => 'm',
            'label' => 'Medium',
        ]);

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants/generate-matrix", [
            'axes' => [
                ['attribute_id' => $size->id, 'value_ids' => [$small->id, $medium->id]],
            ],
        ]);

        $resp->assertStatus(201);
        $resp->assertJsonCount(2, 'data');
        $resp->assertJsonPath('meta.created_count', 2);
        $resp->assertJsonPath('meta.restored_count', 0);
        $resp->assertJsonPath('meta.skipped_count', 0);

        // data is a plain (list) array of variant objects.
        $data = $resp->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('id', $data[0]);
    }
}
