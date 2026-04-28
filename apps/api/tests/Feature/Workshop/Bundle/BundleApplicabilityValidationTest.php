<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Enums\VehicleTypeRef;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for `PUT /api/v1/workshop/bundles/{id}/vehicle-applicabilities`.
 *
 * Enforces the server-side invariant: each applicability row must be
 * either fully universal (both `platform_vehicle_id` and `vehicle_type`
 * null) or fully scoped (both non-null). This mirrors the frontend
 * guard and prevents bypass via API-direct clients.
 *
 * Note: pre-existing both-null rows (e.g. the seeded DIAGNOSTIC-OBD
 * bundle's universal applicability) are intentionally NOT back-filled;
 * the rule enforces new writes only. Universal applicability via
 * {platform_vehicle_id: null, vehicle_type: null} is valid because
 * both keys are null together.
 */
final class BundleApplicabilityValidationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->for($this->tenant)->create();

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo(['workshop-bundles.view', 'workshop-bundles.manage']);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);
    }

    public function test_universal_applicability_both_null_is_valid(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        $this->actingAs($this->user);

        $response = $this->putJson(
            "/api/v1/workshop/bundles/{$bundle->id}/vehicle-applicabilities",
            [
                'applicabilities' => [
                    [
                        'platform_vehicle_id' => null,
                        'vehicle_type' => null,
                        'vehicle_display' => null,
                    ],
                ],
            ],
        );

        $response->assertOk();
    }

    public function test_fully_scoped_applicability_both_non_null_is_valid(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        $this->actingAs($this->user);

        $response = $this->putJson(
            "/api/v1/workshop/bundles/{$bundle->id}/vehicle-applicabilities",
            [
                'applicabilities' => [
                    [
                        'platform_vehicle_id' => Str::uuid()->toString(),
                        'vehicle_type' => VehicleTypeRef::Pc->value,
                        'vehicle_display' => 'Peugeot 308',
                    ],
                ],
            ],
        );

        $response->assertOk();
    }

    public function test_rejects_row_with_vehicle_id_but_null_type(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        $this->actingAs($this->user);

        $response = $this->putJson(
            "/api/v1/workshop/bundles/{$bundle->id}/vehicle-applicabilities",
            [
                'applicabilities' => [
                    [
                        'platform_vehicle_id' => Str::uuid()->toString(),
                        'vehicle_type' => null,
                        'vehicle_display' => 'Partial',
                    ],
                ],
            ],
        );

        $response->assertStatus(422);
        /** @var array{error?: array{code?: string, errors?: array<string, array<int, string>>}} $body */
        $body = $response->json();
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('VALIDATION_ERROR', $body['error']['code'] ?? null);
    }

    public function test_rejects_row_with_vehicle_type_but_null_id(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();

        $this->actingAs($this->user);

        $response = $this->putJson(
            "/api/v1/workshop/bundles/{$bundle->id}/vehicle-applicabilities",
            [
                'applicabilities' => [
                    [
                        'platform_vehicle_id' => null,
                        'vehicle_type' => VehicleTypeRef::Pc->value,
                        'vehicle_display' => 'Partial',
                    ],
                ],
            ],
        );

        $response->assertStatus(422);
        /** @var array{error?: array{code?: string}} $body */
        $body = $response->json();
        $this->assertSame('VALIDATION_ERROR', $body['error']['code'] ?? null);
    }
}
