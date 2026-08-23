import { bcadd, bccomp, bcformat, bcsub } from '@/lib/decimal';

/**
 * B-6(ii) / Option A1 — the three VAT figures every X / Z / EOD surface must
 * show together, derived from SIGNED/STORED data only.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────
 * A report from a shift that took a return carries two VAT numbers that
 * disagree by exactly the refund VAT:
 *   - `tax_amount` is SALE-ONLY (the refund branch `continue`s before it —
 *     `zReportService.ts:960`, `reportApi.ts:583`, `endOfDayPreview.ts:260`),
 *   - `vat_breakdown[].vat_amount` is NET (the refund branch SUBTRACTS from it
 *     — `zReportService.ts:872-874`, `reportApi.ts:541-543`,
 *     `endOfDayPreview.ts:317-327`).
 * Printing the first beside a table of the second, with nothing disclosing the
 * bridge, is the internal contradiction the ruling closes. It is also why
 * `FiscalPayloadConstraintValidator::validateZFamilyVatBreakdownConsistency()`
 * must STOP asserting `Σ vat_amount == tax_amount` once
 * `refunds_totals.count != 0`. That gate stays exactly as gated; this removes
 * the contradiction it hides, not the gate.
 *
 * ── WHY DERIVED, NOT ACCUMULATED ──────────────────────────────────────────
 * The obvious alternative — a fourth accumulator in the three refund branches —
 * cannot work on this device. `aggregateReportData()`'s return value IS the
 * object `computeZReportHash()` hashes and `local_z_reports.report_data`
 * persists (`zReportService.ts:445-450`, `:504`), so a new key on it would
 * change the LEGACY Z fiscal hash for every future Z and break the parity that
 * `zReportHashService.legacyStability.test.ts` pins. An accumulator kept OUT of
 * that object would not survive persistence either, so the modals — which read
 * a re-hydrated `report_data` — could never show it.
 *
 * Deriving from `tax_amount` and `vat_breakdown` needs neither: those two
 * fields are already signed, already persisted, and their difference is
 * algebraically the refund VAT, since both loops walk the same lines:
 *
 *     Σ vat_breakdown[].vat_amount  ==  Σ sale-line VAT − Σ refund-line VAT
 *     tax_amount                    ==  Σ sale-line VAT
 *     ⇒ tax_amount − Σ vat_breakdown[].vat_amount == Σ refund-line VAT
 *
 * The second identity is the one the F-4 tripwire enforces EXACTLY on every
 * refund-free shift, so the derivation is exact wherever the corpus is sound —
 * and where it is not, {@link VatDisclosure.isReconciled} says so out loud
 * rather than printing three numbers that do not add up.
 *
 * NOTHING here is signed, hashed, persisted, or fed to a payload builder.
 */
export interface VatDisclosure {
  /** The SALE-ONLY headline (`tax_amount`). Never a VAT-declaration input. */
  salesVat: string;
  /**
   * VAT reversed by refunds in the period, as a POSITIVE magnitude.
   *
   * Zero in the anomaly case (net table LARGER than the sale-only headline) —
   * a negative wedge is not a refund. That state is still reported, through
   * {@link isReconciled}: the clamp makes `salesVat − refundVat != netVat`, so
   * the flag goes false and the consumer renders the warning.
   */
  refundVat: string;
  /**
   * `Σ vat_breakdown[].vat_amount` — the signed, authoritative, net-of-refunds
   * figure, and the one that reconciles with the VAT declaration (§3.1).
   */
  netVat: string;
  /** Whether there is a refund magnitude worth putting on screen. */
  hasRefundVat: boolean;
  /**
   * Whether `salesVat − refundVat == netVat` holds exactly at this scale.
   *
   * It reads the RAW WEDGE'S SIGN in the fallback path, and a SECOND
   * INDEPENDENT SOURCE when one is supplied:
   *
   * - **Wedge path** (every signed X/Z surface — they have no second source and
   *   cannot grow one). `refundVat` is the clamped wedge, so this reduces to
   *   "the wedge is not negative", and `!isReconciled ⟹ !hasRefundVat` still
   *   holds here. That implication is fine; what mattered was that the CONSUMER
   *   stopped early-returning on `!hasRefundVat` alone — see
   *   {@link ../../components/pos/VatDisclosureSummary}.
   * - **Accumulator path** ({@link VatDisclosureInput.refund_vat_amount}, EOD
   *   only). Here the two flags are genuinely independent and this is a real
   *   cross-check of two separately-derived figures.
   *
   * Gate r1 F-1/B-1 context: the unreconciled branch used to be unreachable on
   * every device surface, so the anomaly it exists to disclose was silently
   * absorbed and four shipped i18n values were dead. Gate r2-2 corrected this
   * docblock, which overstated the fix as a changed derivation.
   */
  isReconciled: boolean;
}

