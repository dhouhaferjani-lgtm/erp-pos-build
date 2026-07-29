/**
 * SaleReceiptV3 — `event_version = 3` canonical SALE_RECEIPT payload
 * (cash rounding, spec 2026-07-27 §4.4).
 *
 * V3 is a strict superset of V2: `total` becomes the ROUNDED total and two
 * signed siblings are appended — `cash_rounding_adjustment` (signed) and
 * `cash_rounding_denomination` (non-negative, normalized at currency scale).
 * Every receipt is therefore verifiable against its OWN authoring policy,
 * offline, forever.
 *
 * Build order is NORMATIVE and must not be reordered:
 *   1. delegate to buildSaleReceiptV2Payload with the EXACT total, so V1's
 *      aggregate assert runs unchanged and passes;
 *   2. replace `total` with the rounded total;
 *   3. add the two rounding fields;
 *   4. run assertSaleReceiptAggregatesV3.
 *
 * The V1 and V2 builders are NEVER mutated — Events are Immutable Forever.
 */

import { getCurrencyDecimals } from '@/lib/currency';
import { bcabs, bcadd, bccomp, bcdiv, bcformat, bcmod, bcsub } from '@/lib/decimal';
import {
  SaleReceiptAggregateInvariantError,
  type BuildSaleReceiptPayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptPayload';
import {
  buildSaleReceiptV2Payload,
  type SaleReceiptV2PayloadInput,
} from '@/lib/fiscal/payloads/SaleReceiptV2Payload';

export interface SaleReceiptV3PayloadInput extends SaleReceiptV2PayloadInput {
  /** Signed `rounded_total - exact_total` at currency scale; canonical zero when unrounded. */
  readonly cash_rounding_adjustment: string;
  /** Non-negative rounding step at currency scale; canonical zero when unrounded. */
  readonly cash_rounding_denomination: string;
}

export interface SaleReceiptV3RoundingInput {
  readonly exactTotal: string;
  readonly roundedTotal: string;
  readonly adjustment: string;
  readonly denomination: string;
}

/**
 * Static, history-stable ceilings on the signed denomination (spec §4.1).
 * Mirrors `DENOMINATION_CAP_BY_SCALE` in `lib/payment/cashRounding.ts` and the
 * server-side bind; duplicated here so the payload assert has no dependency on
 * the checkout layer — what may be SIGNED must not change because what may be
 * OFFERED changed. `SaleReceiptV3Payload.test.ts` pins the two against each
 * other so the deliberate duplication can never become silent drift.
 */
export const V3_DENOMINATION_CAP_BY_SCALE: Readonly<Record<number, string>> = {
  0: '10',
  2: '1.00',
  3: '1.000',
};

/**
 * Format at `scale` and collapse EVERY BCMath-equivalent zero onto the
 * unsigned canonical zero.
 *
 * big.js does not fully normalize negative zero: `new Big('-0.0004')
 * .toFixed(3)` is the STRING `'-0.000'`. The server rejects that as
 * `payload_money_negative_zero`, and a quarantined receipt cannot be repaired
 * in place — the chain is signed. So the sign is stripped here, at the only
 * place where the bytes are authored.
 */
function canonicalMoney(value: string, scale: number): string {
  const formatted = bcformat(value, scale);

  return bccomp(formatted, '0') === 0 ? bcformat('0', scale) : formatted;
}

export function buildSaleReceiptV3Payload(
  input: BuildSaleReceiptPayloadInput,
  rounding: SaleReceiptV3RoundingInput,
): SaleReceiptV3PayloadInput {
  const scale = getCurrencyDecimals(input.currency);

  // (1) V2 with the EXACT total — V1's aggregate assert must see the total it
  //     can reconcile against subtotal/vat/discount.
  const v2 = buildSaleReceiptV2Payload({
    ...input,
    total: bcformat(rounding.exactTotal, scale),
  });

  // (2) + (3)
  const payload: SaleReceiptV3PayloadInput = {
    ...v2,
    total: canonicalMoney(rounding.roundedTotal, scale),
    cash_rounding_adjustment: canonicalMoney(rounding.adjustment, scale),
    cash_rounding_denomination: canonicalMoney(rounding.denomination, scale),
  };

  // (4)
  assertSaleReceiptAggregatesV3(payload, scale);

  return payload;
}

/**
 * V3 aggregate identity + rounding binds. Evaluation ORDER is normative
 * (spec §4.1): the denomination-positivity check runs BEFORE any modulo so a
 * zero divisor is unreachable — a raw division error would escape the
 * quarantine path server-side and kill the worker.
 */
export function assertSaleReceiptAggregatesV3(
  payload: SaleReceiptV3PayloadInput,
  scale: number,
): void {
  const adjustment = payload.cash_rounding_adjustment;
  const denomination = payload.cash_rounding_denomination;

  // 1. subtotal + vat_total == (total - adjustment) + transaction_discount_amount
  const lhs = bcformat(bcadd(payload.subtotal, payload.vat_total, scale), scale);
  const rhs = bcformat(
    bcadd(
      bcsub(payload.total, adjustment, scale),
      payload.transaction_discount_amount,
      scale,
    ),
    scale,
  );
  if (bccomp(lhs, rhs) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 aggregate invariant violated: subtotal (${payload.subtotal}) + vat_total `
      + `(${payload.vat_total}) = ${lhs} != (total (${payload.total}) - `
      + `cash_rounding_adjustment (${adjustment})) + transaction_discount_amount `
      + `(${payload.transaction_discount_amount}) = ${rhs}.`,
    );
  }

  // 1b. The denomination is a STEP, never a signed amount — a negative one is
  //     structurally invalid whether or not rounding applied. Checked before
  //     the canonical-zero shortcut so `{adj: 0, denomination: -0.050}` can
  //     never reach the encoder.
  if (bccomp(denomination, '0') < 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: cash_rounding_denomination ${denomination} is negative.`,
    );
  }

  if (bccomp(adjustment, bcformat('0', scale)) === 0) {
    return;
  }

  // 2a. denomination strictly positive — BEFORE any modulo.
  if (bccomp(denomination, '0') <= 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: cash_rounding_adjustment ${adjustment} is non-zero `
      + `while cash_rounding_denomination is ${denomination}.`,
    );
  }

  // 2b. |adjustment| <= denomination / 2, compared at scale + 1 (truncation-safe).
  const half = bcdiv(denomination, '2', scale + 1);
  if (bccomp(bcabs(adjustment, scale + 1), half) > 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: |cash_rounding_adjustment| (${adjustment}) exceeds `
      + `half the cash_rounding_denomination (${denomination} / 2 = ${half}).`,
    );
  }

  // 2c. total is an exact multiple of denomination.
  const remainder = bcmod(payload.total, denomination, scale);
  if (bccomp(remainder, bcformat('0', scale)) !== 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: total ${payload.total} is not a multiple of `
      + `cash_rounding_denomination ${denomination} (remainder ${remainder}).`,
    );
  }

  // 3. Static history-stable cap on the denomination.
  const cap = V3_DENOMINATION_CAP_BY_SCALE[scale];
  if (cap === undefined || bccomp(denomination, cap) > 0) {
    throw new SaleReceiptAggregateInvariantError(
      `V3 rounding bind violated: cash_rounding_denomination ${denomination} exceeds `
      + `the scale-${String(scale)} cap ${cap ?? '(unsupported scale)'}.`,
    );
  }
}
