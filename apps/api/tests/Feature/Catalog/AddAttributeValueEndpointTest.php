<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DEV-QA-045 — adding a duplicate attribute value must return a readable 422,
 * not a generic 500 (previously the missing FormRequest unique rule let the
 * insert hit the DB `unique(attribute_id, code)` constraint → QueryException).
 */
final class AddAttributeValueEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'EUR']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo([
            'catalog.attributes.view',
            'catalog.attributes.create',
            'catalog.attributes.update',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->actingAs($this->user);
    }

    private function createAttribute(): string
    {
        $resp = $this->postJson('/api/v1/product-attributes', [
            'code' => 'size',
            'name' => 'Pointure',
            'data_type' => 'selection',
            'is_variant_axis' => true,
        ]);
        $resp->assertStatus(201);

        return (string) $resp->json('data.id');
    }

    public function test_duplicate_attribute_value_returns_422_with_readable_message(): void
    {
        $attributeId = $this->createAttribute();

        $this->postJson("/api/v1/product-attributes/{$attributeId}/values", [
            'code' => 'xl',
            'label' => 'XL',
        ])->assertStatus(201);

        $duplicate = $this->postJson("/api/v1/product-attributes/{$attributeId}/values", [
            'code' => 'xl',
            'label' => 'XL again',
        ]);

        $duplicate->assertStatus(422);
        $duplicate->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertIsString($duplicate->json('error.errors.code.0'));
        $this->assertNotSame('', (string) $duplicate->json('error.errors.code.0'));
    }

    public function test_same_code_under_a_different_attribute_is_allowed(): void
    {
        $attrA = $this->createAttribute();

        $attrBResp = $this->postJson('/api/v1/product-attributes', [
            'code' => 'color',
            'name' => 'Couleur',
            'data_type' => 'selection',
            'is_variant_axis' => true,
        ]);
        $attrBResp->assertStatus(201);
        $attrB = (string) $attrBResp->json('data.id');

        $this->postJson("/api/v1/product-attributes/{$attrA}/values", ['code' => 'xl', 'label' => 'XL'])
            ->assertStatus(201);
        // Same code, different attribute — the unique scope is (attribute_id, code).
        $this->postJson("/api/v1/product-attributes/{$attrB}/values", ['code' => 'xl', 'label' => 'XL'])
            ->assertStatus(201);
    }
}
