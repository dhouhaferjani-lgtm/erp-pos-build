<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\ProductFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Task C1 — FormRequest barcode uniqueness (tenant-scoped, soft-delete aware)
 * + max:100 length cap on barcode/sku for product variants.
 *
 * Requires real PostgreSQL (the unique rule is exercised against the live
 * partial-unique index semantics). Run with -c phpunit-pgsql.xml.
 */
class VariantBarcodeValidationTest extends TestCase
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
            'catalog.variants.create',
            'catalog.variants.update',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    private function makeProduct(): Product
    {
        return ProductFactory::new()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_create_rejects_duplicate_barcode_with_422(): void
    {
        $product = $this->makeProduct();

        ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-EXISTING',
            'sku' => 'V-EXISTING',
            'name_suffix' => 'Existing',
            'barcode' => '3401234',
        ]);

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_code' => 'V-NEW',
            'sku' => 'V-NEW',
            'name_suffix' => 'New',
            'barcode' => '3401234',
        ]);

        $this->assertApiValidationErrors($resp, ['barcode']);
    }

    public function test_update_allows_keeping_own_barcode(): void
    {
        $product = $this->makeProduct();

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_code' => 'V-OWN',
            'sku' => 'V-OWN',
            'name_suffix' => 'Own',
            'barcode' => '3409999',
        ]);

        $resp = $this->patchJson("/api/v1/product-variants/{$variant->id}", [
            'barcode' => '3409999',
            'name_suffix' => 'Own Updated',
        ]);

        $resp->assertOk();
        $resp->assertJsonPath('data.barcode', '3409999');
    }

    public function test_barcode_over_100_chars_rejected(): void
    {
        $product = $this->makeProduct();

        $resp = $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_code' => 'V-LONG',
            'sku' => 'V-LONG',
            'name_suffix' => 'Long',
            'barcode' => str_repeat('9', 101),
        ]);

        $this->assertApiValidationErrors($resp, ['barcode']);
    }
}
