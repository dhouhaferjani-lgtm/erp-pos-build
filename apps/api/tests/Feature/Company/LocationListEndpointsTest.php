<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LocationListEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Company $otherCompany;

    private Location $locationA;

    private Location $locationB;

    private Location $otherCompanyLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->otherCompany = Company::factory()->for($this->tenant)->create();

        $this->locationA = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Zulu Shop',
            'code' => 'LOC-A',
            'is_default' => true,
        ]);
        $this->locationB = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Alpha Shop',
            'code' => 'LOC-B',
            'is_default' => false,
        ]);
        $this->otherCompanyLocation = Location::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Company Shop',
            'code' => 'LOC-C',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_scoped_company_locations_excludes_unassigned_for_restricted_user(): void
    {
        $user = $this->createUser([$this->locationA->id]);

        $response = $this->request($user, '/api/v1/company/locations');

        $response->assertOk()
            ->assertExactJson([
                'data' => [$this->pickerRow($this->locationA)],
            ]);
    }

    public function test_scoped_company_locations_returns_all_for_null_membership(): void
    {
        $user = $this->createUser(null);

        $response = $this->request($user, '/api/v1/company/locations');

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    $this->pickerRow($this->locationA),
                    $this->pickerRow($this->locationB),
                ],
            ]);
    }

    public function test_management_all_returns_full_set_with_permission(): void
    {
        $user = $this->createUser([$this->locationA->id], grantManagement: true);

        $response = $this->request($user, '/api/v1/company/locations/all');

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    $this->pickerRow($this->locationA),
                    $this->pickerRow($this->locationB),
                ],
            ]);
    }

    public function test_management_all_forbidden_without_permission(): void
    {
        $user = $this->createUser([$this->locationA->id]);

        $this->request($user, '/api/v1/company/locations/all')
            ->assertForbidden();
    }

    public function test_transaction_destinations_include_active_company_locations_for_transfer_permission(): void
    {
        $this->locationB->update(['is_active' => false]);
        $user = $this->createUser([$this->locationA->id]);
        $this->grant($user, 'inventory.transfers.create');

        $response = $this->request($user, '/api/v1/company/locations/transaction-destinations');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->locationA->id)
            ->assertJsonMissing(['id' => $this->otherCompanyLocation->id]);
    }

    public function test_transaction_destinations_are_forbidden_without_transaction_permission(): void
    {
        $user = $this->createUser([$this->locationA->id]);

        $this->request($user, '/api/v1/company/locations/transaction-destinations')
            ->assertForbidden();
    }

    public function test_wired_inventory_locations_index_honors_allowed_set(): void
    {
        $user = $this->createUser([$this->locationA->id]);

        $response = $this->request($user, '/api/v1/locations');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $this->locationA->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'company_id',
                    'name',
                    'code',
                    'type',
                    'is_default',
                    'is_active',
                ]],
                'meta' => ['timestamp', 'request_id'],
            ]);
    }

    public function test_wired_inventory_locations_index_fails_closed_for_memberless_multi_company_user(): void
    {
        $user = User::factory()->for($this->tenant)->create(['status' => UserStatus::Active]);
        $this->grant($user, 'inventory.view');

        $this->withoutMiddleware(CompanyContextMiddleware::class);

        $response = $this->request($user, '/api/v1/locations');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_absent_membership_returns_an_empty_scoped_list(): void
    {
        $user = User::factory()->for($this->tenant)->create();
        $this->grant($user, 'inventory.view');

        $this->withoutMiddleware(CompanyContextMiddleware::class);

        $this->request($user, '/api/v1/company/locations')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_inactive_membership_returns_an_empty_scoped_list(): void
    {
        $user = $this->createUser([$this->locationA->id], membershipStatus: MembershipStatus::Suspended);

        $this->withoutMiddleware(CompanyContextMiddleware::class);

        $this->request($user, '/api/v1/company/locations')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_locations_from_another_company_never_leak(): void
    {
        $user = $this->createUser([$this->locationA->id, $this->otherCompanyLocation->id], grantManagement: true);

        $scoped = $this->request($user, '/api/v1/company/locations');
        $management = $this->request($user, '/api/v1/company/locations/all');

        $scoped->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->locationA->id);
        $management->assertOk()->assertJsonCount(2, 'data');
        $this->assertNotContains(
            $this->otherCompanyLocation->id,
            array_column($management->json('data'), 'id'),
        );
    }

    /**
     * @param  list<string>|null  $allowedLocationIds
     */
    private function createUser(
        ?array $allowedLocationIds,
        bool $grantManagement = false,
        MembershipStatus $membershipStatus = MembershipStatus::Active,
    ): User {
        $user = User::factory()->for($this->tenant)->create([
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'allowed_location_ids' => $allowedLocationIds,
            'status' => $membershipStatus,
        ]);

        $this->grant($user, 'inventory.view');
        if ($grantManagement) {
            $this->grant($user, 'users.manage_location_access');
        }

        return $user;
    }

    private function grant(User $user, string $permission): void
    {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
    }

    private function request(User $user, string $uri): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson($uri);
    }

    /**
     * @return array{id: string, name: string, code: string|null, type: string, is_default: bool, is_active: bool}
     */
    private function pickerRow(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'code' => $location->code,
            'type' => $location->type->value,
            'is_default' => $location->is_default,
            'is_active' => $location->is_active,
        ];
    }
}
