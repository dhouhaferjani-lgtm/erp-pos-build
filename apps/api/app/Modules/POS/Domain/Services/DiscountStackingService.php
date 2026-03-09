<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\POS\Domain\ValueObjects\DiscountBreakdown;
use App\Modules\POS\Domain\ValueObjects\DiscountLine;
use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * Resolves stacking rules for discount candidates from multiple sources.
 *
 * Algorithm:
 * 1. Group candidates by stacking_group
 * 2. Within each group: if any candidate is exclusive, keep only highest-priority exclusive
 * 3. Non-exclusive candidates in same group stack additively
 * 4. Sum across groups
 * 5. Cap total at subtotal
 */
final class DiscountStackingService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * @param  array<int, DiscountLine>  $candidates
     * @param  numeric-string  $subtotal
     */
    public function resolve(array $candidates, string $subtotal): DiscountBreakdown
    {
        if (count($candidates) === 0) {
            return new DiscountBreakdown([], '0.00', []);
        }

        // Group by stacking_group
        /** @var array<string, array<int, DiscountLine>> $groups */
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate->stackingGroup][] = $candidate;
        }

        $resolvedLines = [];

        foreach ($groups as $groupCandidates) {
            // Check if any exclusive candidate exists in this group
            $exclusives = array_filter($groupCandidates, fn (DiscountLine $c) => $c->isExclusive);

            if (count($exclusives) > 0) {
                // Keep only the highest-priority exclusive (lower number = higher priority)
                usort($exclusives, fn (DiscountLine $a, DiscountLine $b) => $a->priority <=> $b->priority);
                $resolvedLines[] = reset($exclusives);
            } else {
                // All non-exclusive — they stack additively
                foreach ($groupCandidates as $candidate) {
                    $resolvedLines[] = $candidate;
                }
            }
        }

        // Calculate totals, split by transaction vs line
        /** @var numeric-string $totalTransactionDiscount */
        $totalTransactionDiscount = '0.00';
        /** @var array<string, numeric-string> $lineDiscounts */
        $lineDiscounts = [];

        foreach ($resolvedLines as $line) {
            if ($line->appliesTo === 'transaction') {
                $totalTransactionDiscount = bcadd($totalTransactionDiscount, $line->discountAmount, $this->scale());
            } else {
                // Line-level discount (appliesTo = 'line:{product_id}')
                $productId = str_replace('line:', '', $line->appliesTo);
                if (! isset($lineDiscounts[$productId])) {
                    $lineDiscounts[$productId] = '0.00';
                }
                $lineDiscounts[$productId] = bcadd($lineDiscounts[$productId], $line->discountAmount, $this->scale());
            }
        }

        // Cap total at subtotal
        /** @var numeric-string $grandTotal */
        $grandTotal = $totalTransactionDiscount;
        foreach ($lineDiscounts as $amount) {
            $grandTotal = bcadd($grandTotal, $amount, $this->scale());
        }

        if (bccomp($grandTotal, $subtotal, $this->scale()) > 0) {
            // Scale down proportionally to fit within subtotal
            $ratio = bcdiv($subtotal, $grandTotal, 8);
            $totalTransactionDiscount = bcmul($totalTransactionDiscount, $ratio, $this->scale());

            foreach ($lineDiscounts as $productId => $amount) {
                $lineDiscounts[$productId] = bcmul($amount, $ratio, $this->scale());
            }
        }

        return new DiscountBreakdown($resolvedLines, $totalTransactionDiscount, $lineDiscounts);
    }
}
