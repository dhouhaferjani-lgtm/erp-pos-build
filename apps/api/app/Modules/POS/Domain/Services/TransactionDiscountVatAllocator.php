<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Shared\Domain\TransactionRemiseSplit;
use RuntimeException;

/**
 * D-1 — ventilation of a transaction-level discount across the VAT rates of a
 * POS receipt (owner ruling 2026-08-25, option (a)).
 *
 * The PHP twin of `apps/pos/src/lib/fiscal/vatDiscountAllocation.ts`. The
 * DEVICE authors and seals its own ventilation; this class exists for the
 * SERVER-authored receipt path (`ReceiptCreationService`, the non-device POS
 * API), which computes the VAT itself and carried exactly the same defect —
 * `aggregateVat()` rolls the lines up BEFORE the transaction discount, so a
 * discounted receipt declared VAT on a base the customer never paid.
 *
 * It is NEVER used to recompute a device-sealed receipt. The projector mirrors
 * the sealed rows verbatim and `FiscalPayloadConstraintValidator` re-validates
 * them without dividing by a rate.
 *
 * ## Algorithm (identical to the device's, verbatim)
 *
 * 1. `gross_r = net_r + vat_r` per rate group (line-level discounts already
 *    inside the line price).
 * 2. `disc_r = discount x gross_r / Σ gross`, FLOORED at currency scale, the
 *    residue handed out one ulp at a time in descending-remainder order with a
 *    per-group capacity guard, so `Σ disc_r == discount` EXACTLY. Zero-VAT and
 *    exempt groups participate — they carry gross too.
 * 3. The allocated share is split into its own net and VAT halves
 *    (`disc_net_r = disc_r / (1 + rate)`, half-up) and SUBTRACTED from the
 *    group's line sums, clamped inside them. Subtracting from the line sums
 *    (rather than re-deriving the base from the rate) makes the discount-free
 *    case byte-identical to the pre-D-1 roll-up, and makes a 100 % comp land on
 *    exactly zero rather than a +/-1 ulp residue.
 *
 * All arithmetic is bcmath at the currency scale; the proportional intermediate
 * is carried at `scale + 4`. `bcdiv` TRUNCATES, so every half-up rounding goes
 * through {@see roundHalfUp()} — never a bare `bcdiv` at the target scale.
 */
final class TransactionDiscountVatAllocator
{
    /** Precision of the proportional intermediate, above the currency scale. */
    private const RATIO_EXTRA_SCALE = TransactionRemiseSplit::RATIO_EXTRA_SCALE;

    /**
     * Ventilate `$discount` across `$groups`.
     *
     * @param  list<array{tax_rate: numeric-string, net_amount: numeric-string, vat_amount: numeric-string}>  $groups
     * @param  numeric-string  $discount
     * @return list<array{tax_rate: numeric-string, net_amount: numeric-string, vat_amount: numeric-string, gross_amount: numeric-string, discount_allocated: numeric-string}>
     *
     * @throws RuntimeException when the ticket cannot absorb the discount
     */
    public function allocate(array $groups, string $discount, int $scale): array
    {
        $zero = bcadd('0', '0', $scale);
        $grosses = [];
        $totalGross = $zero;
        foreach ($groups as $group) {
            $gross = bcadd($group['net_amount'], $group['vat_amount'], $scale);
            $grosses[] = $gross;
            $totalGross = bcadd($totalGross, $gross, $scale);
        }

        $normalisedDiscount = bcadd($discount, '0', $scale);
        if (bccomp($normalisedDiscount, '0', $scale) < 0) {
            throw new RuntimeException('pos_transaction_discount_negative:'.$normalisedDiscount);
        }
        if (bccomp($normalisedDiscount, $totalGross, $scale) > 0) {
            throw new RuntimeException(
                'pos_transaction_discount_exceeds_gross:discount='.$normalisedDiscount.':gross='.$totalGross
            );
        }

        $allocations = bccomp($normalisedDiscount, '0', $scale) === 0
            ? array_fill(0, count($groups), $zero)
            : $this->largestRemainder($grosses, $totalGross, $normalisedDiscount, $scale);

        $out = [];
        foreach ($groups as $index => $group) {
            $allocated = $allocations[$index] ?? $zero;
            [$discNet, $discVat] = $this->splitAllocated($allocated, $group, $scale);
            $net = bcsub($group['net_amount'], $discNet, $scale);
            $vat = bcsub($group['vat_amount'], $discVat, $scale);
            /** @var numeric-string $net */
            /** @var numeric-string $vat */
            $out[] = [
                'tax_rate' => $group['tax_rate'],
                'net_amount' => $net,
                'vat_amount' => $vat,
                'gross_amount' => bcadd($net, $vat, $scale),
                'discount_allocated' => $allocated,
            ];
        }

        return $out;
    }

