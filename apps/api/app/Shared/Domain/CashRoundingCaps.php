<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * STATIC, HISTORY-STABLE ceilings for a cash-rounding denomination (spec §4.1).
 *
 * A denomination larger than the cap is not a rounding step, it is a
 * suppression payload: at `D = 5.000` a 9.973 sale signs `total = 10.000`
 * with a −0.027..+4 adjustment and the receipt is indistinguishable from
 * fraud. The caps are therefore enforced at BOTH ends of the pipeline:
 *
 *  - `PosPaymentPolicyResolver` — the last server gate BEFORE the device ever
 *    caches the value, so a bad row never becomes signed bytes;
 *  - the Task-6 fiscal validator bind — the gate AFTER signing, which
 *    quarantines anything that got through anyway;
 *  - the Task-4 ops command — refuses to write a capped-out denomination.
 *
 * All three MUST read the table from here. Duplicating the literals is how
 * the two ends drift apart and a tenant's entire receipt stream quarantines.
 *
 * "History-stable" is literal: these values are baked into the verification of
 * receipts that are already signed. RELAXING A CAP RE-VALIDATES HISTORY AND
 * TIGHTENING ONE INVALIDATES IT — never edit an existing entry. Adding a scale
 * that is absent today is the only safe change.
 *
 * An unlisted scale is FAIL-CLOSED (no cap => no rounding), not unlimited.
 */
final class CashRoundingCaps
{
    /**
     * Maximum denomination per currency scale, as a decimal string at that scale.
     *
     * scale 0 = 10 — JPY 10-yen rounding is real (spec §4.1, r2 F6).
     *
     * @var array<int, numeric-string>
     */
    private const CAPS = [
        0 => '10',
        2 => '1.00',
        3 => '1.000',
    ];

    /**
     * The cap for a currency scale, or null when the scale has no sanctioned
     * cap — in which case the caller MUST disable rounding (fail-closed).
     *
     * @return numeric-string|null
     */
    public static function forScale(int $scale): ?string
    {
        return self::CAPS[$scale] ?? null;
    }

    /**
     * True only when $denomination is at or below the cap for $scale.
     *
     * An unlisted scale returns false — absence of a cap is never permission.
     *
     * @param  numeric-string  $denomination  Already normalized to $scale.
     */
    public static function isWithinCap(string $denomination, int $scale): bool
    {
        $cap = self::forScale($scale);

        if ($cap === null) {
            return false;
        }

        return bccomp($denomination, $cap, $scale) <= 0;
    }
}
