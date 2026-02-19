<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Contracts;

/**
 * Interface for categories of loyaltyable entities.
 *
 * Categories allow loyalty programs to apply differential rules
 * (e.g., "Premium services give 2x points") across different verticals.
 *
 * Implementations may include:
 * - ProductCategory (automotive parts, supplies)
 * - MenuCategory (restaurant entrees, beverages)
 * - ServiceCategory (labor, diagnostics, maintenance)
 */
interface LoyaltyableCategoryContract
{
    /**
     * Get the unique identifier of the category.
     *
     * @return string The category ID (e.g., UUID)
     */
    public function getCategoryId(): string;

    /**
     * Get the human-readable name of the category.
     *
     * @return string The category name (e.g., "Premium Services", "Beverages")
     */
    public function getCategoryName(): string;
}
