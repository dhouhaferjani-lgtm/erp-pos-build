<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TechnicianProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        Permission::findOrCreate('workshop.technicians.view', 'sanctum');
        Permission::findOrCreate('workshop.technicians.view_pay', 'sanctum');
        Permission::findOrCreate('workshop.technicians.view_pii', 'sanctum');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->givePermissionTo([
            'workshop.technicians.view',
            'workshop.technicians.view_pay',
            'workshop.technicians.view_pii',
        ]);

        // CompanyContextMiddleware requires an active membership; without it any
        // non-admin route returns 403 NO_COMPANY_ACCESS.
        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Sanctum::actingAs($this->manager);
    }

    private function makeProfile(?array $overrides = null): TechnicianProfile
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        return TechnicianProfile::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $user->id,
        ], $overrides ?? []));
    }

    public function test_index_returns_profiles_for_company(): void
    {
        $this->makeProfile();
        $this->makeProfile();

        $response = $this->getJson('/api/v1/workshop/technicians');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_active_only(): void
    {
        $this->makeProfile(['is_active' => true]);
        $this->makeProfile(['is_active' => false]);

        $response = $this->getJson('/api/v1/workshop/technicians?active_only=1');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_show_returns_profile_with_pay_and_pii_for_manager(): void
    {
        $profile = $this->makeProfile([
            'hourly_billing_rate' => '60.000',
            'national_id' => 'ID-999',
        ]);

        $response = $this->getJson("/api/v1/workshop/technicians/{$profile->id}");

        $response->assertOk();
        $response->assertJsonFragment(['hourly_billing_rate' => '60.000']);
        $response->assertJsonFragment(['national_id' => 'ID-999']);
    }

    public function test_show_returns_404_for_missing(): void
    {
        $response = $this->getJson('/api/v1/workshop/technicians/99999999-9999-9999-9999-999999999999');
        $response->assertStatus(404);
    }

    public function test_availability_yes_when_within_schedule(): void
    {
        $profile = $this->makeProfile();

        $response = $this->getJson('/api/v1/workshop/technicians/available?'.http_build_query([
            'technician_profile_id' => $profile->id,
            'starts_at' => '2026-04-20T10:00:00+02:00',
            'duration_minutes' => 60,
        ]));

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'yes']);
    }

    public function test_availability_partial_on_skill_mismatch(): void
    {
        $profile = $this->makeProfile([
            'specialties' => [SpecialtyCode::GeneralService->value],
        ]);

        $response = $this->getJson('/api/v1/workshop/technicians/available?'.http_build_query([
            'technician_profile_id' => $profile->id,
            'starts_at' => '2026-04-20T10:00:00+02:00',
            'duration_minutes' => 60,
            'required_specialty' => SpecialtyCode::HybridEv->value,
        ]));

        $response->assertOk();
        $response->assertJsonFragment([
            'status' => 'partial',
            'reason' => 'skill_mismatch',
        ]);
    }

    public function test_unauthorized_without_permission(): void
    {
        $noPermsUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Sanctum::actingAs($noPermsUser);

        $response = $this->getJson('/api/v1/workshop/technicians');
        $response->assertStatus(403);
    }
}
