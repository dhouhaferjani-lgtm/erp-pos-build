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

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
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

        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);
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

    /**
     * Gate r1 F-2 — a malformed `{attributeId}` must stay a 400 from the
     * controller guard (AttributeController.php:68-70), NOT a 500.
     *
     * The FormRequest runs before the controller body, so the `Rule::unique`
     * scope introduced for DEV-QA-045 would otherwise bind a non-UUID string
     * against the `uuid` column `product_attribute_values.attribute_id`
     * (migration 2026_06_02_100002_create_product_attribute_values_table.php:17).
     * PostgreSQL rejects that with SQLSTATE 22P02 → 500. SQLite compares it
     * happily, so this regression is only observable on the PG leg — it is kept
     * driver-agnostic here so both legs run it, but only PG can go red.
     */
    public function test_non_uuid_attribute_id_returns_400_not_500(): void
    {
        $response = $this->postJson('/api/v1/product-attributes/not-a-uuid/values', [
            'code' => 'xl',
            'label' => 'XL',
        ]);

        $response->assertStatus(400);
        $this->assertSame('Invalid ID format', $response->json('message'));
    }

    /**
     * Gate r1 F-7 — validation ceilings must match the column widths
     * (code varchar(64), label varchar(128); migration lines 18-19), so an
     * over-long value is a readable 422 rather than a
     * "value too long for type character varying" 500.
     */
    public function test_over_long_code_and_label_are_rejected_with_422(): void
    {
        $attributeId = $this->createAttribute();

        $response = $this->postJson("/api/v1/product-attributes/{$attributeId}/values", [
            'code' => str_repeat('x', 65),
            'label' => str_repeat('y', 129),
        ]);

        $response->assertStatus(422);
        $this->assertIsString($response->json('error.errors.code.0'));
        $this->assertIsString($response->json('error.errors.label.0'));
    }

    /**
     * Gate r1 F-4 — CLAUDE.md rule 22 / docs/conventions/09-SECOND-OF-EVERYTHING.md.
     *
     * `product_attribute_values` is a catalogue table whose unique key is
     * `unique(['attribute_id', 'code'])` with NO `company_id`
     * (migration 2026_06_02_100002_create_product_attribute_values_table.php:24),
     * and its parent `product_attributes` is keyed `unique(['tenant_id','code'])`
     * (2026_06_02_100001_create_product_attributes_table.php:24). Both are
     * therefore TENANT-wide, not company-wide — exactly the shape
     * docs/conventions/09-SECOND-OF-EVERYTHING.md lists as legacy-too-wide.
     *
     * This test RECORDS that behaviour so a later re-scoping lane has a red to
     * flip: company B, in the same tenant, collides with company A's value.
     *
     * Second location: N/A — neither table carries a `location_id`, and the
     * endpoint takes no location; attribute values are not location-scoped.
     */
    public function test_second_company_shares_the_tenant_wide_attribute_value_scope(): void
    {
        $attributeId = $this->createAttribute();

        $this->postJson("/api/v1/product-attributes/{$attributeId}/values", [
            'code' => 'xl',
            'label' => 'XL',
        ])->assertStatus(201);

        // Second company, same tenant, same user.
        $companyB = Company::factory()->for($this->tenant)->create(['currency' => 'EUR']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $companyB->id,
            'role' => 'admin',
        ]);
        $this->app->make(CompanyContext::class)->setCompanyId($companyB->id);

        // Current (too-wide) behaviour: the attribute is visible from company B
        // and its value codes collide tenant-wide.
        $duplicateFromB = $this->postJson("/api/v1/product-attributes/{$attributeId}/values", [
            'code' => 'xl',
            'label' => 'XL for company B',
        ]);
        $duplicateFromB->assertStatus(422);
        $duplicateFromB->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // ...and company B is not otherwise blocked: a fresh code still stores.
        $this->postJson("/api/v1/product-attributes/{$attributeId}/values", [
            'code' => 'xxl',
            'label' => 'XXL for company B',
        ])->assertStatus(201);
    }
}
