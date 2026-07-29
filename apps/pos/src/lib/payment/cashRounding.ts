/**
 * Cash rounding + tender tolerance — pure device helpers (spec 2026-07-27 §4.1).
 *
 * Every function here is total, side-effect-free and string-in/string-out so
 * the checkout snapshot builder can be unit-pinned without a DB, a store or a
 * network. NOTHING in this module reads global state.
 *
 * Rounding contract:
 *   rounded = round_half_up(exact / D) * D      (ties away from zero; totals
 *                                               are non-negative so that is
 *                                               plain half-up)
 *   adj     = rounded - exact                   (signed, |adj| <= D/2)
 *
 * `Big.RM = 1` is set once in `@/lib/decimal` and is the half-up rounding mode
 * every bc* helper inherits; do not change it.
 *
 * FAIL-CLOSED: the payment-policy slice performs no runtime shape validation,
 * so a degenerate `{"data":{}}` pull can install a NON-null policy whose fields
 * are `undefined` while typed `string`. These functions are the last line of
 * defence and never assume a field is present, numeric, or non-negative — an
 * unusable value disables the mechanism it feeds rather than guessing a value.
 * The outputs here are hashed into the SALE_RECEIPT v3 canonical bytes; a
 * guessed rounding would quarantine the receipt on the server.
 */

import { bcadd, bccomp, bcdiv, bcformat, bcmul, bcsub } from '@/lib/decimal';

/** A single tender leg — a payment line OR a voucher tender (they are the same thing). */
export interface TenderLeg {
  readonly methodCode: string;
  readonly amount: string;
}

/**
 * Static, history-stable ceilings on the signed denomination, mirrored from
 * the server bind (spec §4.1). A suppression payload claiming a huge
 * denomination fails here on the device and in the validator server-side.
 * JPY 10-yen rounding is real, hence the scale-0 entry.
 *
 * Doubles as the set of currency scales this module will round at: a scale
 * with no entry has no agreed cap, so rounding is refused for it.
 */
export const DENOMINATION_CAP_BY_SCALE: Readonly<Record<number, string>> = {
  0: '10',
  2: '1.00',
  3: '1.000',
};

/** Per-shift auto-accept ceiling before tolerance escalates to the PIN path (spec §8.1). */
export const TOLERANCE_AUTO_ACCEPT_LIMIT_PER_SHIFT = 10 as const;

/** Plain non-negative decimal literal — no sign, no exponent, no whitespace. */
const NON_NEGATIVE_DECIMAL = /^\d+(\.\d+)?$/;

/**
 * Trimmed decimal literal, or null when the value is absent (`null`/
 * `undefined` from an unvalidated policy), blank, signed or non-numeric.
 */
function sanitizeNonNegativeDecimal(value: string | null | undefined): string | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  return NON_NEGATIVE_DECIMAL.test(trimmed) ? trimmed : null;
}

/** As above, then formatted at `scale`. Null propagates. */
function parseNonNegativeAtScale(
  value: string | null | undefined,
  scale: number,
): string | null {
  const sanitized = sanitizeNonNegativeDecimal(value);
  return sanitized === null ? null : bcformat(sanitized, scale);
}

/**
 * Truncate (never round up) a non-negative decimal string at `scale`.
 * Used for the tolerance percentage cap so the accepted ceiling can only ever
 * be conservative — the denomination floor is the mechanism that admits a
 * full-D shortfall, not an inflated percentage.
 */
function truncateAtScale(value: string, scale: number): string {
  const [intPart = '0', fracPart = ''] = value.split('.');
  if (scale === 0) return intPart;
  return `${intPart}.${`${fracPart}${'0'.repeat(scale)}`.slice(0, scale)}`;
}

/**
 * The denomination normalized to `scale`, or null when it is unusable.
 *
 * Usable means: present and numeric, strictly positive, EXACTLY representable
 * at `scale` (round-trip equality — '0.0025' on a scale-3 currency is
 * rejected, never silently re-scaled, because the re-scaled value would no
 * longer divide the signed total), and within the static cap for that scale.
 *
 * Returning the normalized string rather than a boolean is deliberate: it makes
 * the normative assert-order (validate the denomination BEFORE any division or
 * modulo by it) structural instead of a comment callers can forget.
 */
function normalizeDenomination(
  denomination: string | null | undefined,
  scale: number,
): string | null {
  const sanitized = sanitizeNonNegativeDecimal(denomination);
  if (sanitized === null) return null;

  const cap = DENOMINATION_CAP_BY_SCALE[scale];
  if (cap === undefined) return null;

  const normalized = bcformat(sanitized, scale);
  if (bccomp(normalized, '0') <= 0) return null;
  if (bccomp(normalized, sanitized) !== 0) return null;
  if (bccomp(normalized, cap) > 0) return null;

  return normalized;
}

/**
 * True when `denomination` is a usable rounding step at `scale`. Anything else
 * — missing, blank, non-numeric, zero, negative, not representable at `scale`,
 * or over the cap — disables rounding entirely (exact today-behavior).
 *
 * The server sends a canonical zero at currency scale ('0.000') when rounding
 * is off, which this correctly rejects; `null` only occurs on a device that has
 * never synced a policy. `undefined` is accepted in the signature on purpose —
 * an unvalidated policy response can hand us one despite the `string` type, and
 * callers should not have to launder it through a cast to ask this question.
 */
export function isValidDenomination(
  denomination: string | null | undefined,
  scale: number,
): denomination is string {
  return normalizeDenomination(denomination, scale) !== null;
}

