<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Infrastructure\Adapters;

use App\Modules\Inventory\Application\Contracts\InventoryReservationServiceInterface;
use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderLineRepositoryInterface;
use App\Modules\Workshop\WorkOrder\Domain\ValueObjects\PartNeed;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;

/**
 * Bridges WorkOrder → Inventory reservation concerns.
 *
 * On Approved transition the transition service calls {@see reserveFor} — one
 * reservation per Part line that isn't customer-supplied. On Cancelled
 * transition we call {@see releaseFor} to free everything that was reserved
 * by this WO.
 *
 * The underlying {@see InventoryReservationServiceInterface} is a thin
 * contract over Inventory's StockReservationService — Workshop never
 * imports that service directly (hexagonal boundary).
 */
final readonly class InventoryReservationAdapter
{
    public function __construct(
        private InventoryReservationServiceInterface $reservations,
        private WorkOrderLineRepositoryInterface $lines,
    ) {}

    /**
     * Reserve stock for every Part line on the WO. Each reservation is linked
     * back to its WorkOrderLine via `source_line_id`. Customer-supplied lines
     * are skipped — there's nothing to reserve from company stock.
     *
     * @return list<PartNeed> Materialized part-needs (fed to
     *                        WorkOrderPartsNeeded event).
     */
    public function reserveFor(WorkOrder $wo): array
    {
        $partLines = $this->lines->listPartLinesForWorkOrder($wo->id);

        $needs = [];
        foreach ($partLines as $line) {
            if ($line->is_customer_supplied || $line->product_id === null) {
                continue;
            }

            /** @var numeric-string $quantity */
            $quantity = $line->quantity;

            $reservation = $this->reservations->reserveForWorkOrder(
                productId: $line->product_id,
                quantity: $quantity,
                workOrderLineId: $line->id,
                workOrderId: $wo->id,
                expiresAt: null,
            );

            $line->stock_reservation_id = (string) $reservation->id;
            $this->lines->save($line);

            $needs[] = $this->partNeedFrom($wo, $line);
        }

        return $needs;
    }

    /**
     * Release every active reservation sourced from this WO. Returns the
     * number released (idempotent — zero if nothing outstanding).
     */
    public function releaseFor(WorkOrder $wo, string $reasonCode): int
    {
        return $this->reservations->releaseForWorkOrder($wo->id, $reasonCode);
    }

    private function partNeedFrom(WorkOrder $wo, WorkOrderLine $line): PartNeed
    {
        // WO.vehicle is a non-null BelongsTo per the schema (vehicle_id
        // REFERENCES vehicles(id) NOT NULL). license_plate is the
        // canonical display name for a Vehicle in AutoSpecs contexts.
        $display = $wo->vehicle->license_plate;

        return new PartNeed(
            product_id: $line->product_id ?? '',
            display_name: $line->display_name,
            quantity: $line->quantity,
            unit: $line->unit,
            vehicle_id: $wo->vehicle_id,
            vehicle_display: $display,
            urgency: 'normal',
            notes: null,
        );
    }
}
