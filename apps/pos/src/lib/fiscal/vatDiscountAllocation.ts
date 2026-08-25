/**
 * D-1 — ventilation of a transaction-level discount across the VAT rates of a
 * ticket (owner ruling 2026-08-25, option (a)).
 *
 * ## Why this exists
 *
 * Under the Tunisian Code de la TVA (art. 6) and the French CGI 267-II-1° /
 * BOFiP BOI-TVA-BASE-10-10-30, the taxable base is the price actually charged
 * **remises et rabais consentis sur la facture déduits**. A global discount
 * granted on a multi-rate ticket is therefore ventilated proportionally per
 * rate and the VAT recomputed on each NET base — never at a blended rate, and
 * never left on the pre-discount base.
 *
 * Before D-1 the device sealed `subtotal` / `vat_total` / `vat_breakdown[]` on
 * the PRE-discount base (`SaleReceiptPayload.buildVatBreakdown`, a pure
 * line-item roll-up) while `total` was already net of the discount. The
 * customer paid less than the base they were declared against, and the DGI
 * declaration — which reads the sealed rows verbatim — over-declared output VAT
 * by `discount x rate/(1+rate)` per discounted receipt.
 *
 * ## The algorithm (canonical; the server RE-VALIDATES, it never recomputes)
 *
 * 1. Group cart lines by `(vat_rate, tax_category_code)`; `gross_r` is the
 *    group's TTC roll-up (`Σ line_subtotal + Σ line_vat`, line-level discounts
 *    already inside the line price).
 * 2. `disc_r = discount x gross_r / Σ gross`, floored at currency scale, with
 *    the residue handed out by the LARGEST-REMAINDER method so
 *    `Σ disc_r == discount` EXACTLY (no 0.001 drift). Zero-VAT / exempt groups
 *    participate — they carry gross too.
 * 3. The allocated discount is then split into its own net and VAT parts
 *    (`disc_net_r = disc_r / (1 + rate)`, half-up at scale) and SUBTRACTED from
 *    the group's line sums:
 *      `net_r = Σ line_subtotal − disc_net_r`, `vat_r = Σ line_vat − disc_vat_r`.
 *
 *    Subtracting from the line sums (rather than re-deriving `net_r` from
 *    `gross_r / (1 + rate)`) is what makes the discount-free case BYTE-IDENTICAL
 *    to the pre-D-1 breakdown: with `disc_r == 0` the group is returned
 *    untouched. It also keeps the server's re-validation recomputation-free —
 *    it can check `Σ line_subtotal − net_r` and `Σ line_vat − vat_r` are both
 *    non-negative and sum to the sealed `discount_allocated`, without ever
 *    dividing by a rate.
 * 4. Both parts are clamped inside the group's own line sums, so a 100 % comp
 *    lands on exactly `net_r == vat_r == 0` instead of a +/-1 ulp residue.
 *
 * All arithmetic is decimal (big.js, half-up) at the CURRENCY scale; the
 * proportional intermediate is carried at `scale + 4` so the remainder ordering
 * is meaningful. No float ever touches these numbers.
 */

import { bcadd, bccomp, bcdiv, bcformat, bcmul, bcsub, bctrunc } from '@/lib/decimal';

/** Precision of the proportional intermediate, above the currency scale. */
const RATIO_EXTRA_SCALE = 4;

/**
 * Thrown when the ticket cannot carry the discount it was handed — a discount
 * larger than the ticket gross, or a positive discount on a zero-gross ticket.
 *
 * Nothing is signed when it throws: the caller is still ahead of the fiscal
 * engine append. A named class so callers and tests can assert the IDENTITY of
 * the refusal.
 */
export class TransactionDiscountAllocationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'TransactionDiscountAllocationError';
  }
}

/** A `(rate, category)` group as rolled up from the canonical line items. */
export interface VatGroupLineSums {
  /** Percent at `vat_rate_scale` (e.g. `'19.00'`). */
  readonly rate: string;
  /** Unified tax-category axis; `''` when the catalog carries none. */
  readonly category: string;
  /** `Σ line_subtotal` for the group, at currency scale. */
  readonly lineNet: string;
  /** `Σ line_vat` for the group, at currency scale. */
  readonly lineVat: string;
}

/** One ventilated group — the shape sealed into `vat_breakdown[]` at v5. */
export interface AllocatedVatGroup {
  readonly rate: string;
  readonly category: string;
  /** `Σ line_subtotal + Σ line_vat` — the group's TTC roll-up BEFORE the remise. */
  readonly grossBeforeDiscount: string;
  /** This group's pro-rata share of the transaction discount. */
  readonly discountAllocated: string;
  /** Taxable base AFTER the remise. */
  readonly netAmount: string;
  /** VAT on the post-remise base. */
  readonly vatAmount: string;
  /** `netAmount + vatAmount` == `grossBeforeDiscount − discountAllocated`. */
  readonly grossAmount: string;
}

/** Deterministic key: the same ordering `buildVatBreakdown` seals. */
function groupKey(group: { rate: string; category: string }): string {
  return `${group.rate}|${group.category}`;
}

/**
 * Ventilate `discount` across `groups` and return the post-discount base / VAT
 * per group, sorted by `(rate, category)` — the canonical `vat_breakdown[]`
 * order.
 *
 * @throws TransactionDiscountAllocationError when the discount cannot be
 *         absorbed by the ticket.
 */
