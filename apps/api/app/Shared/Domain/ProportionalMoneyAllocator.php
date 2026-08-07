<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Allocates a money total proportionally across numeric-string bases.
 *
 * Rounding rule: every non-absorber share is truncated to the requested money
 * scale. The absorber — the last positive base — receives the running remainder
 * at that same scale, so the returned shares sum exactly to the input total and
 * no rounding residue lands on a zero-base line.
 */
class ProportionalMoneyAllocator
{
    /**
     * @param  numeric-string  $total
     * @param  array<int, numeric-string>  $bases
     * @return array<int, numeric-string>
     */
    public function allocate(string $total, array $bases, int $scale, int $workingScale): array
    {
        $bases = array_values($bases);

        if ($bases === []) {
            return [];
        }

        $formattedTotal = CurrencyScale::bcformatStrict($total, $scale);
        $formattedBases = [];
        $subtotal = CurrencyScale::bcformatStrict('0', $workingScale);
        $absorberIndex = count($bases) - 1;

        foreach ($bases as $index => $base) {
            $formattedBase = CurrencyScale::bcformatStrict($base, $workingScale);
            $formattedBases[$index] = $formattedBase;
            $subtotal = bcadd($subtotal, $formattedBase, $workingScale);

            if (bccomp($formattedBase, '0', $workingScale) > 0) {
                $absorberIndex = $index;
            }
        }

        if (bccomp($subtotal, '0', $workingScale) <= 0 || bccomp($formattedTotal, '0', $scale) === 0) {
            return array_fill(0, count($bases), CurrencyScale::bcformatStrict('0', $scale));
        }

        $remaining = $formattedTotal;
        $shares = [];

        foreach ($formattedBases as $index => $base) {
            if ($index === $absorberIndex) {
                $share = CurrencyScale::bcformatStrict($remaining, $scale);
            } elseif (bccomp($base, '0', $workingScale) <= 0) {
                $share = CurrencyScale::bcformatStrict('0', $scale);
            } else {
                // Compute the share in ONE division step — multiply first, divide
                // once — so nothing is truncated before the multiplication. The
                // two-step "truncate the proportion, then multiply" path drifts a
                // millime on ratios that are not exactly representable in decimal
                // (e.g. 100/150), even though the absorber still forces the total
                // sum to reconcile (ticket 2026-08-03-w4-purchasing-inventory-defects
                // #1 / MTP-PUR-17).
                $share = CurrencyScale::bcformatStrict(
                    bcdiv(bcmul($formattedTotal, $base, $workingScale), $subtotal, $workingScale),
                    $scale,
                );
            }

            $shares[$index] = $share;
            $remaining = bcsub($remaining, $share, $scale);
        }

        return $shares;
    }
}
