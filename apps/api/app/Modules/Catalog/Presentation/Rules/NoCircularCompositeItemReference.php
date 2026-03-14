<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Rules;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\ComponentType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Prevents circular references when adding a CompositeItem as a recipe component.
 *
 * Traverses the candidate component's recipe tree to ensure the owner CompositeItem
 * does not appear as a descendant, which would create an infinite loop.
 */
class NoCircularCompositeItemReference implements ValidationRule
{
    private const int MAX_DEPTH = 10;

    public function __construct(
        private readonly string $ownerCompositeItemId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Direct self-reference
        if ($value === $this->ownerCompositeItemId) {
            $fail(__('catalog.circular_reference'));

            return;
        }

        // Indirect: walk the candidate's recipe tree looking for ownerCompositeItemId
        if ($this->wouldCreateCycle($value, 0)) {
            $fail(__('catalog.circular_reference'));
        }
    }

    private function wouldCreateCycle(string $compositeItemId, int $depth): bool
    {
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }

        $compositeItem = CompositeItem::with('activeRecipe.lines')->find($compositeItemId);

        if ($compositeItem === null || $compositeItem->activeRecipe === null) {
            return false;
        }

        /** @var RecipeLine $line */
        foreach ($compositeItem->activeRecipe->lines as $line) {
            if ($line->component_type !== ComponentType::CompositeItem) {
                continue;
            }

            if ($line->component_id === $this->ownerCompositeItemId) {
                return true;
            }

            if ($this->wouldCreateCycle($line->component_id, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
