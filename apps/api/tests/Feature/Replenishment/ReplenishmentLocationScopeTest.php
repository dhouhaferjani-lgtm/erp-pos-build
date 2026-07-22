<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReplenishmentLocationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $locationA;

    private Location $locationB;

    private Product $product;

    private User $restricted;

    private User $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->locationA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Shop A']);
        $this->locationB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Shop B']);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->restricted = $this->userWithMembership('restricted-replenishment@example.test', [$this->locationA->id]);
        $this->processor = $this->userWithMembership(
            'processor-replenishment@example.test',
            null,
            ['replenishment.process'],
        );
    }

    public function test_restricted_requester_only_sees_requests_at_allowed_location(): void
    {
        $visible = $this->capture($this->locationA, $this->restricted);
        $hidden = $this->capture($this->locationB, $this->processor);

        $response = $this->actingAs($this->restricted)
            ->getJson('/api/v1/replenishment-requests?status=open')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_processor_sees_requests_at_all_company_locations(): void
    {
        $atA = $this->capture($this->locationA, $this->restricted);
        $atB = $this->capture($this->locationB, $this->processor);

        $response = $this->actingAs($this->processor)
            ->getJson('/api/v1/replenishment-requests?status=open')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($atA->id, $ids);
        $this->assertContains($atB->id, $ids);
    }

    public function test_restricted_requester_cannot_request_an_out_of_scope_location_filter(): void
    {
        $this->capture($this->locationA, $this->restricted);
        $this->capture($this->locationB, $this->processor);

        $this->actingAs($this->restricted)
            ->getJson('/api/v1/replenishment-requests?status=open&location_ids[]='.$this->locationB->id)
            ->assertForbidden();
    }

    private function userWithMembership(string $email, ?array $allowed, array $permissions = []): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => $email,
        ]);
        $user->givePermissionTo(['replenishment.view', 'replenishment.create', ...$permissions]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'allowed_location_ids' => $allowed,
            'status' => 'active',
        ]);

        return $user;
    }

    private function capture(Location $location, User $user): ReplenishmentRequest
    {
        return app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $location->id,
            productId: $this->product->id,
            variantId: null,
            requestedQty: null,
            note: null,
            requestedByUserId: $user->id,
            channel: ReplenishmentChannel::Web,
        ));
    }
}
