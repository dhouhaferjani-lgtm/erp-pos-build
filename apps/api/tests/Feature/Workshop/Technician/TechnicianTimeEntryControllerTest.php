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
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TechnicianTimeEntryControllerTest extends TestCase
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
            'workshop.technicians.manage_time_entries',
        ] as $name) {
            Permission::findOrCreate($name, 'sanctum');
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager->givePermissionTo([
            'workshop.technicians.view',
            'workshop.technicians.manage_time_entries',
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

    private function bindWorkOrderStatus(?string $workOrderId, ?WorkOrderStatus $status): void
    {
        if ($workOrderId === null || $status === null) {
            return;
        }

        WorkOrder::factory()->create([
            'id' => $workOrderId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'status' => $status,
        ]);
    }

    public function test_index_lists_entries_with_date_filter(): void
    {
        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $this->profile->id,
            'started_at' => '2026-06-15 09:00:00',
            'ended_at' => '2026-06-15 11:00:00',
            'duration_minutes' => 120,
        ]);
        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $this->profile->id,
            'started_at' => '2026-08-15 09:00:00',
            'ended_at' => '2026-08-15 11:00:00',
            'duration_minutes' => 120,
        ]);

        $response = $this->getJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries?from=2026-06-01&to=2026-06-30"
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_store_creates_time_entry_without_work_order(): void
    {
        $this->bindWorkOrderStatus(null, null);

        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries",
            [
                'started_at' => '2026-06-15T09:00:00Z',
                'ended_at' => '2026-06-15T11:00:00Z',
                'entry_type' => TimeEntryType::NonBillable->value,
                'notes' => 'Training session',
            ]
        );

        $response->assertCreated();
        $this->assertDatabaseHas('workshop_technician_time_entries', [
            'technician_profile_id' => $this->profile->id,
            'entry_type' => 'non_billable',
        ]);
    }

    public function test_store_creates_time_entry_with_open_work_order(): void
    {
        $workOrderId = '11111111-1111-1111-1111-111111111111';
        $this->bindWorkOrderStatus($workOrderId, WorkOrderStatus::InProgress);

        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries",
            [
                'work_order_id' => $workOrderId,
                'started_at' => '2026-06-15T09:00:00Z',
                'ended_at' => '2026-06-15T11:00:00Z',
                'entry_type' => TimeEntryType::WorkOrder->value,
            ]
        );

        $response->assertCreated();
    }

    public function test_store_rejects_foreign_work_order_id(): void
    {
        $foreignTenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $foreignCompany = Company::factory()->create(['tenant_id' => $foreignTenant->id]);
        $foreignWorkOrder = WorkOrder::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
        ]);

        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries",
            [
                'work_order_id' => $foreignWorkOrder->id,
                'started_at' => '2026-06-15T09:00:00Z',
                'ended_at' => '2026-06-15T11:00:00Z',
                'entry_type' => TimeEntryType::WorkOrder->value,
            ]
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('workshop_technician_time_entries', [
            'technician_profile_id' => $this->profile->id,
            'work_order_id' => $foreignWorkOrder->id,
        ]);
    }

    public function test_update_patches_time_entry(): void
    {
        $this->bindWorkOrderStatus(null, null);

        $entry = TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $this->profile->id,
            'notes' => 'original',
            'work_order_id' => null,
        ]);

        $response = $this->patchJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries/{$entry->id}",
            ['notes' => 'updated']
        );

        $response->assertOk();
        $response->assertJsonPath('data.notes', 'updated');
    }

    public function test_update_rejects_foreign_work_order_id(): void
    {
        $foreignTenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $foreignCompany = Company::factory()->create(['tenant_id' => $foreignTenant->id]);
        $foreignWorkOrder = WorkOrder::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
        ]);

        $entry = TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $this->profile->id,
            'work_order_id' => null,
        ]);

        $response = $this->patchJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries/{$entry->id}",
            ['work_order_id' => $foreignWorkOrder->id]
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('workshop_technician_time_entries', [
            'id' => $entry->id,
            'work_order_id' => null,
        ]);
    }

    public function test_update_on_completed_work_order_is_locked(): void
    {
        $workOrderId = '22222222-2222-2222-2222-222222222222';
        $this->bindWorkOrderStatus($workOrderId, WorkOrderStatus::Completed);

        $entry = TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $this->profile->id,
            'work_order_id' => $workOrderId,
        ]);

        $response = $this->patchJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries/{$entry->id}",
            ['notes' => 'cannot change']
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TIME_ENTRY_LOCKED');
    }

    public function test_update_on_invoiced_work_order_is_locked(): void
    {
        $workOrderId = '33333333-3333-3333-3333-333333333333';
        $this->bindWorkOrderStatus($workOrderId, WorkOrderStatus::Invoiced);

        $entry = TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $this->profile->id,
            'work_order_id' => $workOrderId,
        ]);

        $response = $this->deleteJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries/{$entry->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TIME_ENTRY_LOCKED');
    }

    public function test_destroy_removes_entry_when_unlocked(): void
    {
        $this->bindWorkOrderStatus(null, null);

        $entry = TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $this->profile->id,
            'work_order_id' => null,
        ]);

        $response = $this->deleteJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries/{$entry->id}"
        );

        $response->assertNoContent();
        $this->assertDatabaseMissing('workshop_technician_time_entries', ['id' => $entry->id]);
    }

    public function test_technician_can_self_log_time_entry(): void
    {
        $this->bindWorkOrderStatus(null, null);

        // A technician user with only self-log permission (manage_time_entries
        // is granted to the technician role per the seeder update).
        $techUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $techUser->givePermissionTo('workshop.technicians.manage_time_entries');
        UserCompanyMembership::create([
            'user_id' => $techUser->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Technician,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);

        $this->profile->update(['user_id' => $techUser->id]);
        Sanctum::actingAs($techUser);

        $response = $this->postJson(
            "/api/v1/workshop/technicians/{$this->profile->id}/time-entries",
            [
                'started_at' => '2026-06-15T09:00:00Z',
                'ended_at' => '2026-06-15T10:00:00Z',
                'entry_type' => TimeEntryType::WorkOrder->value,
            ]
        );

        $response->assertCreated();
        // Self-logged entries must record manual source + recorded_by_user_id.
        $this->assertDatabaseHas('workshop_technician_time_entries', [
            'technician_profile_id' => $this->profile->id,
            'recorded_by_user_id' => $techUser->id,
            'source' => TimeEntrySource::Manual->value,
        ]);
    }
}
