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
   * VAT reversed by refunds in the period, as a POSITIVE magnitude. Clamped at
   * zero: a negative wedge is not a refund, it is a corpus inconsistency, and
   * it is reported through {@link isReconciled} instead.
   */
  refundVat: string;
  /**
   * `Σ vat_breakdown[].vat_amount` — the signed, authoritative, net-of-refunds
   * figure, and the one that reconciles with the VAT declaration (§3.1).
   */
  netVat: string;
  /** Whether the three-line disclosure is worth rendering at all. */
  hasRefundVat: boolean;
  /** Whether `salesVat − refundVat == netVat` holds exactly at this scale. */
  isReconciled: boolean;
}

/** The only two fields the derivation reads. Structurally satisfied by
 *  `ZReportData`, `XReportResponse` and `EndOfDayPreview` alike. */
export interface VatDisclosureInput {
  tax_amount: string;
  vat_breakdown: ReadonlyArray<{ vat_amount?: string }>;
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

  const wedge = bcsub(salesVat, netVat, scale);
  const wedgeIsPositive = bccomp(wedge, '0') > 0;
  const refundVat = wedgeIsPositive ? bcformat(wedge, scale) : bcformat('0', scale);

  return {
    salesVat,
    refundVat,
    netVat,
    hasRefundVat: bccomp(refundVat, '0') !== 0,
    // A negative wedge (net table LARGER than the sale-only headline) is
    // impossible on a sound corpus, so it is surfaced rather than absorbed.
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
