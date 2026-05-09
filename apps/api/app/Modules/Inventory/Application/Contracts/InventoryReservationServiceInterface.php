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
     * Caller MUST pass the work order's tenant_id + company_id so the
     * reservation is anchored to the requesting tenant + company. Prior
     * to the api.inventory Codex round-2 finding, this method derived
     * Company from an unscoped StockLevel lookup-by-product_id, which
     * allowed a forged cross-company productId to anchor a reservation
     * against a foreign company's stock on Workshop approval.
     *
     * @param  numeric-string  $quantity  Quantity to reserve, as a scale-preserving string.
     */
    public function reserveForWorkOrder(
        string $tenantId,
        string $companyId,
        string $productId,
        string $quantity,
        string $workOrderLineId,
        string $workOrderId,
        ?\DateTimeImmutable $expiresAt,
    ): StockReservation;

    /**
     * Release all active reservations for a WorkOrder (e.g. cancellation).
     *
     * Pass the work order's tenant_id and company_id to scope the release
     * to that company only (api.inventory.033).
     *
     * @return int Number of reservations released.
     */
    public function releaseForWorkOrder(
        string $workOrderId,
        string $reasonCode,
        ?string $expectedTenantId = null,
        ?string $expectedCompanyId = null,
    ): int;
}
