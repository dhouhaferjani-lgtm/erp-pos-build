<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Services;

use App\Modules\Inventory\Domain\InventoryCountingItem;

/**
 * Pre-finalize opening-cost gate (D3 fix).
 *
 * Computes, from the SAME inputs the finalize listener uses, whether a counting
 * line WILL post as an onboarding opening balance (`will_post_as_opening`) and
 * whether that opening still lacks a cost (`opening_cost_missing`). This is the
 * single source of truth shared by:
 *
 *   - the web review payload (so the cost gate is visible/actionable BEFORE
 *     finalize, per spec §5), and
 *   - the server-side finalize gate (InventoryCountingService::finalize, the
 *     real guarantee).
 *
 * Before this gate existed, the only signal was the post-finalize
 * `pending_opening_cost` flag stamped by ApplyStockAdjustmentsOnCountingCompleted
 * — inert before finalize, and unfixable after (Finalized has no re-post path).
 *
 * Reuses FirstCountDetector's SQL rather than duplicating it, and mirrors the
 * cost-missing rule in ApplyStockAdjustmentsOnCountingCompleted::resolveOpeningUnitCost
 * (explicit item cost — even '0' — is supplied; a non-positive product
 * `cost_price` fallback counts as MISSING).
 */
final class OpeningCostGate
{
    /**
     * Cost scale (6) — matches `opening_unit_cost`'s decimal(_,6) storage and
     * the listener's resolveOpeningUnitCost comparison.
     */
    private const COST_SCALE = 6;

    public function __construct(
        private readonly FirstCountDetector $firstCountDetector,
    ) {}

    /**
     * True when this line will post as an opening balance: its location is in
     * onboarding mode AND no supply-side baseline movement exists yet for the
     * (product, location[, variant]) grain.
     */
    public function willPostAsOpening(
        bool $locationOnboarding,
        string $productId,
        string $locationId,
        ?string $variantId,
    ): bool {
        return $locationOnboarding
            && $this->firstCountDetector->isFirstCount($productId, $locationId, $variantId);
    }

    /**
     * True when a will-post-as-opening line has no resolvable positive cost:
     * item-level `opening_unit_cost` unset (an explicit '0' counts as supplied)
     * AND the product `cost_price` fallback is ≤ 0.
     *
     * @param  numeric-string|null  $openingUnitCost
     * @param  numeric-string|null  $costPrice
     */
    public function openingCostMissing(
        bool $willPostAsOpening,
        ?string $openingUnitCost,
        ?string $costPrice,
    ): bool {
        if (! $willPostAsOpening) {
            return false;
        }

        // An explicit item-level cost (including a deliberate '0') satisfies.
        if ($openingUnitCost !== null) {
            return false;
        }

        $cost = $costPrice ?? '0';

        return bccomp($cost, '0', self::COST_SCALE) <= 0;
    }

    /**
     * Evaluate a single counting item. The caller supplies the resolved
     * onboarding flag for the item's location; the item's `product` relation
     * must be loaded (its `cost_price` is the fallback).
     *
     * @return array{will_post_as_opening: bool, opening_cost_missing: bool}
     */
    public function evaluateItem(InventoryCountingItem $item, bool $locationOnboarding): array
    {
        $willPost = $this->willPostAsOpening(
            $locationOnboarding,
            $item->product_id,
            $item->location_id,
            $item->variant_id,
        );

        // `cost_price` is a non-null decimal string on Product (defaults to '0').
        /** @var numeric-string $costPrice */
        $costPrice = (string) $item->product->cost_price;

        $openingUnitCost = null;
        if ($item->opening_unit_cost !== null) {
            /** @var numeric-string $openingUnitCost */
            $openingUnitCost = (string) $item->opening_unit_cost;
        }

        return [
            'will_post_as_opening' => $willPost,
            'opening_cost_missing' => $this->openingCostMissing($willPost, $openingUnitCost, $costPrice),
        ];
    }
}
