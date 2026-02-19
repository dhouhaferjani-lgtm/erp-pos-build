<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Services;

/**
 * Registry for loyaltyable entity types across verticals.
 *
 * This service maintains a mapping of entity type strings to their concrete classes,
 * enabling polymorphic loyalty behavior without direct dependencies between modules.
 *
 * Usage:
 * 1. Verticals register their loyaltyable entities in their service provider
 * 2. Earning rules use type strings to target specific entity types
 * 3. Registry resolves type strings back to concrete classes when needed
 *
 * Example:
 * ```php
 * // In ProductServiceProvider::boot()
 * $registry = app(LoyaltyRegistry::class);
 * $registry->registerLoyaltyableType('product', Product::class);
 * $registry->registerCategoryType('product_category', Category::class);
 * ```
 */
class LoyaltyRegistry
{
    /**
     * @var array<string, class-string> Mapping of entity type to class name
     */
    private array $entityTypes = [];

    /**
     * @var array<string, class-string> Mapping of category type to class name
     */
    private array $categoryTypes = [];

    /**
     * Register a loyaltyable entity type.
     *
     * @param  string  $type  The entity type identifier (e.g., 'product', 'service')
     * @param  class-string  $class  The fully qualified class name
     */
    public function registerLoyaltyableType(string $type, string $class): void
    {
        $this->entityTypes[$type] = $class;
    }

    /**
     * Register a loyaltyable category type.
     *
     * @param  string  $type  The category type identifier (e.g., 'product_category')
     * @param  class-string  $class  The fully qualified class name
     */
    public function registerCategoryType(string $type, string $class): void
    {
        $this->categoryTypes[$type] = $class;
    }

    /**
     * Get the class name for a registered entity type.
     *
     * @param  string  $type  The entity type identifier
     * @return class-string|null
     */
    public function getEntityClass(string $type): ?string
    {
        return $this->entityTypes[$type] ?? null;
    }

    /**
     * Get the class name for a registered category type.
     *
     * @param  string  $type  The category type identifier
     * @return class-string|null
     */
    public function getCategoryClass(string $type): ?string
    {
        return $this->categoryTypes[$type] ?? null;
    }

    /**
     * Get all registered entity types.
     *
     * @return array<string, class-string>
     */
    public function getRegisteredEntityTypes(): array
    {
        return $this->entityTypes;
    }

    /**
     * Get all registered category types.
     *
     * @return array<string, class-string>
     */
    public function getRegisteredCategoryTypes(): array
    {
        return $this->categoryTypes;
    }

    /**
     * Check if an entity type is registered.
     */
    public function hasEntityType(string $type): bool
    {
        return isset($this->entityTypes[$type]);
    }

    /**
     * Check if a category type is registered.
     */
    public function hasCategoryType(string $type): bool
    {
        return isset($this->categoryTypes[$type]);
    }

    /**
     * Unregister an entity type (useful for testing).
     */
    public function unregisterEntityType(string $type): void
    {
        unset($this->entityTypes[$type]);
    }

    /**
     * Unregister a category type (useful for testing).
     */
    public function unregisterCategoryType(string $type): void
    {
        unset($this->categoryTypes[$type]);
    }

    /**
     * Clear all registrations (useful for testing).
     */
    public function clear(): void
    {
        $this->entityTypes = [];
        $this->categoryTypes = [];
    }

    /**
     * Get count of registered entity types.
     */
    public function entityTypeCount(): int
    {
        return count($this->entityTypes);
    }

    /**
     * Get count of registered category types.
     */
    public function categoryTypeCount(): int
    {
        return count($this->categoryTypes);
    }
}
