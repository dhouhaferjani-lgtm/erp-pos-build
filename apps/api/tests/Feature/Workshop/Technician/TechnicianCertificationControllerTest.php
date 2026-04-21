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
use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TechnicianCertificationControllerTest extends TestCase
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
            'workshop.technicians.manage_certifications',
        ] as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->givePermissionTo([
            'workshop.technicians.view',
            'workshop.technicians.manage_certifications',
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

    public function test_index_lists_certifications_for_technician(): void
    {
        TechnicianCertification::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
        ]);

        $response = $this->getJson("/api/v1/workshop/technicians/{$this->profile->id}/certifications");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_store_creates_certification(): void
    {
        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/certifications",
            [
                'certification_name' => 'ASE Master Technician',
                'issuing_body' => 'ASE',
                'certificate_number' => 'ASE-1234',
                'issued_at' => '2024-01-15',
                'expires_at' => '2029-01-15',
            ]
        );

        $response->assertCreated();
        $response->assertJsonPath('data.certification_name', 'ASE Master Technician');
        $this->assertDatabaseHas('workshop_technician_certifications', [
            'technician_profile_id' => $this->profile->id,
            'certification_name' => 'ASE Master Technician',
        ]);
    }

    public function test_update_patches_certification(): void
    {
        $cert = TechnicianCertification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
            'certification_name' => 'Old name',
        ]);

        $response = $this->patchJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/certifications/{$cert->id}",
            ['certification_name' => 'Updated name']
        );

        $response->assertOk();
        $response->assertJsonPath('data.certification_name', 'Updated name');
    }

    public function test_destroy_removes_certification(): void
    {
        $cert = TechnicianCertification::factory()->create([
            'tenant_id' => $this->tenant->id,
            'technician_profile_id' => $this->profile->id,
        ]);

        $response = $this->deleteJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/certifications/{$cert->id}"
        );

        $response->assertNoContent();
        $this->assertDatabaseMissing('workshop_technician_certifications', ['id' => $cert->id]);
    }

    public function test_store_denied_without_manage_permission(): void
    {
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Permission::findOrCreate('workshop.technicians.view', 'sanctum');
        $viewer->givePermissionTo('workshop.technicians.view');
        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);
        Sanctum::actingAs($viewer);

        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/certifications",
            ['certification_name' => 'Nope']
        );

        $response->assertStatus(403);
    }
}