export function allocateTransactionDiscount(
  groups: ReadonlyArray<VatGroupLineSums>,
  discount: string,
  scale: number,
): AllocatedVatGroup[] {
  const zero = bcformat('0', scale);
  const ulp = ulpFor(scale);
  const sorted = [...groups].sort((a, b) => groupKey(a).localeCompare(groupKey(b)));

  const grosses = sorted.map((g) => bcformat(bcadd(g.lineNet, g.lineVat, scale), scale));
  const totalGross = grosses.reduce((acc, g) => bcformat(bcadd(acc, g, scale), scale), zero);
  const normalisedDiscount = bcformat(discount, scale);

  if (bccomp(normalisedDiscount, '0') < 0) {
    throw new TransactionDiscountAllocationError(
      `transaction discount ${normalisedDiscount} is negative.`,
    );
  }
  if (bccomp(normalisedDiscount, totalGross) > 0) {
    throw new TransactionDiscountAllocationError(
      `transaction discount ${normalisedDiscount} exceeds the ticket gross ${totalGross}.`,
    );
  }

  const allocations = bccomp(normalisedDiscount, '0') === 0
    ? sorted.map(() => zero)
    : allocateLargestRemainder(grosses, totalGross, normalisedDiscount, scale, ulp);

  return sorted.map((group, index) => {
    const grossBeforeDiscount = grosses[index] ?? zero;
    const discountAllocated = allocations[index] ?? zero;
    const { discNet, discVat } = splitAllocatedDiscount(
      discountAllocated,
      group,
      scale,
    );

    const netAmount = bcformat(bcsub(group.lineNet, discNet, scale), scale);
    const vatAmount = bcformat(bcsub(group.lineVat, discVat, scale), scale);

    return {
      rate: group.rate,
      category: group.category,
      grossBeforeDiscount,
      discountAllocated,
      netAmount,
      vatAmount,
      grossAmount: bcformat(bcadd(netAmount, vatAmount, scale), scale),
    };
  });
}

/** `1` at the last representable digit of `scale` (`'0.001'` at TND scale 3). */
function ulpFor(scale: number): string {
  if (scale === 0) return '1';

  return `0.${'0'.repeat(scale - 1)}1`;
}

/**
 * Largest-remainder apportionment of `discount` over `grosses`.
 *
 * Floors every share at `scale`, then hands the residue out one ulp at a time
 * in descending-remainder order (ties broken by the already-canonical group
 * order), skipping any group that has no capacity left. `Σ result == discount`
 * exactly, and no share ever exceeds its own group gross.
 */
function allocateLargestRemainder(
  grosses: ReadonlyArray<string>,
  totalGross: string,
  discount: string,
  scale: number,
  ulp: string,
): string[] {
  if (bccomp(totalGross, '0') === 0) {
    throw new TransactionDiscountAllocationError(
      `transaction discount ${discount} cannot be ventilated over a zero-gross ticket.`,
    );
  }

  const ratioScale = scale + RATIO_EXTRA_SCALE;
  const shares: string[] = [];
  const remainders: Array<{ index: number; remainder: string }> = [];
  let allocated = bcformat('0', scale);

  for (const [index, gross] of grosses.entries()) {
    const exact = bcdiv(bcmul(discount, gross, ratioScale), totalGross, ratioScale);
    const floored = bctrunc(exact, scale);
    shares.push(floored);
    remainders.push({ index, remainder: bcsub(exact, floored, ratioScale) });
    allocated = bcformat(bcadd(allocated, floored, scale), scale);
  }

  remainders.sort((a, b) => {
    const cmp = bccomp(b.remainder, a.remainder);

    return cmp !== 0 ? cmp : a.index - b.index;
  });

  let residue = bcformat(bcsub(discount, allocated, scale), scale);
  // Two passes: descending remainder first (the canonical tie-break), then a
  // sweep over anything with capacity left. The second pass is only reachable
  // when a high-remainder group is already at its own gross ceiling.
  for (const pass of [remainders, remainders]) {
    for (const { index } of pass) {
      if (bccomp(residue, '0') <= 0) break;
      const gross = grosses[index] ?? '0';
      const current = shares[index] ?? '0';
      if (bccomp(current, gross) >= 0) continue;
      shares[index] = bcformat(bcadd(current, ulp, scale), scale);
      residue = bcformat(bcsub(residue, ulp, scale), scale);
    }
  }

  if (bccomp(residue, '0') !== 0) {
    throw new TransactionDiscountAllocationError(
      `transaction discount residue ${residue} could not be ventilated across the ticket.`,
    );
  }

  return shares;
}

/**
 * Split one group's allocated discount into its net and VAT halves, clamped
 * inside the group's own line sums.
 *
 * The half-up division is the natural split; the clamps are what make a full
 * comp land on exactly zero. `disc_r <= gross_r` is guaranteed by the caller,
 * so at most one clamp can bind and the pair always sums back to `disc_r`.
 */
function splitAllocatedDiscount(
  discountAllocated: string,
  group: VatGroupLineSums,
  scale: number,
): { discNet: string; discVat: string } {
  const zero = bcformat('0', scale);
  if (bccomp(discountAllocated, '0') === 0) {
    return { discNet: zero, discVat: zero };
  }

  const divisor = bcadd('1', bcdiv(group.rate, '100', scale + RATIO_EXTRA_SCALE), scale + RATIO_EXTRA_SCALE);
  let discNet = bcformat(bcdiv(discountAllocated, divisor, scale), scale);
  if (bccomp(discNet, group.lineNet) > 0) {
    discNet = bcformat(group.lineNet, scale);
  }
  let discVat = bcformat(bcsub(discountAllocated, discNet, scale), scale);
  if (bccomp(discVat, group.lineVat) > 0) {
    discVat = bcformat(group.lineVat, scale);
    discNet = bcformat(bcsub(discountAllocated, discVat, scale), scale);
  }

  return { discNet, discVat };
}
