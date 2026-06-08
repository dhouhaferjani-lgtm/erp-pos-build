<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
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
 * Feature tests for `PATCH /api/v1/workshop/bundles/{id}/components/{componentId}`.
 *
 * Covers partial update, cycle-detection on NestedBundle component type
 * changes, and standard 404/403 failure modes.
 */
final class BundleComponentPatchTest extends TestCase
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

    public function test_patch_updates_only_provided_fields(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $unit = Unit::factory()->create();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $component = ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->part($product, '1.000')
            ->create(['unit_id' => $unit->id, 'is_optional' => false, 'notes' => 'original']);

        $this->actingAs($this->user);
        $response = $this->patchJson(
            "/api/v1/workshop/bundles/{$bundle->id}/components/{$component->id}",
            ['quantity' => '2.500'],
        );

        $response->assertOk();
        /** @var array{data: array{id: string, quantity: string, notes: string, is_optional: bool}} $body */
        $body = $response->json();
        $this->assertSame($component->id, $body['data']['id']);
        $this->assertSame('2.5000', $body['data']['quantity']);
        $this->assertSame('original', $body['data']['notes']);
        $this->assertFalse($body['data']['is_optional']);
    }

    public function test_patch_rejects_nested_bundle_change_that_creates_cycle(): void
    {
        $parent = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $child = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $unit = Unit::factory()->create();

        // parent → child (child is nested inside parent)
        ServiceBundleComponent::factory()
            ->forBundle($parent)
            ->nestedBundle($child)
            ->create(['unit_id' => $unit->id]);

        // A component on the child that we'll try to mutate into pointing back to parent.
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $childComponent = ServiceBundleComponent::factory()
            ->forBundle($child)
            ->part($product, '1.000')
            ->create(['unit_id' => $unit->id]);

        $this->actingAs($this->user);
        $response = $this->patchJson(
            "/api/v1/workshop/bundles/{$child->id}/components/{$childComponent->id}",
            [
                'component_type' => BundleComponentType::NestedBundle->value,
                'component_id' => $parent->id,
            ],
        );

        $response->assertStatus(422);
        /** @var array{error?: array{code?: string}} $body */
        $body = $response->json();
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('BUNDLE_CYCLE', $body['error']['code'] ?? null);
    }

    public function test_patch_returns_404_when_component_is_missing(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $missingId = '00000000-0000-4000-8000-000000000000';

        $this->actingAs($this->user);
        $this->patchJson(
            "/api/v1/workshop/bundles/{$bundle->id}/components/{$missingId}",
            ['quantity' => '2.000'],
        )->assertNotFound();
    }

    public function test_patch_returns_403_without_manage_permission(): void
    {
        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        $unit = Unit::factory()->create();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $component = ServiceBundleComponent::factory()
            ->forBundle($bundle)
            ->part($product, '1.000')
            ->create(['unit_id' => $unit->id]);

        $viewer = User::factory()->for($this->tenant)->create();
        $viewer->givePermissionTo('workshop-bundles.view');
        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $this->actingAs($viewer);
        $this->patchJson(
            "/api/v1/workshop/bundles/{$bundle->id}/components/{$component->id}",
            ['quantity' => '2.000'],
        )->assertForbidden();
    }
}
