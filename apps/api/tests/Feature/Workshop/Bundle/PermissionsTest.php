<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\Enums\BundlePricingMode;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Bundle\Domain\ServiceBundleVehicleApplicability;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_user_without_view_cannot_list(): void
    {
        $user = User::factory()->for($this->tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/workshop/bundles');
        $response->assertForbidden();
    }

    public function test_technician_can_view_but_not_manage(): void
    {
        $user = User::factory()->for($this->tenant)->create();
        $user->givePermissionTo('workshop-bundles.view');
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'technician',
        ]);
        $this->actingAs($user);

        $this->getJson('/api/v1/workshop/bundles')->assertOk();

        $createResponse = $this->postJson('/api/v1/workshop/bundles', [
            'code' => 'X', 'name' => 'X', 'currency' => 'TND',
            'pricing_mode' => BundlePricingMode::Standard->value,
        ]);
        $createResponse->assertForbidden();
    }

    public function test_operator_can_create_update_delete(): void
    {
        $user = User::factory()->for($this->tenant)->create();
        $user->givePermissionTo(['workshop-bundles.view', 'workshop-bundles.manage']);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);
        $this->actingAs($user);

        $createResponse = $this->postJson('/api/v1/workshop/bundles', [
            'code' => 'VIDANGE-10K-DIESEL',
            'name' => 'Vidange 10k diesel',
            'currency' => 'TND',
            'pricing_mode' => BundlePricingMode::FixedBundle->value,
            'base_price' => '130',
            'tax_rate' => '19',
        ]);
        $createResponse->assertCreated();
        /** @var array{data: array{id: string}} $body */
        $body = $createResponse->json();
        $bundleId = $body['data']['id'];

        $showResponse = $this->getJson("/api/v1/workshop/bundles/{$bundleId}");
        $showResponse->assertOk();

        $updateResponse = $this->patchJson("/api/v1/workshop/bundles/{$bundleId}", [
            'name' => 'Updated',
        ]);
        $updateResponse->assertOk();

        $deleteResponse = $this->deleteJson("/api/v1/workshop/bundles/{$bundleId}");
        $deleteResponse->assertNoContent();

        $this->assertSoftDeleted('workshop_service_bundles', ['id' => $bundleId]);
    }

    public function test_applicable_endpoint_returns_bundles(): void
    {
        $user = User::factory()->for($this->tenant)->create();
        $user->givePermissionTo('workshop-bundles.view');
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        $this->actingAs($user);

        $bundle = ServiceBundle::factory()->forCompany($this->tenant->id, $this->company->id)->create();
        ServiceBundleVehicleApplicability::factory()
            ->forBundle($bundle)->universal()->create();

        $response = $this->getJson('/api/v1/workshop/bundles/applicable');
        $response->assertOk();
    }
}