/**
 * What the derivation reads. Structurally satisfied by `ZReportData`,
 * `XReportResponse` and `EndOfDayPreview` alike.
 */
export interface VatDisclosureInput {
  tax_amount: string;
  vat_breakdown: ReadonlyArray<{ vat_amount: string }>;
  /**
   * OPTIONAL authoritative refund-VAT magnitude, when the caller has one that
   * was accumulated independently of the per-rate table.
   *
   * Only the EOD preview has this: it is unsigned/unhashed, so it can afford a
   * real `bcabs`-then-add accumulator (`endOfDayPreview.ts`), which is era-safe
   * where the wedge is not. The signed Z/X surfaces have no such field and
   * cannot grow one (`report_data` IS the legacy Z hash input), so they fall
   * back to the wedge.
   *
   * Supplying it turns `isReconciled` into a genuine cross-check between two
   * INDEPENDENT sources rather than a tautology — which is the only way the
   * device can legitimately reach `hasRefundVat && !isReconciled`.
   *
   * `'0'` is authoritative, not absent: a shift the accumulator says had no
   * refund VAT, beside a table that disagrees, is a real inconsistency.
   */
  refund_vat_amount?: string;
}

/**
 * @param scale The CURRENCY scale (EUR 2, TND 3). Passed explicitly on every
 *   bc* call — `decimal.ts` defaults to 3, which would leave sub-cent residue
 *   on a scale-2 currency (rule 19).
 */
export function deriveVatDisclosure(input: VatDisclosureInput, scale: number): VatDisclosure {
  const salesVat = bcformat(numericOrZero(input.tax_amount), scale);

  let netVat = bcformat('0', scale);
  for (const row of input.vat_breakdown) {
    netVat = bcadd(netVat, numericOrZero(row.vat_amount), scale);
  }
  netVat = bcformat(netVat, scale);

  // The RAW, SIGNED wedge. Its SIGN — not just its magnitude — decides the
  // fallback `refundVat` below, so a negative wedge clamps to zero and then
  // FAILS the `isReconciled` equality rather than being absorbed into a
  // plausible-looking refund figure (gate r1 F-1).
  //
  // Note (gate r2-2): `isReconciled` itself is computed from the clamped
  // `refundVat`, not from this value directly. In the fallback path that is
  // equivalent to testing `wedge >= 0`; the accumulator path is where the
  // comparison becomes a genuine two-source cross-check.
  const wedge = bcsub(salesVat, netVat, scale);

  const hasAuthoritativeAccumulator = typeof input.refund_vat_amount === 'string';
  const refundVat = hasAuthoritativeAccumulator
    ? bcformat(numericOrZero(input.refund_vat_amount), scale)
    : bccomp(wedge, '0') > 0
      ? bcformat(wedge, scale)
      : bcformat('0', scale);

  return {
    salesVat,
    refundVat,
    netVat,
    hasRefundVat: bccomp(refundVat, '0') !== 0,
    // With an authoritative accumulator this is a real comparison of two
    // independent figures. Without one it reduces to "the wedge is not
    // negative" — a net table LARGER than the sale-only headline is impossible
    // on a sound corpus, so it is surfaced rather than absorbed.
    isReconciled: bccomp(bcsub(salesVat, refundVat, scale), netVat) === 0,
  };
}

/** Decimal string, optionally signed. Deliberately NOT `Number()`/`parseFloat`:
 *  no float may touch money, not even to validate it (rule 19). */
const DECIMAL_STRING = /^-?\d+(\.\d+)?$/;

/**
 * A missing or malformed amount reads as '0' rather than throwing: this feeds a
 * fiscal DISPLAY, and a Z that a cashier cannot close is worse than a Z with a
 * zero line. The zero is visible in the arithmetic, not swallowed.
 */
function numericOrZero(value: string | undefined): string {
  if (typeof value !== 'string' || !DECIMAL_STRING.test(value.trim())) {
    return '0';
  }
  return value.trim();
}
