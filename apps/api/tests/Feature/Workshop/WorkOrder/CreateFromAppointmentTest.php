<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderCreationServiceInterface;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PlannedServiceRef;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateFromAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_from_appointment_materializes_services_as_labor_lines(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'TND']);
        $partner = Partner::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        $vehicle = Vehicle::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        User::factory()->create(['tenant_id' => $tenant->id]);
        $service = Service::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);

        // Real appointment fixture — post 🟠-1 fix, workshop_work_orders
        // .appointment_id is an FK to scheduling_appointments so a fake UUID
        // would fail referential integrity.
        $appointment = Appointment::factory()->confirmed()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'customer_partner_id' => $partner->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $creation = $this->app->make(WorkOrderCreationServiceInterface::class);
        $wo = $creation->createFromAppointment(
            appointmentId: $appointment->id,
            plannedServices: [
                new PlannedServiceRef(
                    service_ref_type: PlannedServiceRef::TYPE_SERVICE,
                    service_ref_id: $service->id,
                ),
            ],
            vehicleId: $vehicle->id,
            partnerId: $partner->id,
            tenantId: $tenant->id,
            companyId: $company->id,
        );

        $this->assertSame(WorkOrderStatus::Received, $wo->status);
        $this->assertSame($vehicle->id, $wo->vehicle_id);
        $this->assertSame($partner->id, $wo->customer_partner_id);
        $this->assertSame($appointment->id, $wo->appointment_id);
        $this->assertSame('TND', $wo->currency);
        $refreshed = WorkOrder::query()->with('lines')->findOrFail($wo->id);
        $this->assertCount(1, $refreshed->lines);
    }
}
