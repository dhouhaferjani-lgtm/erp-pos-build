<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle\Mileage;

use App\Modules\Vehicle\Application\Commands\LogVehicleMileageCommand;
use App\Modules\Vehicle\Application\Services\VehicleMileageService;
use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\Events\MileageAnomalyDetected;
use App\Modules\Vehicle\Domain\Events\MileageReadingLogged;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleMileageReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class LogMileageTest extends TestCase
{
    use RefreshDatabase;

    public function test_logging_emits_event_and_creates_row(): void
    {
        Event::fake([MileageReadingLogged::class, MileageAnomalyDetected::class]);

        $vehicle = Vehicle::factory()->create(['mileage' => 50000]);
        $service = $this->app->make(VehicleMileageService::class);

        $service->log(new LogVehicleMileageCommand(
            vehicle_id: $vehicle->id,
            mileage: 52000,
            recorded_at: new \DateTimeImmutable,
            source: MileageSource::Manual,
            context_document_id: null,
            context_work_order_id: null,
            recorded_by_user_id: null,
            notes: null,
        ));

        Event::assertDispatched(MileageReadingLogged::class);
        Event::assertNotDispatched(MileageAnomalyDetected::class);
        $this->assertDatabaseCount('vehicle_mileage_readings', 1);
    }

    public function test_anomaly_detected_when_new_reading_is_more_than_5_pct_lower(): void
    {
        Event::fake([MileageAnomalyDetected::class, MileageReadingLogged::class]);

        $vehicle = Vehicle::factory()->create();
        VehicleMileageReading::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'mileage' => 100000,
            'recorded_at' => now()->subMonth(),
        ]);

        $service = $this->app->make(VehicleMileageService::class);
        $service->log(new LogVehicleMileageCommand(
            vehicle_id: $vehicle->id,
            mileage: 80000,
            recorded_at: new \DateTimeImmutable,
            source: MileageSource::Manual,
            context_document_id: null,
            context_work_order_id: null,
            recorded_by_user_id: null,
            notes: null,
        ));

        Event::assertDispatched(MileageAnomalyDetected::class);
    }

    public function test_monotonic_increases_do_not_trigger_anomaly(): void
    {
        Event::fake([MileageAnomalyDetected::class, MileageReadingLogged::class]);

        $vehicle = Vehicle::factory()->create();
        VehicleMileageReading::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'mileage' => 100000,
            'recorded_at' => now()->subMonth(),
        ]);

        $service = $this->app->make(VehicleMileageService::class);
        $service->log(new LogVehicleMileageCommand(
            vehicle_id: $vehicle->id,
            mileage: 105000,
            recorded_at: new \DateTimeImmutable,
            source: MileageSource::Manual,
            context_document_id: null,
            context_work_order_id: null,
            recorded_by_user_id: null,
            notes: null,
        ));

        Event::assertDispatched(MileageReadingLogged::class);
        Event::assertNotDispatched(MileageAnomalyDetected::class);
    }

    public function test_small_decrease_within_threshold_does_not_trigger_anomaly(): void
    {
        Event::fake([MileageAnomalyDetected::class]);

        $vehicle = Vehicle::factory()->create();
        VehicleMileageReading::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'mileage' => 100000,
            'recorded_at' => now()->subMonth(),
        ]);

        $service = $this->app->make(VehicleMileageService::class);
        $service->log(new LogVehicleMileageCommand(
            vehicle_id: $vehicle->id,
            mileage: 97000, // 3% decrease - below 5% threshold
            recorded_at: new \DateTimeImmutable,
            source: MileageSource::Manual,
            context_document_id: null,
            context_work_order_id: null,
            recorded_by_user_id: null,
            notes: null,
        ));

        Event::assertNotDispatched(MileageAnomalyDetected::class);
    }
}
