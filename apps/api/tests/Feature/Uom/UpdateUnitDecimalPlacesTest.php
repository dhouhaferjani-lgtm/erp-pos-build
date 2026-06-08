<?php

declare(strict_types=1);

namespace Tests\Feature\Uom;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Jobs\RevalidateUnitQuantityScaleJob;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdateUnitDecimalPlacesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $permissions = ['uom.view', 'uom.create', 'uom.edit', 'uom.delete'];
        foreach ($permissions as $permission) {
            Permission::create([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'unit-precision@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->user->companyMemberships()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
            'role' => 'admin',
        ]);
        $this->user->givePermissionTo($permissions);

        Sanctum::actingAs($this->user);
    }

    private function makeCustomUnit(int $decimalPlaces = 2): Unit
    {
        $category = UnitCategory::factory()->create(['tenant_id' => $this->tenant->id]);

        return Unit::factory()->create([
            'category_id' => $category->id,
            'tenant_id' => $this->tenant->id,
            'is_system' => false,
            'code' => 'CUSTOM',
            'name' => 'Custom Unit',
            'decimal_places' => $decimalPlaces,
            'rounding_method' => RoundingMethod::HalfUp,
        ]);
    }

    public function test_can_update_decimal_places_and_rounding_method(): void
    {
        Bus::fake();

        $unit = $this->makeCustomUnit(decimalPlaces: 2);

        $response = $this->putJson("/api/v1/uom/units/{$unit->id}", [
            'decimal_places' => 4,
            'rounding_method' => RoundingMethod::Floor->value,
        ]);

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'decimalPlaces' => 4,
                    'roundingMethod' => 'floor',
                ],
            ]);

        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'decimal_places' => 4,
            'rounding_method' => 'floor',
        ]);
    }

    public function test_decimal_places_change_dispatches_cascade_job(): void
    {
        Bus::fake();

        $unit = $this->makeCustomUnit(decimalPlaces: 4);

        $this->putJson("/api/v1/uom/units/{$unit->id}", [
            'decimal_places' => 2,
        ])->assertOk();

        Bus::assertDispatched(
            RevalidateUnitQuantityScaleJob::class,
            fn (RevalidateUnitQuantityScaleJob $job): bool => $job->unitId === $unit->id
                && $job->newDecimalPlaces === 2
        );
    }

    public function test_no_cascade_job_when_decimal_places_unchanged(): void
    {
        Bus::fake();

        $unit = $this->makeCustomUnit(decimalPlaces: 4);

        $this->putJson("/api/v1/uom/units/{$unit->id}", [
            'name' => 'Renamed Unit',
        ])->assertOk();

        Bus::assertNotDispatched(RevalidateUnitQuantityScaleJob::class);
    }

    public function test_rejects_out_of_range_decimal_places(): void
    {
        Bus::fake();

        $unit = $this->makeCustomUnit();

        $this->putJson("/api/v1/uom/units/{$unit->id}", [
            'decimal_places' => 11,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['decimal_places']]]);

        $this->putJson("/api/v1/uom/units/{$unit->id}", [
            'decimal_places' => -1,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        Bus::assertNotDispatched(RevalidateUnitQuantityScaleJob::class);
    }

    public function test_rejects_invalid_rounding_method(): void
    {
        Bus::fake();

        $unit = $this->makeCustomUnit();

        $this->putJson("/api/v1/uom/units/{$unit->id}", [
            'rounding_method' => 'banker',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['rounding_method']]]);
    }

    public function test_cascade_job_logs_warning_for_quantity_exceeding_new_scale_without_mutating(): void
    {
        $category = UnitCategory::factory()->create(['tenant_id' => $this->tenant->id]);
        $unit = Unit::factory()->create([
            'category_id' => $category->id,
            'tenant_id' => $this->tenant->id,
            'is_system' => false,
            'code' => 'KG',
            'decimal_places' => 0,
        ]);

        $location = Location::factory()->create(['company_id' => $this->company->id]);

        $product = Product::factory()->goods()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'unit_id' => $unit->id,
        ]);

        // 12.5 has 1 decimal place — violates a new scale of 0.
        $stockLevel = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => '12.5',
            'reserved' => '0',
        ]);

        $logSpy = Log::spy();

        (new RevalidateUnitQuantityScaleJob(
            unitId: $unit->id,
            unitCode: $unit->code,
            tenantId: $this->tenant->id,
            newDecimalPlaces: 0,
        ))->handle();

        $logSpy->shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'uom.unit_scale_cascade.quantity_violation'
                && $context['stock_level_id'] === $stockLevel->id
                && $context['field'] === 'quantity')
            ->once();

        // Data must NOT have been mutated by the audit job.
        $refreshed = $stockLevel->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('12.5000', $refreshed->quantity);
    }

    public function test_requires_edit_permission(): void
    {
        $unit = $this->makeCustomUnit();

        $userWithoutPermission = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $userWithoutPermission->companyMemberships()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
            'role' => 'viewer',
        ]);

        Sanctum::actingAs($userWithoutPermission);

        $response = $this->putJson("/api/v1/uom/units/{$unit->id}", [
            'decimal_places' => 3,
        ]);

        $this->assertTrue(
            in_array($response->status(), [401, 403], true),
            'Expected 401/403 without uom.edit, got '.$response->status()
        );
    }
}
