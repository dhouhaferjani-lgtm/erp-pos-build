<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Traits;

use App\Modules\Loyalty\Domain\Contracts\LoyaltyableCategoryContract;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyableContract;

/**
 * Trait for entities that can participate in loyalty programs
 *
 * Usage:
 * 1. Add trait to your entity (e.g. Product)
 * 2. Implement the required methods
 * 3. Register the entity type in LoyaltyRegistry
 *
 * Example:
 * ```php
 * class Product extends Model implements LoyaltyableContract
 * {
 *     use Loyaltyable;
 *
 *     public function getLoyaltyableType(): string
 *     {
 *         return 'product';
 *     }
 *
 *     public function getLoyaltyableName(): string
 *     {
 *         return $this->name;
 *     }
 *
 *     public function getLoyaltyablePrice(): float
 *     {
 *         return (float) $this->price;
 *     }
 *
 *     public function getLoyaltyableCategory(): ?LoyaltyableCategoryContract
 *     {
 *         return $this->category;
 *     }
 * }
 * ```
 */
/** @phpstan-ignore trait.unused */
trait Loyaltyable
{
    /**
     * Get the unique identifier for this loyaltyable entity
     *
     * Default implementation uses the model's ID
     * Override if you need different behavior
     */
    public function getLoyaltyableId(): string
    {
        return $this->id;
    }

    /**
     * Get the type identifier for this loyaltyable entity
     *
     * MUST be overridden in implementing class
     * Examples: 'product', 'service', 'package'
     *
     * This type must be registered in LoyaltyRegistry
     */
    abstract public function getLoyaltyableType(): string;

    /**
     * Get the display name for this loyaltyable entity
     *
     * MUST be overridden in implementing class
     * Used in transaction descriptions and UI
     */
    abstract public function getLoyaltyableName(): string;

    /**
     * Get the price/value of this loyaltyable entity
     *
     * MUST be overridden in implementing class
     * Used for spend-based earning rules
     *
     * @return float Price in base currency
     */
    abstract public function getLoyaltyablePrice(): float;

    /**
     * Get the category for this loyaltyable entity
     *
     * MUST be overridden in implementing class
     * Return null if entity has no category concept
     * Used for category-based earning rules
     */
    abstract public function getLoyaltyableCategory(): ?LoyaltyableCategoryContract;

    /**
     * Get additional metadata for loyalty purposes
     *
     * Optional - override to provide custom data for earning rules
     *
     * @return array<string, mixed>
     */
    public function getLoyaltyableMetadata(): array
    {
        return [];
    }

    /**
     * Check if this entity is eligible for loyalty programs
     *
     * Optional - override to add custom eligibility logic
     * Examples: exclude discounted items, exclude certain brands
     */
    public function isLoyaltyEligible(): bool
    {
        return true;
    }
}
