<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contracts;

use App\Modules\Inventory\Domain\StockReservation;

/**
 * Cross-module contract for reserving/releasing stock tied to a Work Order.
 *
 * Workshop/WorkOrder depends on this interface (DIP); Inventory provides the
 * concrete implementation via StockReservationService. Quantity is a numeric
 * string (scale-preserving) to match the existing StockReservationService::reserve
 * signature and avoid float-precision bugs on monetary/quantity values.
 */
interface InventoryReservationServiceInterface
{
    /**
     * Reserve stock for a specific WorkOrder line.
     *
     * @param  numeric-string  $quantity  Quantity to reserve, as a scale-preserving string.
     */
    public function reserveForWorkOrder(
        string $productId,
        string $quantity,
        string $workOrderLineId,
        string $workOrderId,
        ?\DateTimeImmutable $expiresAt,
    ): StockReservation;

    /**
     * Release all active reservations for a WorkOrder (e.g. cancellation).
     *
     * @return int Number of reservations released.
     */
    public function releaseForWorkOrder(
        string $workOrderId,
        string $reasonCode,
    ): int;
}
