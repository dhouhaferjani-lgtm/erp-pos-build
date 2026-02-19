<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Services;

use App\Modules\Loyalty\Domain\Entities\MemberStampCard;
use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;

final readonly class StampCardService
{
    /**
     * Check if transaction qualifies for stamp
     *
     * @param  object{qualifying_items: array<string, mixed>}  $definition  StampCardDefinition or stub with same properties
     * @param  array<string, mixed>  $transactionData
     */
    public function qualifiesForStamp(
        object $definition,
        array $transactionData
    ): bool {
        $items = $transactionData['items'] ?? [];

        if (empty($items)) {
            return false;
        }

        $qualifyingItems = $definition->qualifying_items;

        // If all_products is true, check for exclusions only
        if (isset($qualifyingItems['all_products']) && $qualifyingItems['all_products'] === true) {
            return $this->hasQualifyingItemsWithExclusions($items, $qualifyingItems);
        }

        // Check if any item matches the qualification criteria
        foreach ($items as $item) {
            if ($this->itemQualifies($item, $qualifyingItems)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate stamps to award for transaction
     *
     * @param  object{qualifying_items: array<string, mixed>, stamps_per_item: int}  $definition  StampCardDefinition or stub with same properties
     * @param  array<string, mixed>  $transactionData
     */
    public function calculateStamps(
        object $definition,
        array $transactionData
    ): int {
        $items = $transactionData['items'] ?? [];

        if (empty($items)) {
            return 0;
        }

        $qualifyingItems = $definition->qualifying_items;
        $stampsPerItem = $definition->stamps_per_item;
        $totalStamps = 0;

        foreach ($items as $item) {
            if ($this->itemQualifies($item, $qualifyingItems)) {
                $quantity = $item['quantity'] ?? 1;
                $totalStamps += $quantity * $stampsPerItem;
            }
        }

        return $totalStamps;
    }

    /**
     * Check if card is complete
     *
     * @param  object{current_stamps: int}  $card  MemberStampCard or stub with same properties
     * @param  object{stamps_required: int}  $definition  StampCardDefinition or stub with same properties
     */
    public function isComplete(object $card, object $definition): bool
    {
        return $card->current_stamps >= $definition->stamps_required;
    }

    /**
     * Calculate remaining stamps needed
     *
     * @param  object{current_stamps: int}  $card  MemberStampCard or stub with same properties
     * @param  object{stamps_required: int}  $definition  StampCardDefinition or stub with same properties
     */
    public function stampsRemaining(object $card, object $definition): int
    {
        return (int) max(0, $definition->stamps_required - $card->current_stamps);
    }

    /**
     * Calculate progress percentage
     *
     * @param  object{current_stamps: int}  $card  MemberStampCard or stub with same properties
     * @param  object{stamps_required: int}  $definition  StampCardDefinition or stub with same properties
     */
    public function progressPercentage(object $card, object $definition): float
    {
        if ($definition->stamps_required === 0) {
            return 0.0;
        }

        $percentage = ($card->current_stamps / $definition->stamps_required) * 100;

        return min(100.0, $percentage);
    }

    /**
     * Check if an item qualifies based on criteria
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $qualifyingItems
     */
    private function itemQualifies(array $item, array $qualifyingItems): bool
    {
        // Check if all_products with exclusions
        if (isset($qualifyingItems['all_products']) && $qualifyingItems['all_products'] === true) {
            return ! $this->itemIsExcluded($item, $qualifyingItems);
        }

        // Check product IDs
        if (isset($qualifyingItems['product_ids']) && ! empty($qualifyingItems['product_ids'])) {
            $productId = $item['product_id'] ?? null;
            if ($productId && in_array($productId, $qualifyingItems['product_ids'], true)) {
                return ! $this->itemIsExcluded($item, $qualifyingItems);
            }
        }

        // Check category IDs
        if (isset($qualifyingItems['category_ids']) && ! empty($qualifyingItems['category_ids'])) {
            $categoryId = $item['category_id'] ?? null;
            if ($categoryId && in_array($categoryId, $qualifyingItems['category_ids'], true)) {
                return ! $this->itemIsExcluded($item, $qualifyingItems);
            }
        }

        // Check price range
        if ($this->hasPriceConstraints($qualifyingItems)) {
            return $this->itemMatchesPriceRange($item, $qualifyingItems) && ! $this->itemIsExcluded($item, $qualifyingItems);
        }

        return false;
    }

    /**
     * Check if item is excluded
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $qualifyingItems
     */
    private function itemIsExcluded(array $item, array $qualifyingItems): bool
    {
        // Check excluded product IDs
        if (isset($qualifyingItems['excluded_product_ids']) && ! empty($qualifyingItems['excluded_product_ids'])) {
            $productId = $item['product_id'] ?? null;
            if ($productId && in_array($productId, $qualifyingItems['excluded_product_ids'], true)) {
                return true;
            }
        }

        // Check excluded category IDs
        if (isset($qualifyingItems['excluded_category_ids']) && ! empty($qualifyingItems['excluded_category_ids'])) {
            $categoryId = $item['category_id'] ?? null;
            if ($categoryId && in_array($categoryId, $qualifyingItems['excluded_category_ids'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any qualifying items exist with exclusion rules
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $qualifyingItems
     */
    private function hasQualifyingItemsWithExclusions(array $items, array $qualifyingItems): bool
    {
        foreach ($items as $item) {
            if (! $this->itemIsExcluded($item, $qualifyingItems)) {
                // Check price range if specified
                if ($this->hasPriceConstraints($qualifyingItems)) {
                    if ($this->itemMatchesPriceRange($item, $qualifyingItems)) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if qualifying items has price constraints
     *
     * @param  array<string, mixed>  $qualifyingItems
     */
    private function hasPriceConstraints(array $qualifyingItems): bool
    {
        return isset($qualifyingItems['min_price']) || isset($qualifyingItems['max_price']);
    }

    /**
     * Check if item matches price range
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $qualifyingItems
     */
    private function itemMatchesPriceRange(array $item, array $qualifyingItems): bool
    {
        $price = $item['price'] ?? null;

        if ($price === null) {
            return false;
        }

        $priceFloat = is_string($price) ? (float) $price : $price;

        // Check minimum price
        if (isset($qualifyingItems['min_price'])) {
            $minPrice = (float) $qualifyingItems['min_price'];
            if ($priceFloat < $minPrice) {
                return false;
            }
        }

        // Check maximum price
        if (isset($qualifyingItems['max_price'])) {
            $maxPrice = (float) $qualifyingItems['max_price'];
            if ($priceFloat > $maxPrice) {
                return false;
            }
        }

        return true;
    }
}
