<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Scheduling\Application\Services\AppointmentConversionService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentService as AppointmentServiceModel;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for audit finding 🟠-1 (C.1.3).
 *
 * Before the fix, AppointmentConversionService only wrote the forward link
 * (appointment.work_order_id = $wo->id). The reverse link
 * (workshop_work_orders.appointment_id = $appointment->id) was never
 * populated, breaking downstream analytics that correlate WO throughput with
 * appointment source (storefront vs walk-in) without a second lookup.
 *
 * Asserts:
 *  - Forward link (existing behavior): appointment.work_order_id points to
 *    the new WO.
 *  - **Reverse link (the fix)**: WO.appointment_id points back to the source
 *    appointment.
 *  - Customer partner + vehicle round-trip correctly (regression coverage
 *    for the conversion path).
 */
final class AppointmentConversionWritesBidirLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversion_writes_bidirectional_link_appointment_work_order(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'currency' => 'TND',
        ]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        User::factory()->create(['tenant_id' => $tenant->id]);
        $serviceRef = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $appointment = Appointment::factory()->confirmed()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'customer_partner_id' => $partner->id,
            'vehicle_id' => $vehicle->id,
        ]);
        AppointmentServiceModel::factory()->create([
            'tenant_id' => $tenant->id,
            'appointment_id' => $appointment->id,
            'service_ref_type' => AppointmentServiceModel::REF_TYPE_SERVICE,
            'service_ref_id' => $serviceRef->id,
        ]);

        /** @var AppointmentConversionService $conversion */
        $conversion = $this->app->make(AppointmentConversionService::class);

        $workOrder = $conversion->convertToWorkOrder($appointment->id);

        // Forward link (existing behavior)
        $appointment->refresh();
        self::assertSame(
            $workOrder->id,
            $appointment->work_order_id,
            'appointment.work_order_id must be set by the conversion (forward link).',
        );

        // Reverse link — THIS IS THE FIX
        $freshWo = WorkOrder::query()->findOrFail($workOrder->id);
        self::assertSame(
            $appointment->id,
            $freshWo->appointment_id,
            'workshop_work_orders.appointment_id must point back to the source appointment (reverse link — 🟠-1).',
        );

        // Regression: customer + vehicle round-trip
        self::assertSame($partner->id, $freshWo->customer_partner_id);
        self::assertSame($vehicle->id, $freshWo->vehicle_id);
    }
}
