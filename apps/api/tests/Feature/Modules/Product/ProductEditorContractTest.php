<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Shared\Enums\EnrichmentStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * IziPOS Product Editor — backend contract prerequisite tests.
 *
 * Tests cover all 6 TDD cases from the brief (§task-backend-core-wiring-brief.md):
 * 1. POST with unit_id → unit column mirrors unit code; response includes unit_id.
 * 2. POST with unit string only → reverse resolution sets unit_id (no regression).
 * 3. POST with is_active_for_ecommerce:true → persisted; response includes field.
 *    POST omitting it → default (false).
 * 4. POST type=service/no is_physical → is_physical=false.
 *    POST type=part/no is_physical → is_physical=true.
 *    POST type=service + is_physical=true → 422.
 * 5. POST requires_batch_tracking:true + default_shelf_life_days:30 → both persisted.
 * 6. PATCH (update path) parity for unit_id mirror + type/is_physical contradiction 422.
 */
final class ProductEditorContractTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private UnitCategory $unitCategory;

    private Unit $pcsUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Editor Contract Tenant',
            'slug' => 'editor-contract-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
            'enabled_extras' => [],
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Editor Contract Company',
            'legal_name' => 'Editor Contract Company LLC',
            'tax_id' => 'TAX-EC-001',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Editor Contract Admin',
            'email' => 'editor-admin@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Seed a system Unit (tenant_id IS NULL) so it is visible to all tenants.
        $this->unitCategory = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'count',
            'name' => 'Count',
            'is_system' => true,
            'is_active' => true,
        ]);

        $this->pcsUnit = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $this->unitCategory->id,
            'code' => 'pcs',
            'name' => 'Pieces',
            'symbol' => 'pcs',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Case 1: POST with unit_id → unit column mirrors unit code; response has unit_id
    // =========================================================================

    public function test_store_with_unit_id_mirrors_unit_code_and_exposes_unit_id_in_response(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product Unit ID',
                'sku' => 'EDITOR-UID-001',
                'unit_id' => $this->pcsUnit->id,
            ])
            ->assertCreated();

        $data = $response->json('data');

        // unit column must equal the unit's code
        $this->assertSame('pcs', $data['unit'], 'unit must mirror the unit code when unit_id is provided');

        // response must expose unit_id
        $this->assertArrayHasKey('unit_id', $data, 'unit_id must be present in the response');
        $this->assertSame($this->pcsUnit->id, $data['unit_id'], 'unit_id in response must match the sent unit_id');

        // DB assertion
        $product = Product::query()->where('sku', 'EDITOR-UID-001')->firstOrFail();
        $this->assertSame('pcs', $product->unit, 'DB unit column must be set to unit code');
        $this->assertSame($this->pcsUnit->id, $product->unit_id, 'DB unit_id must match');
    }

    // =========================================================================
    // Case 2: POST with unit string only → reverse resolution sets unit_id (no regression)
    // =========================================================================

    public function test_store_with_unit_string_only_reverse_resolves_unit_id(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Test Product Unit String',
                'sku' => 'EDITOR-USTR-001',
                'unit' => 'pcs',
            ])
            ->assertCreated();

        $data = $response->json('data');

        // unit_id must be set by reverse resolution
        $this->assertArrayHasKey('unit_id', $data, 'unit_id must be present in the response');
        $this->assertSame($this->pcsUnit->id, $data['unit_id'], 'unit_id must be reverse-resolved from unit string');

        $product = Product::query()->where('sku', 'EDITOR-USTR-001')->firstOrFail();
        $this->assertSame($this->pcsUnit->id, $product->unit_id, 'DB unit_id must be reverse-resolved');
    }

    // =========================================================================
    // Case 3: POST is_active_for_ecommerce:true → persisted; omitted → default false
    // =========================================================================

    public function test_store_with_is_active_for_ecommerce_true_persists(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Ecommerce Active Product',
                'sku' => 'EDITOR-ECOM-001',
                'is_active_for_ecommerce' => true,
            ])
            ->assertCreated();

        $data = $response->json('data');

        $this->assertArrayHasKey('is_active_for_ecommerce', $data, 'is_active_for_ecommerce must be in response');
        $this->assertTrue($data['is_active_for_ecommerce'], 'is_active_for_ecommerce must be true');

        $product = Product::query()->where('sku', 'EDITOR-ECOM-001')->firstOrFail();
        $this->assertTrue($product->is_active_for_ecommerce, 'DB is_active_for_ecommerce must be true');
    }

    public function test_store_omitting_is_active_for_ecommerce_defaults_to_false(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Ecommerce Default Product',
                'sku' => 'EDITOR-ECOM-002',
            ])
            ->assertCreated();

        $data = $response->json('data');

        $this->assertArrayHasKey('is_active_for_ecommerce', $data, 'is_active_for_ecommerce must be in response');
        $this->assertFalse($data['is_active_for_ecommerce'], 'is_active_for_ecommerce must default to false');
    }

    // =========================================================================
    // Case 4: type ↔ is_physical derivation and contradiction detection
    // =========================================================================

    public function test_store_type_service_without_is_physical_derives_false(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Service Product',
                'sku' => 'EDITOR-SVC-001',
                'type' => 'service',
            ])
            ->assertCreated();

        $product = Product::query()->where('sku', 'EDITOR-SVC-001')->firstOrFail();
        $this->assertFalse($product->is_physical, 'type=service must derive is_physical=false');
    }

    public function test_store_type_part_without_is_physical_derives_true(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Part Product',
                'sku' => 'EDITOR-PART-001',
                'type' => 'part',
            ])
            ->assertCreated();

        $product = Product::query()->where('sku', 'EDITOR-PART-001')->firstOrFail();
        $this->assertTrue($product->is_physical, 'type=part must derive is_physical=true');
    }

    public function test_store_type_consumable_without_is_physical_derives_true(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Consumable Product',
                'sku' => 'EDITOR-CONS-001',
                'type' => 'consumable',
            ])
            ->assertCreated();

        $product = Product::query()->where('sku', 'EDITOR-CONS-001')->firstOrFail();
        $this->assertTrue($product->is_physical, 'type=consumable must derive is_physical=true');
    }

    public function test_store_type_service_with_is_physical_true_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Service Physical Contradiction',
                'sku' => 'EDITOR-SVC-CONTR-001',
                'type' => 'service',
                'is_physical' => true,
            ]);

        $this->assertApiValidationErrors($response, ['is_physical']);
    }

    public function test_store_type_part_with_is_physical_false_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Part Non-Physical Contradiction',
                'sku' => 'EDITOR-PART-CONTR-001',
                'type' => 'part',
                'is_physical' => false,
            ]);

        $this->assertApiValidationErrors($response, ['is_physical']);
    }

    public function test_store_type_consumable_with_is_physical_false_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Consumable Non-Physical Contradiction',
                'sku' => 'EDITOR-CONS-CONTR-001',
                'type' => 'consumable',
                'is_physical' => false,
            ]);

        $this->assertApiValidationErrors($response, ['is_physical']);
    }

    // =========================================================================
    // Case 5: POST requires_batch_tracking + default_shelf_life_days persisted
    // =========================================================================

    public function test_store_batch_tracking_fields_persisted(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Batch Tracked Product',
                'sku' => 'EDITOR-BATCH-001',
                'requires_batch_tracking' => true,
                'default_shelf_life_days' => 30,
            ])
            ->assertCreated();

        $product = Product::query()->where('sku', 'EDITOR-BATCH-001')->firstOrFail();
        $this->assertTrue($product->requires_batch_tracking, 'requires_batch_tracking must be true');
        $this->assertSame(30, $product->default_shelf_life_days, 'default_shelf_life_days must be 30');
    }

    // =========================================================================
    // Case 6: PATCH (update path) parity
    // =========================================================================

    public function test_update_with_unit_id_mirrors_unit_code(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Update Unit Test Product',
            'sku' => 'EDITOR-UPD-UID-001',
            'unit' => 'piece',
            'unit_id' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'unit_id' => $this->pcsUnit->id,
            ])
            ->assertOk();

        $data = $response->json('data');

        $this->assertSame('pcs', $data['unit'], 'PATCH: unit must mirror the unit code when unit_id is provided');
        $this->assertArrayHasKey('unit_id', $data, 'PATCH: unit_id must be present in the response');
        $this->assertSame($this->pcsUnit->id, $data['unit_id']);

        $product->refresh();
        $this->assertSame('pcs', $product->unit, 'PATCH DB: unit column must be set to unit code');
    }

    public function test_update_type_service_with_is_physical_true_returns_422(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Update Contradiction Test',
            'sku' => 'EDITOR-UPD-CONTR-001',
            'type' => 'part',
            'is_physical' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'type' => 'service',
                'is_physical' => true,
            ]);

        $this->assertApiValidationErrors($response, ['is_physical']);
    }

    public function test_update_type_part_with_is_physical_false_returns_422(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Update Part Non-Physical',
            'sku' => 'EDITOR-UPD-CONTR-002',
            'type' => 'service',
            'is_physical' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'type' => 'part',
                'is_physical' => false,
            ]);

        $this->assertApiValidationErrors($response, ['is_physical']);
    }

    public function test_update_type_derivation_without_is_physical(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Update Type Derive Test',
            'sku' => 'EDITOR-UPD-DERIVE-001',
            'type' => 'part',
            'is_physical' => true,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'type' => 'service',
            ])
            ->assertOk();

        $product->refresh();
        $this->assertFalse($product->is_physical, 'PATCH: type=service without is_physical must derive false');
    }

    // =========================================================================
    // Inventory fields: units_per_pack, shelf_location, reorder_point, reorder_quantity
    // =========================================================================

    public function test_store_inventory_fields_persisted_and_returned(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Inventory Fields Product',
                'sku' => 'EDITOR-INV-001',
                'units_per_pack' => 24,
                'shelf_location' => 'A-12',
                'reorder_point' => '10',
                'reorder_quantity' => '50.5',
            ])
            ->assertCreated();

        $data = $response->json('data');

        $this->assertSame(24, $data['units_per_pack'], 'units_per_pack must be persisted as integer');
        $this->assertSame('A-12', $data['shelf_location'], 'shelf_location must be persisted');
        $this->assertSame('10.0000', $data['reorder_point'], 'reorder_point must be returned as string with 4dp');
        $this->assertSame('50.5000', $data['reorder_quantity'], 'reorder_quantity must be returned as string with 4dp');

        $product = Product::query()->where('sku', 'EDITOR-INV-001')->firstOrFail();
        $this->assertSame(24, $product->units_per_pack);
        $this->assertSame('A-12', $product->shelf_location);
        $this->assertSame('10.0000', (string) $product->reorder_point);
        $this->assertSame('50.5000', (string) $product->reorder_quantity);
    }

    public function test_store_omitting_inventory_fields_returns_null(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'No Inventory Fields Product',
                'sku' => 'EDITOR-INV-002',
            ])
            ->assertCreated();

        $data = $response->json('data');

        $this->assertNull($data['units_per_pack'], 'units_per_pack must be null when omitted');
        $this->assertNull($data['shelf_location'], 'shelf_location must be null when omitted');
        $this->assertNull($data['reorder_point'], 'reorder_point must be null when omitted');
        $this->assertNull($data['reorder_quantity'], 'reorder_quantity must be null when omitted');
    }

    public function test_store_units_per_pack_zero_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Bad Pack Count',
                'sku' => 'EDITOR-INV-003',
                'units_per_pack' => 0,
            ]);

        $this->assertApiValidationErrors($response, ['units_per_pack']);
    }

    public function test_store_reorder_point_too_many_decimals_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/products', [
                'name' => 'Bad Reorder Point',
                'sku' => 'EDITOR-INV-004',
                'reorder_point' => '1.23456',
            ]);

        $this->assertApiValidationErrors($response, ['reorder_point']);
    }

    public function test_update_inventory_fields_persisted(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Update Inventory Test',
            'sku' => 'EDITOR-INV-UPD-001',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/products/{$product->id}", [
                'units_per_pack' => 12,
                'reorder_point' => '5.0000',
            ])
            ->assertOk();

        $data = $response->json('data');

        $this->assertSame(12, $data['units_per_pack'], 'PATCH: units_per_pack must be persisted');
        $this->assertSame('5.0000', $data['reorder_point'], 'PATCH: reorder_point must be returned as string with 4dp');

        $product->refresh();
        $this->assertSame(12, $product->units_per_pack);
        $this->assertSame('5.0000', (string) $product->reorder_point);
    }

    public function test_show_exposes_editor_hero_enrichment_and_stock_contract(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Hero Contract Product',
            'sku' => 'EDITOR-HERO-001',
            'enrichment_status' => EnrichmentStatus::Completed,
        ]);

        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Hero Contract Store',
            'type' => LocationType::Shop,
            'is_active' => true,
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '24.0000',
            'reserved' => '0.0000',
        ]);

        EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => 'trk-editor-hero-001',
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Hero Contract Product',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 91,
                enrichment_tier: 'high',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'full',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $data = $response->json('data');

        $this->assertSame('completed', $data['enrichment_status']);
        $this->assertSame('pending_review', $data['latest_enrichment_result']['status']);
        $this->assertSame('24.0000', $data['stock_quantity']);
    }
}
