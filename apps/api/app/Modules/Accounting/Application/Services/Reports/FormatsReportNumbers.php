<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\QuantityScale;

/**
 * Number emission for the owner-report layer.
 *
 * These rows come off `DB::table()` as `stdClass`, so neither the Eloquent
 * decimal casts nor the `ForbidFloatCastOnDecimalProperty` PHPStan rule sees
 * them — the correctness of every money figure on the owner dashboard rests on
 * this trait alone.
 *
 * History (W-7 F-2, same family as W-6 D6 / W-8 F-3; fix lane L4): the previous
 * single helper did `rtrim(number_format((float) $value, 2, '.', ''), '0')`,
 * which
 *   1. cast money to `float` — CLAUDE.md rule 19, never;
 *   2. hardcoded scale 2, so a TND millime was truncated before the string was
 *      even built; and
 *   3. trimmed the zeros, emitting `300.000` as `"300"` and `181.100` as
 *      `"181.1"`.
 * It was also used for QUANTITIES, which are unit-scaled, never currency-scaled.
 *
 * The replacement splits the three kinds of number the reports emit, and each
 * one takes its scale from the value's OWN domain:
 *   - money    → the company currency scale (caller-resolved, never defaulted);
 *   - quantity → the product unit's `decimal_places`;
 *   - percent  → a fixed 2 dp (a percent is not a currency).
 */
trait FormatsReportNumbers
{
    /** A percentage is not currency-scaled — see the precision contract. */
    private const REPORT_PERCENT_SCALE = 2;

    /**
     * Working precision used to turn a float into a numeric string.
     *
     * Strictly finer than every currency scale in `CurrencyScale::SCALE_MAP`
     * (max 3) and than the quantity storage scale, so normalising here can never
     * lose a digit either domain cares about. Named locally rather than reusing
     * `QuantityScale::SCALE`: this is a money-and-quantity NORMALISATION scale,
     * not the quantity domain constant, and the two are only numerically equal
     * by coincidence.
     */
    private const MONEY_NORMALISATION_SCALE = 4;

    /**
     * Emit a MONEY figure at the caller-resolved currency scale.
     *
     * $scale has NO default on purpose: a defaulted scale is exactly how this
     * layer became currency-blind. Callers resolve it once per report from
     * `CurrencyScaleResolverInterface`.
     *
     * Rounds half-away-from-zero (the presentation-boundary helper) rather than
     * truncating, so a stored scale-4 figure — `pos_shifts.expected_cash` is
     * `decimal(16,4)` — renders to the currency scale without silently dropping
     * its last digit.
     *
     * @return numeric-string
     */
    private function decimalString(string|int|float|null $value, int $scale): string
    {
        return CurrencyScale::bcround($this->numericString($value), $scale);
    }

    /**
     * Emit a QUANTITY at the product unit's precision.
     *
     * Quantities live at `decimal(N,4)` and are displayed at the unit's
     * `decimal_places` (see `QuantityScale::formatForUnit`). Passing one through
     * the money helper renders `12.5000` as `12.50` on a EUR tenant — the
     * rule-19 violation W-7 F-2 filed against `StockAlertReportService` and
     * `SalesReportService`.
     *
     * @param  int|null  $decimalPlaces  the unit's `decimal_places`; null (a
     *                                   mixed-unit aggregate) falls back to the
     *                                   canonical storage scale.
     * @return numeric-string
     */
    private function quantityString(string|int|float|null $value, ?int $decimalPlaces): string
    {
        return QuantityScale::formatForUnit($this->numericString($value), $decimalPlaces);
    }

    /**
     * Emit a PERCENTAGE at a fixed 2 dp — independent of the currency.
     *
     * @return numeric-string
     */
    private function percentString(string|int|float|null $value): string
    {
        return CurrencyScale::bcround($this->numericString($value), self::REPORT_PERCENT_SCALE);
    }

    /**
     * Normalise a raw `stdClass` column into a well-formed numeric string.
     *
     * The STRING branch is the production path: PostgreSQL returns `numeric`
     * columns and aggregates as strings, so the value reaches the formatters
     * with its full stored precision intact and is never converted at all.
     *
     * The FLOAT branch exists ONLY for a float this code did not create and
     * cannot prevent — a value the DRIVER hands back as a float on a
     * non-PostgreSQL path (SQLite's `SUM()`), i.e. the branch the test suite
     * exercises and the one production does not. It is NOT a licence to compute
     * money with native PHP arithmetic and normalise afterwards: that is still a
     * rule-19 violation, and normalising cannot restore precision already lost.
     * See the emission-normalisation exemption in
     * `docs/architecture/precision-contract.md` § Emission & display, which names
     * this method as the only sanctioned site.
     *
     * It must ROUND, not truncate:
     * `777.775` is held as `777.77499999999998`, so truncating the binary
     * expansion at the normalisation scale yields `777.7749` and silently eats
     * the half BEFORE the currency-scale rounding ever runs. `number_format`
     * rounds and is scientific-notation safe — `(string) 1e-5` would yield
     * `"1.0E-5"`, which bcmath cannot parse.
     *
     * @return numeric-string
     */
    private function numericString(string|int|float|null $value): string
    {
        if ($value === null) {
            return '0';
        }

        if (is_float($value)) {
            /** @var numeric-string */
            return number_format($value, self::MONEY_NORMALISATION_SCALE, '.', '');
        }

        $trimmed = trim((string) $value);

        /** @var numeric-string */
        return is_numeric($trimmed) ? $trimmed : '0';
    }
}
