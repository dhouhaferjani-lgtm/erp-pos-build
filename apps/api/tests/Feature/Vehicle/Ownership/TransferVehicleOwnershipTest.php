<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle\Ownership;

use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Vehicle\Application\Commands\TransferVehicleOwnershipCommand;
use App\Modules\Vehicle\Application\Services\VehicleOwnershipService;
use App\Modules\Vehicle\Domain\Enums\OwnershipReason;
use App\Modules\Vehicle\Domain\Events\VehicleOwnerChanged;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class TransferVehicleOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_transfer_closes_prior_and_opens_new(): void
    {
        Event::fake([VehicleOwnerChanged::class]);

        $vehicle = Vehicle::factory()->create();
        $priorOwner = Partner::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'type' => PartnerType::Customer->value,
        ]);
        $newOwner = Partner::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'type' => PartnerType::Customer->value,
        ]);

        VehicleOwnership::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'owner_partner_id' => $priorOwner->id,
            'acquired_at' => now()->subYear(),
            'released_at' => null,
        ]);

        $service = $this->app->make(VehicleOwnershipService::class);

        $newOwnership = $service->transfer(new TransferVehicleOwnershipCommand(
            vehicle_id: $vehicle->id,
            new_owner_partner_id: $newOwner->id,
            occurred_at: new \DateTimeImmutable,
            reason: OwnershipReason::Sale,
            notes: 'Sold to new owner',
            actor_user_id: null,
        ));

        $this->assertDatabaseCount('vehicle_ownership_history', 2);
        $this->assertDatabaseHas('vehicle_ownership_history', [
            'vehicle_id' => $vehicle->id,
            'owner_partner_id' => $priorOwner->id,
        ]);

        /** @var VehicleOwnership $priorRow */
        $priorRow = VehicleOwnership::where('vehicle_id', $vehicle->id)
            ->where('owner_partner_id', $priorOwner->id)
            ->firstOrFail();
        $this->assertNotNull($priorRow->released_at);
        $this->assertNull($newOwnership->released_at);
        $this->assertSame($newOwner->id, $newOwnership->owner_partner_id);

        Event::assertDispatched(VehicleOwnerChanged::class, function ($e) use ($priorOwner, $newOwner): bool {
            return $e->previous_owner_partner_id === $priorOwner->id
                && $e->new_owner_partner_id === $newOwner->id
                && $e->reason === OwnershipReason::Sale;
        });
    }

    public function test_transfer_without_prior_owner_just_opens(): void
    {
        $vehicle = Vehicle::factory()->create();
        $owner = Partner::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'type' => PartnerType::Customer->value,
        ]);

        $service = $this->app->make(VehicleOwnershipService::class);
        $service->transfer(new TransferVehicleOwnershipCommand(
            vehicle_id: $vehicle->id,
            new_owner_partner_id: $owner->id,
            occurred_at: new \DateTimeImmutable,
            reason: OwnershipReason::InitialRegistration,
            notes: null,
            actor_user_id: null,
        ));

        $this->assertDatabaseCount('vehicle_ownership_history', 1);
    }

    public function test_handle_partner_deleted_closes_all_open_ownerships(): void
    {
        $vehicle1 = Vehicle::factory()->create();
        $vehicle2 = Vehicle::factory()->create(['tenant_id' => $vehicle1->tenant_id, 'company_id' => $vehicle1->company_id]);
        $owner = Partner::factory()->create([
            'tenant_id' => $vehicle1->tenant_id,
            'company_id' => $vehicle1->company_id,
            'type' => PartnerType::Customer->value,
        ]);

        VehicleOwnership::factory()->create([
            'tenant_id' => $vehicle1->tenant_id,
            'company_id' => $vehicle1->company_id,
            'vehicle_id' => $vehicle1->id,
            'owner_partner_id' => $owner->id,
            'released_at' => null,
        ]);
        VehicleOwnership::factory()->create([
            'tenant_id' => $vehicle2->tenant_id,
            'company_id' => $vehicle2->company_id,
            'vehicle_id' => $vehicle2->id,
            'owner_partner_id' => $owner->id,
            'released_at' => null,
        ]);

        $service = $this->app->make(VehicleOwnershipService::class);
        $closed = $service->handlePartnerDeleted($owner->id);

        $this->assertSame(2, $closed);
        $this->assertSame(0, VehicleOwnership::where('owner_partner_id', $owner->id)->whereNull('released_at')->count());
    }
}
