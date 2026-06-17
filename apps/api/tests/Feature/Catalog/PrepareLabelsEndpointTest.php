<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\ProductFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task B3 — POST /api/v1/labels/variants/prepare.
 *
 * Real DB (RefreshDatabase) + seeded permissions; the full middleware/auth
 * stack runs (no mocked HTTP).
 */
class PrepareLabelsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo(['catalog.labels.print']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    private function variant(): ProductVariant
    {
        $product = ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '9.000',
        ]);

        return ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'sku' => 'LBL-'.uniqid(),
            'barcode' => null,
        ]);
    }

    public function test_valid_items_return_200_with_ready_and_skipped_structure(): void
    {
        $variant = $this->variant();

        $resp = $this->postJson('/api/v1/labels/variants/prepare', [
            'items' => [
                ['variant_id' => $variant->id, 'quantity' => 2],
            ],
        ]);

        $resp->assertOk();
        $resp->assertJsonPath('data.ready.0.variant_id', $variant->id);
        $resp->assertJsonPath('data.ready.0.quantity', 2);
        $resp->assertJsonStructure([
            'data' => ['ready' => [['variant_id', 'quantity', 'barcode_value', 'symbology']]],
            'meta' => ['skipped'],
        ]);
    }

    public function test_empty_items_is_422(): void
    {
        $this->postJson('/api/v1/labels/variants/prepare', ['items' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['items']]]);
    }

    public function test_zero_quantity_is_422(): void
    {
        $variant = $this->variant();

        $this->postJson('/api/v1/labels/variants/prepare', [
            'items' => [['variant_id' => $variant->id, 'quantity' => 0]],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['items.0.quantity']]]);
    }

    public function test_total_quantity_over_cap_is_422(): void
    {
        $variant = $this->variant();

        $this->postJson('/api/v1/labels/variants/prepare', [
            'items' => [['variant_id' => $variant->id, 'quantity' => 1001]],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['quantity']]]);
    }

    public function test_too_many_items_is_422(): void
    {
        $items = [];
        for ($i = 0; $i < 501; $i++) {
            $items[] = ['variant_id' => '00000000-0000-4000-8000-'.sprintf('%012d', $i), 'quantity' => 1];
        }

        $this->postJson('/api/v1/labels/variants/prepare', ['items' => $items])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['items']]]);
    }

    public function test_no_permission_is_403(): void
    {
        $other = User::factory()->for($this->tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $other->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $variant = $this->variant();

        $this->actingAs($other)
            ->postJson('/api/v1/labels/variants/prepare', [
                'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);
    }
}
