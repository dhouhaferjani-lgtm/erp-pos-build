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
 *
 * api.catalog.026 round-2: callerTenantId + callerCompanyId are passed so the
 * traversal is scoped to the caller's tenant+company — cross-tenant composite
 * item ids are silently treated as "no cycle found" (CompositeItem::find returns
 * null → wouldCreateCycle returns false) rather than leaking data.
 */
class NoCircularCompositeItemReference implements ValidationRule
{
    private const int MAX_DEPTH = 10;

    public function __construct(
        private readonly string $ownerCompositeItemId,
        private readonly string $callerTenantId = '',
        private readonly string $callerCompanyId = '',
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

        // api.catalog.026 round-2: scope lookup to caller's tenant+company so a
        // cross-tenant compositeItemId resolves to null (no cycle) without leaking data.
        $query = CompositeItem::query()->with('activeRecipe.lines');
        if ($this->callerTenantId !== '') {
            $query->where('tenant_id', $this->callerTenantId)
                ->where('company_id', $this->callerCompanyId);
        }

        $compositeItem = $query->find($compositeItemId);

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
