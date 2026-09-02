<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UomApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant first
        $this->tenant = Tenant::factory()->create();

        // Create company under tenant
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Set permissions team ID context (required for multi-tenant permissions)
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        // Create permissions for UoM module (will be scoped to tenant via context)
        $permissions = ['uom.view', 'uom.create', 'uom.edit', 'uom.delete', 'units.manage'];
        foreach ($permissions as $permission) {
            Permission::create([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }

        // Create user
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        // Create user-company membership (users relate to companies via many-to-many)
        $this->user->companyMemberships()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
            'role' => 'admin',
        ]);

        // Assign all permissions to user (tenant context already set above)
        $this->user->givePermissionTo($permissions);

        // Authenticate user
        Sanctum::actingAs($this->user);
    }

    /**
     * Test listing all categories with their units
     */
    public function test_can_list_all_categories(): void
    {
        // Create weight category with units
        $weightCategory = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'weight',
            'name' => 'Weight',
            'is_system' => true,
        ]);

        $gram = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $weightCategory->id,
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'is_base_unit' => true,
            'is_system' => true,
        ]);

        $kg = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $weightCategory->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'is_system' => true,
        ]);

        $weightCategory->update(['base_unit_id' => $gram->id]);

        // Make request
        $response = $this->getJson('/api/v1/uom/categories');

        // Assert response structure
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'description',
                        'baseUnitId',
                        'isSystem',
                        'isActive',
                    ],
                ],
                'meta' => ['timestamp', 'request_id'],
            ])
            ->assertJsonFragment([
                'code' => 'weight',
                'name' => 'Weight',
            ]);
    }

    /**
     * Test listing all units (optionally filtered by category)
     */
    public function test_can_list_all_units(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);

        Unit::factory()->count(3)->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'is_system' => true,
        ]);

        $response = $this->getJson('/api/v1/uom/units');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'categoryId',
                        'code',
                        'name',
                        'symbol',
                        'conversionFactor',
                        'decimalPlaces',
                        'roundingMethod',
                        'isBaseUnit',
                        'isSystem',
                        'isActive',
                    ],
                ],
            ]);
    }

    /**
     * Test filtering units by category
     */
    public function test_can_filter_units_by_category(): void
    {
        $weightCategory = UnitCategory::factory()->create(['code' => 'weight']);
        $volumeCategory = UnitCategory::factory()->create(['code' => 'volume']);

        Unit::factory()->count(3)->create(['category_id' => $weightCategory->id]);
        Unit::factory()->count(2)->create(['category_id' => $volumeCategory->id]);

        $response = $this->getJson("/api/v1/uom/units?category_id={$weightCategory->id}");

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /**
     * Test getting a single unit
     */
    public function test_can_get_single_unit(): void
    {
        $category = UnitCategory::factory()->create();
        $unit = Unit::factory()->create([
            'category_id' => $category->id,
            'code' => 'kg',
            'name' => 'Kilogram',
        ]);

        $response = $this->getJson("/api/v1/uom/units/{$unit->id}");

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $unit->id,
                    'code' => 'kg',
                    'name' => 'Kilogram',
                ],
            ]);
    }

    /**
     * Test creating a custom unit
     */
    public function test_can_create_custom_unit(): void
    {
        $category = UnitCategory::factory()->create();

        $payload = [
            'category_id' => $category->id,
            'code' => 'tbsp',
            'name' => 'Tablespoon',
            'symbol' => 'tbsp',
            'conversion_factor' => '14.787',
            'decimal_places' => 2,
            'rounding_method' => 'half_up',
        ];

        $response = $this->postJson('/api/v1/uom/units', $payload);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'code' => 'tbsp',
                    'name' => 'Tablespoon',
                    'symbol' => 'tbsp',
                    'conversionFactor' => '14.787',
                    'isSystem' => false,
                ],
            ]);

        $this->assertDatabaseHas('units', [
            'code' => 'tbsp',
            'name' => 'Tablespoon',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * Test validation on unit creation
     */
    public function test_unit_creation_requires_valid_data(): void
    {
        // Missing required fields
        $response = $this->postJson('/api/v1/uom/units', []);

        // The application wraps validation failures in
        // `{ error: { code: 'VALIDATION_ERROR', message, errors: {...} } }`
        // (see App\Exceptions\Handler), so the standard Laravel
        // `assertJsonValidationErrors` (which reads top-level `errors`)
        // doesn't apply. Assert the wrapped path directly.
        $response->assertUnprocessable()
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'errors' => [
                        'category_id',
                        'code',
                        'name',
                        'symbol',
                        'conversion_factor',
                    ],
                ],
            ])
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /**
     * Test updating a custom unit
     */
    public function test_can_update_custom_unit(): void
    {
        $category = UnitCategory::factory()->create();
        $unit = Unit::factory()->create([
            'category_id' => $category->id,
            'tenant_id' => $this->tenant->id,
            'is_system' => false,
            'code' => 'tbsp',
            'name' => 'Tablespoon',
        ]);

        $payload = [
            'name' => 'Table Spoon',
            'symbol' => 'Tbsp',
        ];

        $response = $this->putJson("/api/v1/uom/units/{$unit->id}", $payload);

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'name' => 'Table Spoon',
                    'symbol' => 'Tbsp',
                ],
            ]);

        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'name' => 'Table Spoon',
            'symbol' => 'Tbsp',
        ]);
    }

    /**
     * Test cannot update system units
     */
    public function test_cannot_update_system_units(): void
    {
        $category = UnitCategory::factory()->create();
        $systemUnit = Unit::factory()->create([
            'category_id' => $category->id,
            'is_system' => true,
        ]);

        $response = $this->putJson("/api/v1/uom/units/{$systemUnit->id}", [
            'name' => 'Modified Name',
        ]);

        $response->assertUnprocessable()
            ->assertJsonFragment(['code' => 'SYSTEM_UNIT']);
    }

    /**
     * Test deactivating a custom unit
     */
    public function test_can_delete_custom_unit(): void
    {
        $category = UnitCategory::factory()->create();
        $unit = Unit::factory()->create([
            'category_id' => $category->id,
            'tenant_id' => $this->tenant->id,
            'is_system' => false,
        ]);

        $response = $this->deleteJson("/api/v1/uom/units/{$unit->id}");

        $response->assertNoContent();

        // Unit should be deactivated, not deleted
        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'is_active' => false,
        ]);
    }

    /**
     * Test cannot delete system units
     */
    public function test_cannot_delete_system_units(): void
    {
        $category = UnitCategory::factory()->create();
        $systemUnit = Unit::factory()->create([
            'category_id' => $category->id,
            'is_system' => true,
        ]);

        $response = $this->deleteJson("/api/v1/uom/units/{$systemUnit->id}");

        $response->assertUnprocessable()
            ->assertJsonFragment(['code' => 'SYSTEM_UNIT']);
    }

    /**
     * Test converting between units
     */
    public function test_can_convert_between_units(): void
    {
        $category = UnitCategory::factory()->create();

        $gram = Unit::factory()->create([
            'category_id' => $category->id,
            'code' => 'g',
            'conversion_factor' => '1',
            'decimal_places' => 2,
            'is_base_unit' => true,
        ]);

        $kg = Unit::factory()->create([
            'category_id' => $category->id,
            'code' => 'kg',
            'conversion_factor' => '1000',
            'decimal_places' => 3,
        ]);

        $payload = [
            'quantity' => 2.5,
            'from_unit_id' => $kg->id,
            'to_unit_id' => $gram->id,
        ];

        $response = $this->postJson('/api/v1/uom/convert', $payload);

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'originalQuantity' => '2.5',
                    'convertedQuantity' => '2500.00',
                    'conversionFactor' => '1000.0000000000',
                ],
            ]);
    }

    /**
     * Test conversion fails for incompatible units
     */
    public function test_conversion_fails_for_incompatible_units(): void
    {
        $weightCategory = UnitCategory::factory()->create(['code' => 'weight']);
        $volumeCategory = UnitCategory::factory()->create(['code' => 'volume']);

        $kg = Unit::factory()->create(['category_id' => $weightCategory->id]);
        $liter = Unit::factory()->create(['category_id' => $volumeCategory->id]);

        $payload = [
            'quantity' => 10,
            'from_unit_id' => $kg->id,
            'to_unit_id' => $liter->id,
        ];

        $response = $this->postJson('/api/v1/uom/convert', $payload);

        $response->assertStatus(422);
    }

    /**
     * Test unauthorized access is blocked
     */
    public function test_unauthorized_access_is_blocked(): void
    {
        // Remove authentication
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/uom/categories');

        $response->assertUnauthorized();
    }

    /**
     * Test permission-based access control
     */
    public function test_requires_permissions(): void
    {
        // Create user without permissions. The `users` table only carries
        // `tenant_id`; company association lives in `user_company_memberships`.
        // Passing `company_id` here used to crash the test with "table users
        // has no column named company_id".
        $userWithoutPermissions = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $userWithoutPermissions->companyMemberships()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
            'role' => 'viewer',
        ]);

        Sanctum::actingAs($userWithoutPermissions);

        $response = $this->getJson('/api/v1/uom/categories');

        // Depending on your permission implementation, this might be 403 or redirect
        $this->assertTrue(
            $response->status() === 403 || $response->status() === 401,
            'Expected 403 Forbidden or 401 Unauthorized, got '.$response->status()
        );
    }

    /**
     * REGRESSION TEST: Categories must include their units array
     *
     * Issue: UnitCategoryData DTO was missing units property, causing
     * frontend to not display units after creation.
     *
     * Fix: Added units array to UnitCategoryData and populated it
     * when units relationship is loaded.
     *
     * @see UnitCategoryData::fromModel()
     */
    public function test_categories_include_units_array(): void
    {
        // Create category with multiple units
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'weight',
            'name' => 'Weight',
            'is_system' => true,
        ]);

        $gram = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'g',
            'name' => 'Gram',
            'symbol' => 'g',
            'conversion_factor' => '1',
            'is_base_unit' => true,
            'is_system' => true,
        ]);

        $kilogram = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'conversion_factor' => '1000',
            'is_system' => true,
        ]);

        $category->update(['base_unit_id' => $gram->id]);

        // Request categories
        $response = $this->getJson('/api/v1/uom/categories');

        // Assert units array is present and contains units
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'units' => [  // CRITICAL: units array must be present
                            '*' => [
                                'id',
                                'code',
                                'name',
                                'symbol',
                                'conversionFactor',
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJsonCount(2, 'data.0.units'); // Should have 2 units

        // Verify the units data is correct
        $responseData = $response->json('data.0.units');
        self::assertIsArray($responseData);
        $this->assertCount(2, $responseData);

        $unitCodes = collect($responseData)->pluck('code')->toArray();
        $this->assertContains('g', $unitCodes);
        $this->assertContains('kg', $unitCodes);
    }

    /**
     * REGRESSION TEST: No duplicate categories should exist within a tenant
     *
     * The `unit_categories` table has a `unique(['tenant_id', 'code'])`
     * constraint, which fires on per-tenant duplicates. Note that
     * SQL NULL semantics treat each NULL `tenant_id` as distinct, so the
     * constraint does *not* block two system categories (tenant_id NULL)
     * with the same code — that's a separate gap that needs a partial
     * unique index, tracked outside this PR.
     */
    public function test_cannot_create_duplicate_categories(): void
    {
        UnitCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'weight',
            'name' => 'Weight',
            'is_system' => false,
        ]);

        // Attempt to create duplicate within the same tenant should fail
        $this->expectException(QueryException::class);

        UnitCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'code' => 'weight',  // Duplicate code within same tenant
            'name' => 'Weight Duplicate',
            'is_system' => false,
        ]);
    }

    /**
     * REGRESSION TEST: Created units appear immediately in category list
     *
     * Issue: After creating a unit, it didn't appear in the units list
     * because categories endpoint wasn't including units.
     *
     * Fix: Added units array to UnitCategoryData DTO.
     */
    public function test_newly_created_unit_appears_in_category_list(): void
    {
        $category = UnitCategory::factory()->create([
            'code' => 'volume',
            'name' => 'Volume',
        ]);

        // Create first unit
        $payload = [
            'category_id' => $category->id,
            'code' => 'tbsp',
            'name' => 'Tablespoon',
            'symbol' => 'tbsp',
            'conversion_factor' => '14.787',
            'decimal_places' => 2,
            'rounding_method' => 'half_up',
        ];

        $createResponse = $this->postJson('/api/v1/uom/units', $payload);
        $createResponse->assertCreated();

        // Immediately fetch categories - new unit MUST appear
        $categoriesResponse = $this->getJson('/api/v1/uom/categories');

        $categoriesResponse->assertOk();

        // Find the volume category in response
        $categories = $categoriesResponse->json('data');
        self::assertIsArray($categories);
        $volumeCategory = collect($categories)->firstWhere('code', 'volume');

        self::assertIsArray($volumeCategory, 'Volume category should exist');
        $this->assertArrayHasKey('units', $volumeCategory, 'Category must have units array');
        self::assertIsArray($volumeCategory['units']);
        $this->assertCount(1, $volumeCategory['units'], 'Should have 1 unit');
        $this->assertEquals('tbsp', $volumeCategory['units'][0]['code']);

        // Create second unit
        $payload2 = [
            'category_id' => $category->id,
            'code' => 'tsp',
            'name' => 'Teaspoon',
            'symbol' => 'tsp',
            'conversion_factor' => '4.929',
            'decimal_places' => 2,
            'rounding_method' => 'half_up',
        ];

        $this->postJson('/api/v1/uom/units', $payload2)->assertCreated();

        // Fetch again - should now have 2 units
        $categoriesResponse2 = $this->getJson('/api/v1/uom/categories');
        $categories2 = $categoriesResponse2->json('data');
        self::assertIsArray($categories2);
        $volumeCategory2 = collect($categories2)->firstWhere('code', 'volume');

        self::assertIsArray($volumeCategory2);
        self::assertIsArray($volumeCategory2['units']);
        $this->assertCount(2, $volumeCategory2['units'], 'Should now have 2 units');

        $unitCodes = collect($volumeCategory2['units'])->pluck('code')->toArray();
        $this->assertContains('tbsp', $unitCodes);
        $this->assertContains('tsp', $unitCodes);
    }

    /**
     * REGRESSION TEST: System units cannot be modified
     *
     * Ensures system units remain immutable to prevent data corruption.
     */
    public function test_system_units_cannot_be_modified(): void
    {
        $category = UnitCategory::factory()->create([
            'is_system' => true,
        ]);

        $systemUnit = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'g',
            'name' => 'Gram',
            'is_system' => true,
        ]);

        // Attempt to update system unit should fail
        $response = $this->putJson("/api/v1/uom/units/{$systemUnit->id}", [
            'name' => 'Modified Gram',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'code' => 'SYSTEM_UNIT',
            ]);
    }

    /**
     * REGRESSION TEST: Units list updates reflect in categories endpoint
     *
     * Ensures both endpoints (/units and /categories) return consistent data.
     */
    public function test_units_endpoints_are_consistent(): void
    {
        $category = UnitCategory::factory()->create([
            'code' => 'length',
            'name' => 'Length',
        ]);

        Unit::factory()->count(3)->create([
            'category_id' => $category->id,
        ]);

        // Get units from /units endpoint
        $unitsResponse = $this->getJson("/api/v1/uom/units?category_id={$category->id}");
        $unitsFromUnitsEndpoint = $unitsResponse->json('data');
        self::assertIsArray($unitsFromUnitsEndpoint);

        // Get units from /categories endpoint
        $categoriesResponse = $this->getJson('/api/v1/uom/categories');
        $categories = $categoriesResponse->json('data');
        self::assertIsArray($categories);
        $lengthCategory = collect($categories)->firstWhere('code', 'length');
        self::assertIsArray($lengthCategory);
        $unitsFromCategoriesEndpoint = $lengthCategory['units'];
        self::assertIsArray($unitsFromCategoriesEndpoint);

        // Both should return the same number of units
        $this->assertCount(3, $unitsFromUnitsEndpoint);
        $this->assertCount(3, $unitsFromCategoriesEndpoint);

        // Unit IDs should match
        $idsFromUnits = collect($unitsFromUnitsEndpoint)->pluck('id')->sort()->values()->toArray();
        $idsFromCategories = collect($unitsFromCategoriesEndpoint)->pluck('id')->sort()->values()->toArray();

        $this->assertEquals($idsFromUnits, $idsFromCategories);
    }

    public function test_unmapped_texts_are_company_scoped_and_mapping_is_idempotent_and_audited(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);
        $piece = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'pc',
            'name' => 'Piece',
            'symbol' => 'pc',
            'is_system' => true,
        ]);
        $kilogram = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'is_system' => true,
        ]);
        Product::factory()->count(2)->sequence(
            ['sku' => 'K9-PIECE-1'],
            ['sku' => 'K9-PIECE-2'],
        )->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => null,
            'unit' => 'piece',
        ]);
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'K9-BLANK-1',
            'unit_id' => null,
            'unit' => null,
        ]);
        $this->createValidatedUnknownRows($this->company, 'piece', 2);
        $this->createValidatedUnknownRows($this->company, 'piece', 1);

        $secondCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->companyMemberships()->create([
            'company_id' => $secondCompany->id,
            'status' => 'active',
            'role' => 'admin',
        ]);
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $secondCompany->id,
            'sku' => 'K9-SECOND-PIECE',
            'unit_id' => null,
            'unit' => 'piece',
        ]);

        $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/uom/unit-text-mappings/unmapped')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sourceText', null)
            ->assertJsonPath('data.0.productCount', 1)
            ->assertJsonPath('data.1.sourceText', 'piece')
            ->assertJsonPath('data.1.productCount', 2)
            ->assertJsonPath('data.1.importRowCount', 3)
            ->assertJsonPath('data.1.pendingImportCount', 2)
            ->assertJsonPath('data.1.totalCount', 5);

        $map = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => 'piece',
                'target_unit_id' => $piece->id,
            ]);

        $map->assertOk()
            ->assertJsonPath('data.sourceText', 'piece')
            ->assertJsonPath('data.targetUnitCode', 'pc')
            ->assertJsonPath('data.productCount', 2)
            ->assertJsonPath('data.importRowCount', 3)
            ->assertJsonPath('data.applied', true);
        $this->assertSame(2, Product::query()
            ->where('company_id', $this->company->id)
            ->where('unit_id', $piece->id)
            ->where('unit', 'pc')
            ->count());
        $this->assertSame(1, Product::query()
            ->where('company_id', $secondCompany->id)
            ->whereNull('unit_id')
            ->where('unit', 'piece')
            ->count());
        $this->assertSame(1, AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'uom.unit_text_mapping_applied')
            ->count());

        $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => 'piece',
                'target_unit_id' => $kilogram->id,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'UNIT_TEXT_MAPPING_CONFLICT');

        $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => 'piece',
                'target_unit_id' => $piece->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.productCount', 0)
            ->assertJsonPath('data.importRowCount', 0)
            ->assertJsonPath('data.applied', false);
        $this->assertSame(1, AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'uom.unit_text_mapping_applied')
            ->count());

        $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/uom/unit-text-mappings/unmapped')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sourceText', null);
        $this->withHeader('X-Company-Id', $secondCompany->id)
            ->getJson('/api/v1/uom/unit-text-mappings/unmapped')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sourceText', 'piece')
            ->assertJsonPath('data.0.productCount', 1);
    }

    public function test_blank_mapping_requires_visible_pc_and_manage_permission(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => null]);
        $piece = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'pc',
            'name' => 'Piece',
            'symbol' => 'pc',
            'is_system' => true,
        ]);
        $kilogram = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'kg',
            'name' => 'Kilogram',
            'symbol' => 'kg',
            'is_system' => true,
        ]);
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'K9-BLANK-PERMISSION',
            'unit_id' => null,
            'unit' => null,
        ]);

        $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => null,
                'target_unit_id' => $kilogram->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'UNIT_TEXT_MAPPING_BLANK_REQUIRES_PC');

        $this->user->revokePermissionTo('units.manage');
        $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/uom/unit-text-mappings/unmapped')
            ->assertForbidden();
        $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => null,
                'target_unit_id' => $piece->id,
            ])
            ->assertForbidden();
    }

    public function test_mapping_rejects_a_target_outside_the_company_visible_catalog(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $category = UnitCategory::factory()->create(['tenant_id' => $foreignTenant->id]);
        $foreignUnit = Unit::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'category_id' => $category->id,
            'code' => 'foreign',
            'name' => 'Foreign',
            'symbol' => 'f',
        ]);

        $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/uom/unit-text-mappings', [
                'source_text' => 'piece',
                'target_unit_id' => $foreignUnit->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'UNIT_TEXT_MAPPING_TARGET_NOT_VISIBLE');
    }

    private function createValidatedUnknownRows(Company $company, string $text, int $count): void
    {
        $job = ImportJob::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'k9-preview.csv',
            'file_path' => 'imports/k9-preview.csv',
            'total_rows' => $count,
            'failed_rows' => $count,
        ]);

        foreach (range(1, $count) as $rowNumber) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $rowNumber,
                'data' => ['name' => "K9 preview {$rowNumber}", 'unit' => $text],
                'is_valid' => false,
                'errors' => ['unit' => ['Unknown unit.']],
                'import_error_code' => ImportErrorCode::UnitUnknown,
            ]);
        }
    }
}
