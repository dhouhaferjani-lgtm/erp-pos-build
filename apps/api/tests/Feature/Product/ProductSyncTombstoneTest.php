<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ProductSyncTombstoneTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('products.view', 'sanctum');
        $this->user->givePermissionTo('products.view');

        Sanctum::actingAs($this->user);
    }

    public function test_products_index_includes_deleted_ids_for_soft_deleted_rows_since_cursor(): void
    {
        $kept = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Kept Product',
            'sku' => 'KEEP-001',
            'is_physical' => true,
            'sale_price' => '10.00',
            'tax_rate' => 7.00,
            'is_active' => true,
        ]);

        $doomed = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Doomed Product',
            'sku' => 'DOOM-001',
            'is_physical' => true,
            'sale_price' => '5.00',
            'tax_rate' => 7.00,
            'is_active' => true,
        ]);

        $cursor = now()->subMinute()->toIso8601String();
        $doomed->delete(); // soft-delete after the cursor

        $response = $this->getJson('/api/v1/products?updated_since='.urlencode($cursor));

        $response->assertOk();
        $response->assertJsonStructure(['data', 'deleted_ids']);

        $data = $response->json('data');
        $ids = array_column($data, 'id');
        $this->assertContains($kept->id, $ids);
        $this->assertNotContains($doomed->id, $ids);

        $this->assertContains($doomed->id, $response->json('deleted_ids'));
        $this->assertNotContains($kept->id, $response->json('deleted_ids'));
    }

    public function test_products_index_omits_deleted_ids_when_no_cursor(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
        $payload = $response->json();
        $this->assertArrayNotHasKey('deleted_ids', $payload);
    }
}