    /**
     * @param  list<numeric-string>  $grosses
     * @param  numeric-string  $totalGross
     * @param  numeric-string  $discount
     * @return list<numeric-string>
     */
    private function largestRemainder(array $grosses, string $totalGross, string $discount, int $scale): array
    {
        if (bccomp($totalGross, '0', $scale) === 0) {
            throw new RuntimeException('pos_transaction_discount_on_zero_gross_ticket:'.$discount);
        }

        $ratioScale = $scale + self::RATIO_EXTRA_SCALE;
        $ulp = $this->ulp($scale);
        $shares = [];
        $remainders = [];
        $allocated = bcadd('0', '0', $scale);

        foreach ($grosses as $index => $gross) {
            // bcdiv TRUNCATES, which is exactly the FLOOR this method wants.
            $exact = bcdiv(bcmul($discount, $gross, $ratioScale), $totalGross, $ratioScale);
            $floored = bcadd(bcdiv($exact, '1', $scale), '0', $scale);
            $shares[$index] = $floored;
            $remainders[] = ['index' => $index, 'remainder' => bcsub($exact, $floored, $ratioScale)];
            $allocated = bcadd($allocated, $floored, $scale);
        }

        usort($remainders, static function (array $a, array $b) use ($ratioScale): int {
            $cmp = bccomp($b['remainder'], $a['remainder'], $ratioScale);

            return $cmp !== 0 ? $cmp : $a['index'] <=> $b['index'];
        });

        $residue = bcsub($discount, $allocated, $scale);
        // Two passes: descending remainder first (the canonical tie-break),
        // then a sweep over whatever still has capacity. The second pass is
        // reachable only when a high-remainder group is already at its own
        // gross ceiling.
        foreach ([$remainders, $remainders] as $pass) {
            foreach ($pass as $entry) {
                if (bccomp($residue, '0', $scale) <= 0) {
                    break;
                }
                $index = $entry['index'];
                if (bccomp($shares[$index], $grosses[$index], $scale) >= 0) {
                    continue;
                }
                $shares[$index] = bcadd($shares[$index], $ulp, $scale);
                $residue = bcsub($residue, $ulp, $scale);
            }
        }

        if (bccomp($residue, '0', $scale) !== 0) {
            throw new RuntimeException('pos_transaction_discount_residue_unallocatable:'.$residue);
        }

        ksort($shares);

        /** @var list<numeric-string> $values */
        $values = array_values($shares);

        return $values;
    }

    /**
     * Split one group's allocated discount into its net and VAT halves,
     * clamped inside the group's own line sums.
     *
     * `disc_r <= gross_r` is guaranteed by the caller, so at most one clamp can
     * bind and the pair always sums back to `disc_r`.
     *
     * @param  numeric-string  $allocated
     * @param  array{tax_rate: numeric-string, net_amount: numeric-string, vat_amount: numeric-string}  $group
     * @return array{numeric-string, numeric-string}
     */
    private function splitAllocated(string $allocated, array $group, int $scale): array
    {
        // Delegates to the shared kernel (gate r1 finding 2): the SERVER
        // CONTRACT VALIDATOR now re-derives this same split to PIN a sealed
        // payload's net/VAT halves, so the rule had to stop being a private
        // detail of this class and become one authority all three consumers
        // read. Behaviour is unchanged — `TransactionDiscountVatAllocatorTest`
        // pins the worked example digit-for-digit across the extraction.
        return TransactionRemiseSplit::split(
            $allocated,
            $group['tax_rate'],
            $group['net_amount'],
            $group['vat_amount'],
            $scale,
        );
    }

    /**
     * `1` at the last representable digit of `$scale` — the shared rule.
     *
     * (`roundHalfUp()` used to live here too; since the split moved to
     * `TransactionRemiseSplit` this class no longer rounds anything itself, so
     * the wrapper was removed rather than left as dead delegation.)
     *
     * @return numeric-string
     */
    private function ulp(int $scale): string
    {
        return TransactionRemiseSplit::ulp($scale);
    }
}
