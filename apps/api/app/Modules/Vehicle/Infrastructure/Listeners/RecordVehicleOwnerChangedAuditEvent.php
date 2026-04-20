<?php

declare(strict_types=1);

namespace App\Modules\Vehicle\Infrastructure\Listeners;

use App\Modules\Vehicle\Domain\Events\VehicleOwnerChanged;
use Illuminate\Support\Facades\Log;

/**
 * Writes an audit-log entry for VehicleOwnerChanged events.
 *
 * Uses the default log channel (stderr in testing, daily rotating file in prod)
 * because TimescaleDB-based audit stream is not in this spec's scope. The
 * payload is the full event state so downstream audit replay can reconstruct
 * the ownership transfer.
 */
final readonly class RecordVehicleOwnerChangedAuditEvent
{
    public function handle(VehicleOwnerChanged $event): void
    {
        Log::info('vehicle.owner_changed', [
            'vehicle_id' => $event->vehicle_id,
            'tenant_id' => $event->tenant_id,
            'previous_owner_partner_id' => $event->previous_owner_partner_id,
            'new_owner_partner_id' => $event->new_owner_partner_id,
            'reason' => $event->reason->value,
            'occurred_at' => $event->occurred_at->format(\DateTimeInterface::ATOM),
        ]);
    }
}
