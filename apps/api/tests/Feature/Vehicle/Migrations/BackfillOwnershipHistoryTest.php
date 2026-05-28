<?php

declare(strict_types=1);

namespace Tests\Feature\Vehicle\Migrations;

use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Vehicle\Domain\Vehicle;
use App\Modules\Vehicle\Domain\VehicleOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the data-migration's INSERT SELECT + row-by-row fallback produce
 * the same end state: one open ownership row per vehicle whose legacy
 * partner_id is a customer-or-both partner.
 *
 * RefreshDatabase runs migrations fresh; to simulate legacy data we restore
 * vehicles.partner_id inside the test, clear any rows the migration already
 * wrote, and re-invoke the up() logic via a fresh run.
 */
final class BackfillOwnershipHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_inserts_row_for_customer_partner(): void
    {
        $vehicle = Vehicle::factory()->create();
        $customer = Partner::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'type' => PartnerType::Customer->value,
        ]);

        DB::table('vehicles')->where('id', $vehicle->id)->update(['partner_id' => $customer->id]);
        DB::table('vehicle_ownership_history')->where('vehicle_id', $vehicle->id)->delete();

        $this->runBackfill();

        $this->assertDatabaseHas('vehicle_ownership_history', [
            'vehicle_id' => $vehicle->id,
            'owner_partner_id' => $customer->id,
            'released_at' => null,
            'reason_code' => 'initial_registration',
        ]);
    }

    public function test_backfill_skips_supplier_partners(): void
    {
        $vehicle = Vehicle::factory()->create();
        $supplier = Partner::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'type' => PartnerType::Supplier->value,
        ]);

        DB::table('vehicles')->where('id', $vehicle->id)->update(['partner_id' => $supplier->id]);
        DB::table('vehicle_ownership_history')->where('vehicle_id', $vehicle->id)->delete();

        $this->runBackfill();

        $this->assertSame(0, VehicleOwnership::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_backfill_idempotent_does_not_duplicate_existing_open_row(): void
    {
        $vehicle = Vehicle::factory()->create();
        $customer = Partner::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'type' => PartnerType::Customer->value,
        ]);

        DB::table('vehicles')->where('id', $vehicle->id)->update(['partner_id' => $customer->id]);
        VehicleOwnership::factory()->create([
            'tenant_id' => $vehicle->tenant_id,
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->id,
            'owner_partner_id' => $customer->id,
            'released_at' => null,
        ]);

        $this->runBackfill();

        $this->assertSame(1, VehicleOwnership::where('vehicle_id', $vehicle->id)->whereNull('released_at')->count());
    }

    private function runBackfill(): void
    {
        $migration = require __DIR__.'/../../../../database/migrations/tenant/2026_04_19_100004_backfill_vehicle_ownership_history.php';
        $migration->up();
    }
}