/**
 * Swedish-round `exactTotal` to the nearest multiple of `denomination`.
 * Caller MUST have validated the denomination first (`isValidDenomination`) —
 * the assert order is normative; an unvalidated zero would reach `bcdiv` and
 * throw rather than fail closed.
 */
export function roundCashTotal(exactTotal: string, denomination: string, scale: number): string {
  const units = bcdiv(bcformat(exactTotal, scale), denomination, 0);
  return bcformat(bcmul(units, denomination, scale), scale);
}

/**
 * Signed `rounded - exact` at currency scale. Canonical zero when they agree.
 *
 * BOTH operands are normalized to `scale` first, exactly as `roundCashTotal`
 * normalizes its dividend. That is what guarantees the identity
 *
 *     rounded - adjustment == bcformat(exact, scale)
 *
 * which is the device-side form of Task 8's v3 aggregate bind
 * (`subtotal + vat_total == (total - cash_rounding_adjustment) + discount`).
 * Subtracting a RAW `exactTotal` here while `roundCashTotal` rounds a
 * normalized one would make the two functions relative to different "exact"
 * values whenever a caller passes more than `scale` decimals, and the one-ulp
 * gap would throw `SaleReceiptAggregateInvariantError` on a signed receipt.
 * The invariant must hold for ANY caller, not only for the ones that happen to
 * pre-format their total.
 */
export function computeRoundingAdjustment(
  exactTotal: string,
  roundedTotal: string,
  scale: number,
): string {
  return bcformat(
    bcsub(bcformat(roundedTotal, scale), bcformat(exactTotal, scale), scale),
    scale,
  );
}

/**
 * True when EVERY leg of the union (payment lines + voucher tenders) is a cash
 * method. An empty tender is never cash-only. Cash-ness comes from
 * `payment_methods.is_cash_tender` via the injected predicate — never from
 * is_physical/has_maturity (that misclassifies MEAL_VOUCHER) and never from a
 * hardcoded 'CASH' string comparison in this module.
 */
export function isCashOnlyTender(
  legs: readonly TenderLeg[],
  isCashMethodCode: (code: string) => boolean,
): boolean {
  if (legs.length === 0) return false;
  return legs.every((leg) => isCashMethodCode(leg.methodCode));
}

export interface ToleranceMaxInput {
  readonly exactTotal: string;
  /** Fraction, not a percent literal: 0.5% arrives as '0.0050'. */
  readonly percentage: string;
  readonly maxAmount: string;
  readonly denomination: string | null;
  readonly roundingActive: boolean;
  readonly scale: number;
}

/**
 * `min(pct * total, max_amount)`, floored to the denomination when rounding is
 * active on a positive-total sale (owner decision §8.1). Returns a
 * currency-scale string; a zero result means "no auto-accept headroom".
 *
 * `percentage` is a FRACTION ('0.0050' == 0.5%) — there is no /100 anywhere.
 *
 * FAIL-CLOSED, and the floor cannot outlive it: if EITHER tolerance input is
 * unusable (absent, blank, signed, non-numeric) the tolerance policy is not
 * configured at all, so the result is zero and the denomination floor is NOT
 * applied. The floor exists to WIDEN a configured cap so a full-D shortfall is
 * acceptable while rounding is on — it is not itself a source of headroom, and
 * manufacturing D of auto-accept out of a corrupt policy is exactly the guess
 * this module must never make. A policy that legitimately configures zero
 * tolerance parses fine and still gets the spec's floor.
 */
export function toleranceEffectiveMax(input: ToleranceMaxInput): string {
  const { exactTotal, percentage, maxAmount, denomination, roundingActive, scale } = input;
  const zero = bcformat('0', scale);

  const total = parseNonNegativeAtScale(exactTotal, scale);
  if (total === null || bccomp(total, '0') <= 0) {
    return zero;
  }

  const pct = sanitizeNonNegativeDecimal(percentage);
  const max = sanitizeNonNegativeDecimal(maxAmount);
  if (pct === null || max === null) {
    return zero;
  }

  // Both caps TRUNCATED (never rounded up) at currency scale, so an accepted
  // ceiling can only ever be conservative. The percentage is kept at full
  // precision for the multiply and truncated only once, at the end.
  const pctCap = truncateAtScale(bcmul(total, pct, scale + 4), scale);
  const maxCap = truncateAtScale(max, scale);

  let effective = bccomp(pctCap, maxCap) < 0 ? pctCap : maxCap;

  if (roundingActive) {
    // Assert-order: normalize (validate) the denomination BEFORE it is used.
    const floor = normalizeDenomination(denomination, scale);
    if (floor !== null && bccomp(floor, effective) > 0) {
      effective = floor;
    }
  }

  return effective;
}

/** Positive shortfall `total - tendered`, clamped at zero. */
export function computeShortfall(total: string, tendered: string, scale: number): string {
  const raw = bcsub(bcformat(total, scale), bcformat(tendered, scale), scale);
  return bccomp(raw, '0') > 0 ? raw : bcformat('0', scale);
}

/** Positive change `tendered - total`, clamped at zero. */
export function computeChange(total: string, tendered: string, scale: number): string {
  const raw = bcsub(bcformat(tendered, scale), bcformat(total, scale), scale);
  return bccomp(raw, '0') > 0 ? raw : bcformat('0', scale);
}

/** Sum of the legs whose method is cash, at currency scale. */
export function sumCashLegs(
  legs: readonly TenderLeg[],
  isCashMethodCode: (code: string) => boolean,
  scale: number,
): string {
  let total = bcformat('0', scale);
  for (const leg of legs) {
    if (isCashMethodCode(leg.methodCode)) {
      total = bcadd(total, leg.amount, scale);
    }
  }
  return total;
}
