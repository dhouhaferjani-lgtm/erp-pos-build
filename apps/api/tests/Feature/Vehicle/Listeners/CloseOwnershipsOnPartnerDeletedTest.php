<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle\Listeners;

use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Events\PartnerDeleted;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CloseOwnershipsOnPartnerDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_deleted_event_closes_open_ownerships(): void
    {
        $vehicle = Vehicle::factory()->create();
        $partner = Partner::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'type' => PartnerType::Customer->value,
        ]);
        $ownership = VehicleOwnership::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'owner_partner_id' => $partner->id,
            'released_at' => null,
        ]);

        $this->assertNull($ownership->released_at);

        event(new PartnerDeleted(
            partnerId: $partner->id,
            tenantId: $vehicle->tenant_id,
            companyId: $vehicle->company_id,
            deletedAt: now()->toIso8601String(),
        ));

        $ownership->refresh();
        $this->assertNotNull($ownership->released_at);
    }
}
