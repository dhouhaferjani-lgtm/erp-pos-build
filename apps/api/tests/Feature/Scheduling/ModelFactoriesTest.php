<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\AppointmentService;
use App\Modules\Scheduling\Domain\AppointmentStatusTransition;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentSource;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\Enums\AppointmentType;
use App\Modules\Scheduling\Domain\Enums\BayType;
use App\Modules\Scheduling\Domain\Enums\WaitType;
use App\Modules\Scheduling\Domain\ScheduleConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test — confirms every Scheduling model can be persisted via its
 * factory, enum casts round-trip, and the key relationships hydrate.
 */
final class ModelFactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_bay_factory_persists_and_casts_enum(): void
    {
        $bay = Bay::factory()->create();
        $this->assertInstanceOf(BayType::class, $bay->bay_type);
        $this->assertSame(BayType::General, $bay->bay_type);
        $this->assertTrue($bay->is_active);
        $this->assertArrayHasKey('mon', $bay->operating_hours);
    }

    public function test_schedule_config_factory_persists_with_defaults(): void
    {
        $config = ScheduleConfig::factory()->create();
        $this->assertSame(30, $config->time_slot_minutes);
        $this->assertSame(60, $config->default_appointment_duration_minutes);
        $this->assertFalse($config->online_booking_enabled);
    }

    public function test_appointment_factory_persists_with_enum_casts(): void
    {
        $appt = Appointment::factory()->create();
        $this->assertInstanceOf(AppointmentStatus::class, $appt->status);
        $this->assertSame(AppointmentStatus::Scheduled, $appt->status);
        $this->assertInstanceOf(AppointmentType::class, $appt->appointment_type);
        $this->assertInstanceOf(WaitType::class, $appt->wait_type);
        $this->assertInstanceOf(AppointmentSource::class, $appt->source);
        $this->assertSame(AppointmentSource::Manual, $appt->source);
    }

    public function test_appointment_service_factory_persists_with_polymorphic_ref(): void
    {
        $service = AppointmentService::factory()->create();
        $this->assertSame(AppointmentService::REF_TYPE_SERVICE, $service->service_ref_type);
        $this->assertSame('45.000', $service->estimated_price);
    }

    public function test_appointment_service_factory_bundle_state(): void
    {
        $service = AppointmentService::factory()->bundle()->create();
        $this->assertSame(AppointmentService::REF_TYPE_BUNDLE, $service->service_ref_type);
    }

    public function test_appointment_status_transition_factory_persists(): void
    {
        $transition = AppointmentStatusTransition::factory()->create();
        $this->assertSame(AppointmentStatus::Scheduled, $transition->from_status);
        $this->assertSame(AppointmentStatus::Confirmed, $transition->to_status);
    }

    public function test_appointment_services_relation_hydrates(): void
    {
        $appointment = Appointment::factory()->create();
        AppointmentService::factory()->count(3)->create([
            'tenant_id' => $appointment->tenant_id,
            'appointment_id' => $appointment->id,
        ]);

        $fresh = $appointment->fresh(['services']);
        $this->assertNotNull($fresh);
        $this->assertCount(3, $fresh->services);
    }

    public function test_appointment_bay_relation_hydrates(): void
    {
        $appointment = Appointment::factory()->create();
        $this->assertInstanceOf(Bay::class, $appointment->bay);
    }
}
