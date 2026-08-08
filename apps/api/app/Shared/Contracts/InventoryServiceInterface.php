<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Interface for inventory operations used by other modules.
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
/**
 * Read-only: `upsertStockLevel` was removed by owner ruling D4 (document-per-action
 * remediation). It let any module set an absolute stock quantity with no stock
 * movement and no justifying document, making the change invisible to every
 * ledger-based detector. Do not reinstate a bare stock writer here — stock
 * changes belong to a document-backed path.
 */
interface InventoryServiceInterface
{
    /**
     * Return true iff a non-reversed Opening movement exists for the product
     * in the given company (tenant isolation via the per-tenant DB).
     *
     * "Non-reversed" means the row itself is not a reversal (reverses_movement_id IS NULL)
     * AND no other row has reversed it (whereDoesntHave reversalOf).
     */
    public function hasActiveOpening(string $companyId, string $productId): bool;

    /**
     * Return true iff any movement that is neither an Opening nor a reversal
     * of an Opening exists for the product in the given company.
     *
     * This indicates post-opening stock activity that would prevent
     * an opening balance from being safely modified or reversed.
     */
    public function hasDownstreamMovements(string $companyId, string $productId): bool;
}
