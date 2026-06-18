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
 * Task C2 — POST /api/v1/labels/variants/pdf.
 *
 * Real DB (RefreshDatabase) + seeded permissions; the full middleware/auth
 * stack runs. Variant scoping needs PG (run via phpunit-pgsql.xml).
 */
class GenerateLabelPdfEndpointTest extends TestCase
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

    private function variant(?string $barcode = 'BC-'): ProductVariant
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
            'barcode' => $barcode === 'BC-' ? 'BC-'.uniqid() : $barcode,
        ]);
    }

    public function test_returns_pdf_binary_for_variants_with_barcodes(): void
    {
        $variant = $this->variant();

        $resp = $this->post('/api/v1/labels/variants/pdf', [
            'format' => 'avery_l7160',
            'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'start_cell' => 0,
        ]);

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_unknown_format_is_422(): void
    {
        $variant = $this->variant();

        $this->postJson('/api/v1/labels/variants/pdf', [
            'format' => 'nope',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['format']]]);
    }

    public function test_start_cell_beyond_sheet_is_422(): void
    {
        $variant = $this->variant();

        // avery_l7160 = 7*3 = 21 cells; start_cell 21 is out of range.
        $this->postJson('/api/v1/labels/variants/pdf', [
            'format' => 'avery_l7160',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            'start_cell' => 21,
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['start_cell']]]);
    }

    public function test_total_quantity_over_cap_is_422(): void
    {
        $variant = $this->variant();

        $this->postJson('/api/v1/labels/variants/pdf', [
            'format' => 'avery_l7160',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1001]],
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['quantity']]]);
    }

    public function test_variant_without_barcode_is_422(): void
    {
        $variant = $this->variant(barcode: null);

        $this->postJson('/api/v1/labels/variants/pdf', [
            'format' => 'avery_l7160',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertStatus(422);
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
            ->postJson('/api/v1/labels/variants/pdf', [
                'format' => 'avery_l7160',
                'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
            ])->assertStatus(403);
    }
}
