<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Contracts;

/**
 * Interface for entities that can participate in loyalty programs.
 *
 * Any entity that should be eligible for loyalty points or rewards
 * must implement this contract. This enables polymorphic behavior
 * across verticals (automotive services, restaurants, retail, etc.).
 *
 * Module boundaries are sacred: Cross-module communication ONLY via interfaces.
 */
interface LoyaltyableContract
{
    /**
     * Get the unique identifier of the loyaltyable entity.
     *
     * @return string The entity ID (e.g., product UUID, menu item UUID, service UUID)
     */
    public function getLoyaltyableId(): string;

    /**
     * Get the type identifier for polymorphic relationships.
     *
     * @return string One of: 'product', 'menu_item', 'service', or custom vertical-specific type
     */
    public function getLoyaltyableType(): string;

    /**
     * Get the human-readable name of the loyaltyable entity.
     *
     * @return string The entity name (e.g., "Premium Oil Change", "Grilled Chicken Wrap")
     */
    public function getLoyaltyableName(): string;

    /**
     * Get the price used for loyalty calculation.
     *
     * @return float The price in the tenant's base currency
     */
    public function getLoyaltyablePrice(): float;

    /**
     * Get the category of this loyaltyable entity.
     *
     * @return LoyaltyableCategoryContract|null The category contract or null if uncategorized
     */
    public function getLoyaltyableCategory(): ?LoyaltyableCategoryContract;
}
