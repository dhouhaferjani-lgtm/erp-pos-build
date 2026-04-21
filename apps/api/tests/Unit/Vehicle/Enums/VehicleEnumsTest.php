<?php

declare(strict_types=1);

namespace Tests\Unit\Vehicle\Enums;

use App\Modules\Vehicle\Domain\Enums\BodyType;
use App\Modules\Vehicle\Domain\Enums\FuelType;
use App\Modules\Vehicle\Domain\Enums\MileageSource;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\Enums\TransmissionType;
use PHPUnit\Framework\TestCase;

final class VehicleEnumsTest extends TestCase
{
    public function test_fuel_type_cases(): void
    {
        $this->assertSame('gasoline', FuelType::Gasoline->value);
        $this->assertSame('diesel', FuelType::Diesel->value);
        $this->assertSame('electric', FuelType::Electric->value);
        $this->assertSame('hybrid', FuelType::Hybrid->value);
        $this->assertSame('plugin_hybrid', FuelType::PluginHybrid->value);
        $this->assertSame('lpg', FuelType::Lpg->value);
        $this->assertSame('cng', FuelType::Cng->value);
        $this->assertSame('hydrogen', FuelType::Hydrogen->value);
        $this->assertSame('other', FuelType::Other->value);
        $this->assertCount(9, FuelType::cases());
        $this->assertSame(
            ['gasoline', 'diesel', 'electric', 'hybrid', 'plugin_hybrid', 'lpg', 'cng', 'hydrogen', 'other'],
            FuelType::values(),
        );
    }

    public function test_transmission_type_cases(): void
    {
        $this->assertSame('manual', TransmissionType::Manual->value);
        $this->assertSame('automatic', TransmissionType::Automatic->value);
        $this->assertSame('semi_automatic', TransmissionType::SemiAutomatic->value);
        $this->assertSame('cvt', TransmissionType::Cvt->value);
        $this->assertSame('dual_clutch', TransmissionType::DualClutch->value);
        $this->assertSame('other', TransmissionType::Other->value);
        $this->assertCount(6, TransmissionType::cases());
    }

    public function test_body_type_cases(): void
    {
        $this->assertSame('sedan', BodyType::Sedan->value);
        $this->assertSame('hatchback', BodyType::Hatchback->value);
        $this->assertSame('suv', BodyType::Suv->value);
        $this->assertSame('pickup', BodyType::Pickup->value);
        $this->assertSame('van', BodyType::Van->value);
        $this->assertSame('coupe', BodyType::Coupe->value);
        $this->assertSame('convertible', BodyType::Convertible->value);
        $this->assertSame('wagon', BodyType::Wagon->value);
        $this->assertSame('truck', BodyType::Truck->value);
        $this->assertSame('motorcycle', BodyType::Motorcycle->value);
        $this->assertSame('other', BodyType::Other->value);
        // 10 explicit body types + Other sentinel = 11 cases.
        $this->assertCount(11, BodyType::cases());
    }

    public function test_ownership_reason_cases(): void
    {
        $this->assertSame('initial_registration', OwnershipReason::InitialRegistration->value);
        $this->assertSame('purchase', OwnershipReason::Purchase->value);
        $this->assertSame('sale', OwnershipReason::Sale->value);
        $this->assertSame('transfer', OwnershipReason::Transfer->value);
        $this->assertSame('trade_in', OwnershipReason::TradeIn->value);
        $this->assertSame('fleet_assignment', OwnershipReason::FleetAssignment->value);
        $this->assertSame('fleet_return', OwnershipReason::FleetReturn->value);
        $this->assertSame('other', OwnershipReason::Other->value);
        $this->assertCount(8, OwnershipReason::cases());
    }

    public function test_mileage_source_cases(): void
    {
        $this->assertSame('service', MileageSource::Service->value);
        $this->assertSame('manual', MileageSource::Manual->value);
        $this->assertSame('odometer_photo', MileageSource::OdometerPhoto->value);
        $this->assertSame('external_api', MileageSource::ExternalApi->value);
        $this->assertSame('work_order_completion', MileageSource::WorkOrderCompletion->value);
        $this->assertCount(5, MileageSource::cases());
    }
}
