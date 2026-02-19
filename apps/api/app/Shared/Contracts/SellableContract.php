<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Contract for sellable items in the system.
 *
 * Both Product and CompositeItem implement this interface,
 * allowing the POS, Document, and Pricing modules to work
 * with any sellable item without coupling to specific types.
 */
interface SellableContract
{
    /**
     * Get the unique identifier of the sellable item.
     */
    public function getSellableId(): string;

    /**
     * Get the type discriminator (e.g., 'product', 'composite_item').
     */
    public function getSellableType(): string;

    /**
     * Get the display name of the sellable item.
     */
    public function getSellableName(): string;

    /**
     * Get the base selling price (before modifiers/variants).
     */
    public function getSellableBasePrice(): string;

    /**
     * Get the unit of measure label (e.g., 'piece', 'kg').
     */
    public function getSellableUnit(): ?string;

    /**
     * Whether this sellable item tracks physical stock.
     */
    public function isStockTracked(): bool;

    /**
     * Whether this sellable item is currently available for sale.
     */
    public function isAvailable(): bool;
}
