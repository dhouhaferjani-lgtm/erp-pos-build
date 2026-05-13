<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Infrastructure\Listeners\WriteMileageReadingFromWorkOrderCompleted;
use App\Modules\Workshop\WorkOrder\Domain\Events\WorkOrderCompletedV2;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WriteMileageReadingFromWorkOrderCompletedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_listener_writes_mileage_reading_when_completion_mileage_provided(): void
    {
        $workOrder = WorkOrder::factory()->inProgress()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
        ]);

        $completedAt = new \DateTimeImmutable('2026-04-19T15:30:00', new \DateTimeZone('UTC'));
        $event = new WorkOrderCompletedV2(
            work_order_id: $workOrder->id,
            tenant_id: $workOrder->tenant_id,
            company_id: $workOrder->company_id,
            completion_mileage: 42000,
            completed_at: $completedAt,
        );

        /** @var WriteMileageReadingFromWorkOrderCompleted $listener */
        $listener = $this->app->make(WriteMileageReadingFromWorkOrderCompleted::class);
        $listener->handle($event);

        $this->assertDatabaseHas('vehicle_mileage_readings', [
            'vehicle_id' => $this->vehicle->id,
            'mileage' => 42000,
            'source' => MileageSource::WorkOrderCompletion->value,
            'context_work_order_id' => $workOrder->id,
        ]);
    }

    public function test_listener_skips_silently_when_completion_mileage_is_null(): void
    {
        $workOrder = WorkOrder::factory()->inProgress()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->id,
        ]);

        $event = new WorkOrderCompletedV2(
            work_order_id: $workOrder->id,
            tenant_id: $workOrder->tenant_id,
            company_id: $workOrder->company_id,
            completion_mileage: null,
            completed_at: new \DateTimeImmutable,
        );

        /** @var WriteMileageReadingFromWorkOrderCompleted $listener */
        $listener = $this->app->make(WriteMileageReadingFromWorkOrderCompleted::class);
        $listener->handle($event);

        $this->assertDatabaseCount('vehicle_mileage_readings', 0);
    }

    public function test_listener_skips_silently_when_work_order_not_found(): void
    {
        $event = new WorkOrderCompletedV2(
            work_order_id: '00000000-0000-0000-0000-000000000000',
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
            completion_mileage: 12345,
            completed_at: new \DateTimeImmutable,
        );

        /** @var WriteMileageReadingFromWorkOrderCompleted $listener */
        $listener = $this->app->make(WriteMileageReadingFromWorkOrderCompleted::class);
        $listener->handle($event);

        $this->assertDatabaseCount('vehicle_mileage_readings', 0);
    }

    public function test_listener_skips_foreign_work_order_id_when_event_scope_does_not_match(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignCompany = Company::factory()->create(['tenant_id' => $foreignTenant->id]);
        $foreignVehicle = Vehicle::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
        ]);
        $foreignWorkOrder = WorkOrder::factory()->inProgress()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'vehicle_id' => $foreignVehicle->id,
        ]);

        $event = new WorkOrderCompletedV2(
            work_order_id: $foreignWorkOrder->id,
            tenant_id: $this->tenant->id,
            company_id: $this->company->id,
            completion_mileage: 54321,
            completed_at: new \DateTimeImmutable,
        );

        /** @var WriteMileageReadingFromWorkOrderCompleted $listener */
        $listener = $this->app->make(WriteMileageReadingFromWorkOrderCompleted::class);
        $listener->handle($event);

        $this->assertDatabaseCount('vehicle_mileage_readings', 0);
    }
}
