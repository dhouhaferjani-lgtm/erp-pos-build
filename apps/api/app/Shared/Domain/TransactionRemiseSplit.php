<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use RuntimeException;

/**
 * D-1 (owner ruling 2026-08-25) — how ONE VAT-rate group's share of a
 * ticket-level remise divides into its net and VAT halves.
 *
 * ## Why this is a shared kernel class and not three implementations
 *
 * Three places need the identical answer, and a disagreement between any two of
 * them is a wrong number on a signed fiscal document:
 *
 *   1. the DEVICE, authoring the sealed `vat_breakdown[]`
 *      (`apps/pos/src/lib/fiscal/vatDiscountAllocation.ts` — the one copy that
 *      cannot be shared, pinned against this class by
 *      `TransactionDiscountVatAllocatorTest`'s worked example, which is the same
 *      fixture the device suite pins);
 *   2. the SERVER-authored POS path
 *      (`POS\Domain\Services\TransactionDiscountVatAllocator`);
 *   3. the SERVER CONTRACT VALIDATOR
 *      (`Fiscal\Application\Services\FiscalPayloadConstraintValidator`), which
 *      re-derives the expected split to PIN a sealed payload's net/VAT halves.
 *
 * (3) is the reason this had to be extracted. Without it the v5 partition check
 * bounded only the TOTAL of the two halves, so a device could put the whole
 * remise on the VAT side and under-declare output VAT by up to
 * `min(remise, Σ line_vat)` per receipt with the server raising nothing — gate
 * r1 finding 2 demonstrated 30.703 TND on the ruling's own worked example.
 *
 * ## What it does NOT do
 *
 * It never recomputes a group's VAT from its rate. The group's VAT stays
 * `Σ line_vat − discVat`, where `Σ line_vat` is sealed line data. Only the
 * CARVING of the remise is derived here, from four values the server already
 * holds — the allocated share, the rate, and the group's own line sums. So the
 * validator gains an EXACT pin without becoming a second authority for the VAT
 * itself.
 *
 * ## The rule
 *
 * `discNet = round_half_up(allocated / (1 + rate))`, `discVat = allocated − discNet`,
 * both CLAMPED inside the group's own line sums. The clamps are what make a
 * 100 % comp land on exactly `net == vat == 0` instead of a ±1 ulp residue;
 * `allocated <= lineNet + lineVat` is the caller's guarantee, so at most one
 * clamp can bind and the pair always sums back to `allocated`.
 *
 * bcmath has no rounding mode (`bcdiv` truncates), so half-up is done by adding
 * half an ulp before truncating. Every value here is non-negative money, so
 * "half up" and "half away from zero" coincide; the guard makes that explicit.
 */
final class TransactionRemiseSplit
{
    /** Precision of the proportional intermediate, above the currency scale. */
    public const RATIO_EXTRA_SCALE = 4;

    /**
     * @param  numeric-string  $allocated  this group's share of the ticket remise
     * @param  numeric-string  $rate  VAT rate as a PERCENT (e.g. '19.00')
     * @param  numeric-string  $lineNet  Σ line_subtotal for the group (pre-remise)
     * @param  numeric-string  $lineVat  Σ line_vat for the group (pre-remise)
     * @return array{numeric-string, numeric-string} `[discNet, discVat]`
     *
     * @throws RuntimeException on a negative input, which is never a rounding artefact
     */
    public static function split(
        string $allocated,
        string $rate,
        string $lineNet,
        string $lineVat,
        int $scale,
    ): array {
        $zero = bcadd('0', '0', $scale);
        foreach ([$allocated, $lineNet, $lineVat] as $value) {
            if (bccomp($value, '0', $scale) < 0) {
                throw new RuntimeException('transaction_remise_split_negative_input:'.$value);
            }
        }

        if (bccomp($allocated, '0', $scale) === 0) {
            return [$zero, $zero];
        }

        $ratioScale = $scale + self::RATIO_EXTRA_SCALE;
        $divisor = bcadd('1', bcdiv($rate, '100', $ratioScale), $ratioScale);
        $discNet = self::roundHalfUp(bcdiv($allocated, $divisor, $ratioScale), $scale);
        if (bccomp($discNet, $lineNet, $scale) > 0) {
            $discNet = bcadd($lineNet, '0', $scale);
        }
        $discVat = bcsub($allocated, $discNet, $scale);
        if (bccomp($discVat, $lineVat, $scale) > 0) {
            $discVat = bcadd($lineVat, '0', $scale);
            $discNet = bcsub($allocated, $discVat, $scale);
        }

        /** @var numeric-string $discNet */
        /** @var numeric-string $discVat */
        return [$discNet, $discVat];
    }

    /**
     * Half-up rounding at `$scale` for a NON-NEGATIVE value.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    public static function roundHalfUp(string $value, int $scale): string
    {
        if (bccomp($value, '0', $scale + self::RATIO_EXTRA_SCALE) < 0) {
            throw new RuntimeException('transaction_remise_split_negative_intermediate:'.$value);
        }

        $half = bcdiv(self::ulp($scale), '2', $scale + 1);

        /** @var numeric-string $rounded */
        $rounded = bcadd(bcadd($value, $half, $scale + self::RATIO_EXTRA_SCALE), '0', $scale);

        return $rounded;
    }

    /**
     * `1` at the last representable digit of `$scale`.
     *
     * Built by DIVISION rather than string concatenation: `bcdiv` returns a
     * genuine numeric-string, whereas `'0.'.str_repeat(…).'1'` is inferred as
     * non-falsy-string and would need a cast.
     *
     * @return numeric-string
     */
    public static function ulp(int $scale): string
    {
        if ($scale === 0) {
            return '1';
        }

        return bcdiv('1', bcpow('10', (string) $scale), $scale);
    }
}
