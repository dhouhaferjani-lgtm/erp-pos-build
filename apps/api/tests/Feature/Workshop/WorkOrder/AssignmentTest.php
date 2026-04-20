<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Identity\Domain\User;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\WorkOrder\Application\Commands\AssignTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\SetPrimaryTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\Commands\UnassignTechnicianCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderAssignmentService;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_technician_creates_row_and_sets_primary_when_lead(): void
    {
        $wo = WorkOrder::factory()->create();
        $tech = TechnicianProfile::factory()->create([
            'tenant_id' => $wo->tenant_id,
            'company_id' => $wo->company_id,
        ]);
        $user = User::factory()->create(['tenant_id' => $wo->tenant_id]);

        $service = $this->app->make(WorkOrderAssignmentService::class);
        $assignment = $service->assign(new AssignTechnicianCommand(
            work_order_id: $wo->id,
            technician_profile_id: $tech->id,
            is_lead: true,
            assigned_by_user_id: $user->id,
            notes: 'Main tech',
        ));

        $this->assertTrue($assignment->is_lead);
        $this->assertNull($assignment->unassigned_at);
        $this->assertSame($tech->id, $wo->fresh()->primary_technician_profile_id);
    }

    public function test_second_lead_assignment_supersedes_first(): void
    {
        $wo = WorkOrder::factory()->create();
        $tenant = $wo->tenant_id;
        $company = $wo->company_id;
        $tech1 = TechnicianProfile::factory()->create(['tenant_id' => $tenant, 'company_id' => $company]);
        $tech2 = TechnicianProfile::factory()->create(['tenant_id' => $tenant, 'company_id' => $company]);
        $user = User::factory()->create(['tenant_id' => $tenant]);

        $service = $this->app->make(WorkOrderAssignmentService::class);
        $service->assign(new AssignTechnicianCommand(
            work_order_id: $wo->id,
            technician_profile_id: $tech1->id,
            is_lead: true,
            assigned_by_user_id: $user->id,
            notes: null,
        ));
        $service->assign(new AssignTechnicianCommand(
            work_order_id: $wo->id,
            technician_profile_id: $tech2->id,
            is_lead: true,
            assigned_by_user_id: $user->id,
            notes: null,
        ));

        $this->assertSame(
            1,
            WorkOrderAssignment::query()
                ->where('work_order_id', $wo->id)
                ->where('is_lead', true)
                ->whereNull('unassigned_at')
                ->count(),
        );
        $this->assertSame($tech2->id, $wo->fresh()->primary_technician_profile_id);
    }

    public function test_unassign_clears_primary_when_removing_lead(): void
    {
        $wo = WorkOrder::factory()->create();
        $tech = TechnicianProfile::factory()->create([
            'tenant_id' => $wo->tenant_id,
            'company_id' => $wo->company_id,
        ]);
        $user = User::factory()->create(['tenant_id' => $wo->tenant_id]);

        $service = $this->app->make(WorkOrderAssignmentService::class);
        $assignment = $service->assign(new AssignTechnicianCommand(
            work_order_id: $wo->id,
            technician_profile_id: $tech->id,
            is_lead: true,
            assigned_by_user_id: $user->id,
            notes: null,
        ));

        $service->unassign(new UnassignTechnicianCommand(
            work_order_id: $wo->id,
            assignment_id: $assignment->id,
        ));

        $this->assertNull($wo->fresh()->primary_technician_profile_id);
    }

    public function test_set_primary_elects_existing_assignment_as_lead(): void
    {
        $wo = WorkOrder::factory()->create();
        $tech1 = TechnicianProfile::factory()->create(['tenant_id' => $wo->tenant_id, 'company_id' => $wo->company_id]);
        $tech2 = TechnicianProfile::factory()->create(['tenant_id' => $wo->tenant_id, 'company_id' => $wo->company_id]);
        $user = User::factory()->create(['tenant_id' => $wo->tenant_id]);

        $service = $this->app->make(WorkOrderAssignmentService::class);
        // tech1 initial lead
        $service->assign(new AssignTechnicianCommand(
            work_order_id: $wo->id,
            technician_profile_id: $tech1->id,
            is_lead: true,
            assigned_by_user_id: $user->id,
            notes: null,
        ));
        // tech2 assigned non-lead
        $service->assign(new AssignTechnicianCommand(
            work_order_id: $wo->id,
            technician_profile_id: $tech2->id,
            is_lead: false,
            assigned_by_user_id: $user->id,
            notes: null,
        ));

        $service->setPrimary(new SetPrimaryTechnicianCommand(
            work_order_id: $wo->id,
            technician_profile_id: $tech2->id,
        ));

        $this->assertSame($tech2->id, $wo->fresh()->primary_technician_profile_id);
    }
}
