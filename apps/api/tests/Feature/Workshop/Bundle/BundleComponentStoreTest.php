<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleComponent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for `POST /api/v1/workshop/bundles/{id}/components`.
 *
 * Covers cycle-detection as a structured HTTP 422 response rather than
 * an unhandled 500 with leaked stack trace.
 */
final class BundleComponentStoreTest extends TestCase
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

    public function test_post_rejects_nested_bundle_that_creates_cycle_with_422(): void
    {
        $parent = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $child = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $unit = Unit::factory()->create();

        // parent → child (child is nested inside parent)
        ServiceBundleComponent::factory()
            ->forBundle($parent)
            ->nestedBundle($child)
            ->create(['unit_id' => $unit->id]);

        $this->actingAs($this->user);

        // Attempting to add parent as a nested component of child would
        // create the cycle child → parent → child.
        $response = $this->postJson(
            "/api/v1/workshop/bundles/{$child->id}/components",
            [
                'component_type' => BundleComponentType::NestedBundle->value,
                'component_id' => $parent->id,
                'quantity' => '1.000',
                'unit_id' => $unit->id,
            ],
        );

        $response->assertStatus(422);
        /** @var array{error?: array{code?: string, message?: string}} $body */
        $body = $response->json();
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('BUNDLE_CYCLE', $body['error']['code'] ?? null);
        $this->assertIsString($body['error']['message'] ?? null);
    }

    public function test_post_rejects_self_reference_with_422(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $unit = Unit::factory()->create();

        $this->actingAs($this->user);

        // Adding the bundle itself as a nested component must be a cycle.
        $response = $this->postJson(
            "/api/v1/workshop/bundles/{$bundle->id}/components",
            [
                'component_type' => BundleComponentType::NestedBundle->value,
                'component_id' => $bundle->id,
                'quantity' => '1.000',
                'unit_id' => $unit->id,
            ],
        );

        $response->assertStatus(422);
        /** @var array{error?: array{code?: string}} $body */
        $body = $response->json();
        $this->assertSame('BUNDLE_CYCLE', $body['error']['code'] ?? null);
    }
}
