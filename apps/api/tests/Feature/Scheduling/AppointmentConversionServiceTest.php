<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Services\AppointmentConversionService;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentService as AppointmentServiceModel;
use App\Modules\Scheduling\Domain\Events\AppointmentConvertedToWorkOrder;
use App\Modules\Scheduling\Domain\Exceptions\AppointmentNotConvertibleException;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderCreationServiceInterface;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PlannedServiceRef;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\Expectation;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature tests for {@see AppointmentConversionService}.
 *
 * Covers:
 *  - Happy path: Confirmed appointment converts; work_order_id is set;
 *    AppointmentConvertedToWorkOrder fires; planned services mapped to
 *    PlannedServiceRef VOs.
 *  - Guard: already-converted appointment throws (double-conversion).
 *  - Guard: non-Confirmed / non-CheckedIn status throws.
 *  - Guard: missing vehicle or partner throws.
 */
final class AppointmentConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_converts_confirmed_appointment_to_work_order(): void
    {
        Event::fake();

        $appt = Appointment::factory()->confirmed()->create();
        AppointmentServiceModel::factory()->count(2)->create([
            'tenant_id' => $appt->tenant_id,
            'appointment_id' => $appt->id,
        ]);

        $workOrder = WorkOrder::factory()->create();

        /** @var MockInterface&WorkOrderCreationServiceInterface $mock */
        $mock = Mockery::mock(WorkOrderCreationServiceInterface::class);
        /** @var Expectation $expectation */
        $expectation = $mock->shouldReceive('createFromAppointment');
        $expectation
            ->once()
            ->with(
                Mockery::on(fn (string $id): bool => $id === $appt->id),
                Mockery::on(function (array $planned): bool {
                    $this->assertCount(2, $planned);
                    foreach ($planned as $p) {
                        $this->assertInstanceOf(PlannedServiceRef::class, $p);
                    }

                    return true;
                }),
                Mockery::on(fn (string $v): bool => $v === $appt->vehicle_id),
                Mockery::on(fn (string $p): bool => $p === $appt->customer_partner_id),
            )
            ->andReturn($workOrder);

        $this->app->instance(WorkOrderCreationServiceInterface::class, $mock);
        $service = $this->app->make(AppointmentConversionService::class);

        $result = $service->convertToWorkOrder($appt->id);

        $this->assertSame($workOrder->id, $result->id);
        $this->assertDatabaseHas('scheduling_appointments', [
            'id' => $appt->id,
            'work_order_id' => $workOrder->id,
        ]);
        Event::assertDispatched(
            AppointmentConvertedToWorkOrder::class,
            fn ($e) => $e->appointment_id === $appt->id && $e->work_order_id === $workOrder->id,
        );
    }

    public function test_double_conversion_throws(): void
    {
        $alreadyLinkedWorkOrder = WorkOrder::factory()->create();
        $appt = Appointment::factory()->confirmed()->create([
            'work_order_id' => $alreadyLinkedWorkOrder->id,
        ]);

        $mock = Mockery::mock(WorkOrderCreationServiceInterface::class);
        $mock->shouldNotReceive('createFromAppointment');
        $this->app->instance(WorkOrderCreationServiceInterface::class, $mock);
        $service = $this->app->make(AppointmentConversionService::class);

        $this->expectException(AppointmentNotConvertibleException::class);
        $this->expectExceptionMessage('already been converted');
        $service->convertToWorkOrder($appt->id);
    }

    public function test_converts_checked_in_appointment(): void
    {
        Event::fake();
        $appt = Appointment::factory()->checkedIn()->create();
        AppointmentServiceModel::factory()->create([
            'tenant_id' => $appt->tenant_id,
            'appointment_id' => $appt->id,
        ]);

        $workOrder = WorkOrder::factory()->create();
        /** @var MockInterface&WorkOrderCreationServiceInterface $mock */
        $mock = Mockery::mock(WorkOrderCreationServiceInterface::class);
        /** @var Expectation $expectation */
        $expectation = $mock->shouldReceive('createFromAppointment');
        $expectation->once()->andReturn($workOrder);
        $this->app->instance(WorkOrderCreationServiceInterface::class, $mock);

        $service = $this->app->make(AppointmentConversionService::class);
        $result = $service->convertToWorkOrder($appt->id);

        $this->assertSame($workOrder->id, $result->id);
    }

    public function test_scheduled_appointment_rejects_conversion(): void
    {
        $appt = Appointment::factory()->create(); // status = Scheduled
        $mock = Mockery::mock(WorkOrderCreationServiceInterface::class);
        $mock->shouldNotReceive('createFromAppointment');
        $this->app->instance(WorkOrderCreationServiceInterface::class, $mock);
        $service = $this->app->make(AppointmentConversionService::class);

        $this->expectException(AppointmentNotConvertibleException::class);
        $this->expectExceptionMessage("cannot be converted from status 'scheduled'");
        $service->convertToWorkOrder($appt->id);
    }

    public function test_cancelled_appointment_rejects_conversion(): void
    {
        $appt = Appointment::factory()->cancelled()->create();
        $mock = Mockery::mock(WorkOrderCreationServiceInterface::class);
        $mock->shouldNotReceive('createFromAppointment');
        $this->app->instance(WorkOrderCreationServiceInterface::class, $mock);
        $service = $this->app->make(AppointmentConversionService::class);

        $this->expectException(AppointmentNotConvertibleException::class);
        $service->convertToWorkOrder($appt->id);
    }

    public function test_missing_vehicle_rejects_conversion(): void
    {
        $appt = Appointment::factory()->confirmed()->create([
            'vehicle_id' => null,
        ]);
        $mock = Mockery::mock(WorkOrderCreationServiceInterface::class);
        $mock->shouldNotReceive('createFromAppointment');
        $this->app->instance(WorkOrderCreationServiceInterface::class, $mock);
        $service = $this->app->make(AppointmentConversionService::class);

        $this->expectException(AppointmentNotConvertibleException::class);
        $this->expectExceptionMessage('missing vehicle or customer partner');
        $service->convertToWorkOrder($appt->id);
    }

    public function test_missing_partner_rejects_conversion(): void
    {
        $appt = Appointment::factory()->confirmed()->create([
            'customer_partner_id' => null,
        ]);
        $mock = Mockery::mock(WorkOrderCreationServiceInterface::class);
        $mock->shouldNotReceive('createFromAppointment');
        $this->app->instance(WorkOrderCreationServiceInterface::class, $mock);
        $service = $this->app->make(AppointmentConversionService::class);

        $this->expectException(AppointmentNotConvertibleException::class);
        $service->convertToWorkOrder($appt->id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
