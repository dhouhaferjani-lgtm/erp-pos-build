<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Strategy for deducting stock when selling composite items.
 *
 * Different composite items may deduct stock differently:
 * - Made-to-order: deduct components on sale
 * - Batch production: deduct from pre-produced stock
 * - Stock items: deduct composite item directly
 *
 * Phase 1: Only preview() is implemented.
 */
interface StockDeductionStrategyInterface
{
    /**
     * Preview the stock deductions that would occur for a given quantity.
     *
     * @param  string  $compositeItemId  The composite item being sold
     * @param  string  $quantity  The quantity being sold
     * @param  string  $locationId  The warehouse/location to deduct from
     * @return array{
     *     can_fulfill: bool,
     *     lines: array<int, array{
     *         product_id: string,
     *         product_name: string,
     *         required_quantity: string,
     *         available_quantity: string,
     *         unit: string,
     *         sufficient: bool
     *     }>
     * }
     */
    public function preview(string $compositeItemId, string $quantity, string $locationId): array;

    /**
     * Execute the stock deductions for a completed sale.
     *
     * @param  string  $compositeItemId  The composite item sold
     * @param  string  $quantity  The quantity sold
     * @param  string  $locationId  The warehouse/location to deduct from
     * @param  string  $referenceId  The document/receipt ID for audit trail
     * @return array<int, array{product_id: string, quantity_deducted: string, unit: string}>
     */
    public function deduct(string $compositeItemId, string $quantity, string $locationId, string $referenceId): array;
}
