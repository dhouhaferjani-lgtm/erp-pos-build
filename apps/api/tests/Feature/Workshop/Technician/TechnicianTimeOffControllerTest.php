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
use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TechnicianTimeOffControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $manager;

    private TechnicianProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([
            'workshop.technicians.view',
            'workshop.technicians.manage_time_off',
        ] as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->givePermissionTo([
            'workshop.technicians.view',
            'workshop.technicians.manage_time_off',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Sanctum::actingAs($this->manager);

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->profile = TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_index_lists_time_off_with_date_filter(): void
    {
        TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
            'starts_at' => '2026-06-01 00:00:00',
            'ends_at' => '2026-06-03 23:59:59',
        ]);
        TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-08-03 23:59:59',
        ]);

        $response = $this->getJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-off?from=2026-06-01&to=2026-06-30"
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_store_creates_time_off(): void
    {
        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-off",
            [
                'reason_code' => TimeOffReason::Vacation->value,
                'starts_at' => '2026-07-01T00:00:00Z',
                'ends_at' => '2026-07-05T23:59:59Z',
                'is_full_day' => true,
                'notes' => 'Summer holiday',
            ]
        );

        $response->assertCreated();
        $response->assertJsonPath('data.reason_code', 'vacation');
        $this->assertDatabaseHas('workshop_technician_time_off', [
            'technician_profile_id' => $this->profile->id,
            'reason_code' => 'vacation',
        ]);
    }

    public function test_store_rejects_overlap_with_existing_time_off(): void
    {
        TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-05 23:59:59',
        ]);

        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-off",
            [
                'reason_code' => TimeOffReason::Sick->value,
                'starts_at' => '2026-07-03T00:00:00Z',
                'ends_at' => '2026-07-07T23:59:59Z',
                'is_full_day' => true,
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TIME_OFF_OVERLAP');
    }

    public function test_update_patches_time_off(): void
    {
        $to = TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
            'notes' => 'original',
        ]);

        $response = $this->patchJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-off/{$to->id}",
            ['notes' => 'updated']
        );

        $response->assertOk();
        $response->assertJsonPath('data.notes', 'updated');
    }

    public function test_destroy_removes_time_off(): void
    {
        $to = TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
        ]);

        $response = $this->deleteJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-off/{$to->id}"
        );

        $response->assertNoContent();
        $this->assertDatabaseMissing('workshop_technician_time_off', ['id' => $to->id]);
    }

    public function test_technician_can_view_own_time_off_without_manage_permission(): void
    {
        // A technician without manage_time_off but with view permission
        // can view their own time-off entries via the self-view policy.
        $techUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $techUser->givePermissionTo('workshop.technicians.view');
        UserCompanyMembership::create([
            'user_id' => $techUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Technician,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        // Rebind profile to this user so the self-view path activates.
        $this->profile->update(['user_id' => $techUser->id]);
        TechnicianTimeOff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
        ]);

        Sanctum::actingAs($techUser);
        $response = $this->getJson("/api/v1/workshop/technicians/{$this->profile->id}/time-off");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }
}
