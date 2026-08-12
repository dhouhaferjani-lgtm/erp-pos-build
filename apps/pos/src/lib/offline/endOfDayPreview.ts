/**
 * End-of-day preview builder.
 *
 * Computes a preview of the shift totals from local SQLite data WITHOUT
 * hashing, persisting, or modifying any state. This is a read-only
 * aggregation used to show the operator what they are about to close.
 *
 * Cash-tendered formula (contract v1.1):
 *   expected_cash = opening_float
 *                 + Σ(pos_receipt_payments.amount for CASH-method rows)
 *                 − Σ(pos_receipts.change_due for cash-paid receipts)
 *
 * `payments_json` on offline_receipts surfaces pos_receipt_payments.amount —
 * the amount the customer physically tendered, not receipt.total.
 */

import type Database from '@tauri-apps/plugin-sql';
import { queryAll } from '@/lib/db';
import { toSqliteUtc } from '@/lib/db/sqliteTime';
import { bcadd, bcsub, bcformat, bccomp } from '@/lib/decimal';
import { getCurrencyDecimals } from '@/lib/currency';
import { getCashDrawerOpsForShift } from '@/lib/db/repositories/cashDrawerRepository';
import { getAccountPaymentRecordsForShift } from '@/lib/db/repositories/localAccountPaymentRecordRepository';
import { getToleranceAutoAcceptCount } from '@/lib/db/repositories/toleranceAutoAcceptRepository';
import { getRefundRecordsForShift } from '@/lib/db/repositories/localRefundRecordRepository';

interface OfflineReceiptRow {
  id: string;
  total: string;
  subtotal: string;
  tax_amount: string;
  payments_json: string;
  lines: string;
  created_at: string;
  // Receipt-level change given back to the customer (offline_receipts.change_due,
  // written by receiptService.ts). Change is only ever given on cash tenders.
  change_due: string;
  // Primary payment method — used only for the legacy fallback when a receipt
  // has no usable payments_json (mirrors the signed-Z aggregation).
  payment_method_id: string;
  // Signed v3 cash-rounding adjustment, NULL on an unrounded receipt (Task 9).
  cash_rounding_adjustment: string | null;
  // Auto-accepted tender shortfall, NULL when none was applied (Task 9).
  tolerance_shortfall: string | null;
  // v3-refund-chain-integration spec §7.3a — 'sale' (default, absent on
  // rows predating this feature which the LEFT JOIN-free SELECT below
  // still reads as undefined) or 'refund'.
  receipt_kind?: 'sale' | 'refund';
}

interface PaymentJsonRow {
  // The writer (receiptService.ts) persists `method_code` and an `amount` that
  // is the cashier's PHYSICALLY TENDERED amount (backend contract:
  // CashCountToleranceVarianceRegressionTest.php). `change_due` is NOT on the
  // payment row — it is a receipt-level column (see OfflineReceiptRow).
  //
  // There is deliberately NO `tolerance_writeoff` here: the writer never
  // persisted one, so reading it always yielded zero. The write-off is a
  // receipt-level column (see OfflineReceiptRow.tolerance_shortfall).
  method_code: string;
  amount: string;
}

interface ReceiptLineJson {
  tax_rate?: string;
  tax_amount?: string;
  line_total?: string;
}

export interface VatBreakdownItem {
  /**
   * Tax rate percentage. Keep numeric to mirror the signed Z-report aggregate;
   * callers must format it only when rendering.
   */
  tax_rate: number;
  net_amount: string;
  vat_amount: string;
  gross_amount: string;
}

export interface PaymentMethodItem {
  payment_method_id: string;
  payment_method_code: string;
  payment_method_name: string;
  is_physical: boolean;
  total_amount: string;
  transaction_count: number;
}

export interface EndOfDayPreview {
  sales_count: number;
  gross_sales: string;
  net_sales: string;
  tax_amount: string;
  opening_cash: string;
  /** Cash receipts retained in the drawer, net of change and cash refunds. */
  cash_sales_net: string;
  /** Signed deposits, payouts, and cash account-payment movements. */
  drawer_movements_net: string;
  expected_cash: string;
  variance: string | null;
  vat_breakdown: VatBreakdownItem[];
  payment_methods: PaymentMethodItem[];
  /**
   * Receipt-derived tender-tolerance write-offs for this shift: what the till
   * reconciliation has to account for. Counts the shift's non-voided,
   * non-training receipts carrying a `tolerance_shortfall`.
   *
   * NOT the §8.1 budget figure — see {@link EndOfDayPreview.tolerance_auto_accept_count}.
   */
  tolerance_summary: {
    totalAmount: string;
    writeoffCount: number;
    currencyCode: string;
  } | null;
  /** Signed net cash-rounding for the shift. Null when nothing rounded. */
  cash_rounding_summary: { totalAdjustment: string; receiptCount: number } | null;
  /**
   * Auto-accepts already charged against this shift's §8.1 budget.
   *
   * Read from the DURABLE `tolerance_auto_accepts` row (SQLite migration v64) —
   * the very number `paymentStore` feeds the gate as
   * `autoAcceptCountThisShift`. Deliberately NOT derived from receipts: the two
   * counts diverge in both directions. A training-mode or later-voided
   * auto-accept spends budget but is excluded from the receipt query
   * (`is_training = 0`, `voided = 0`), and the budget write is best-effort
   * (paymentStore swallows a failed charge rather than failing an authored
   * sale). Only this row answers "how much headroom is left".
   *
   * NULL — meaning UNKNOWN, never "zero spent" — when the preview is built
   * without a shift id. There is no row to read, and the two honest readings
   * disagree: nothing was charged, yet the gate treats a null shift as the
   * budget FULLY SPENT and refuses the next short tender
   * (`paymentStore.ts:1030-1033`, the deliberate fail-closed inversion). Zero
   * would render as full headroom and contradict what the cashier is about to
   * experience; the limit would fabricate spend that never happened. The UI
   * must render null as unknown, never as available budget.
   */
  tolerance_auto_accept_count: number | null;
}

/**
 * Build a read-only end-of-day preview from local SQLite receipts.
 * Does NOT hash, persist, or mutate any state.
 *
 * @param db            - SQLite database connection
 * @param terminalId    - Terminal UUID
 * @param shiftOpenedAt - ISO 8601 timestamp when the shift was opened
 * @param openingCash   - Opening cash amount for the shift (decimal string)
 * @param currencyCode  - ISO 4217 currency code (e.g. 'EUR', 'TND'). When omitted,
 *                        falls back to the company currency from authStore.
 * @param shiftId       - Shift UUID. When provided, cash drawer deposits/payouts
 *                        for the shift are folded into expected_cash so the
 *                        preview mirrors the signed Z (NF525 drawer reality).
 */
export async function buildEndOfDayPreview(
  db: Database,
  terminalId: string,
  shiftOpenedAt: string,
  openingCash: string,
  currencyCode?: string,
  shiftId?: string,
): Promise<EndOfDayPreview> {
  // Resolve currency and scale
  let resolvedCurrency = currencyCode;
  if (!resolvedCurrency) {
    const { useAuthStore } = await import('@/stores/authStore');
    const authState = useAuthStore.getState();
    const company = authState.companies.find((c) => c.id === authState.companyId);
    resolvedCurrency = company?.currency ?? 'EUR';
  }
  const scale = getCurrencyDecimals(resolvedCurrency);

  // 1. Fetch all non-voided receipts for this terminal since the shift opened.
  // created_at is `datetime('now')` format (space separator, UTC); the ISO
  // shift timestamp must be normalized or the TEXT comparison excludes every
  // same-day receipt (' ' < 'T').
  const receipts = await queryAll<OfflineReceiptRow>(
    db,
    `SELECT id, total, subtotal, tax_amount, payments_json, lines, created_at, change_due, payment_method_id,
            cash_rounding_adjustment, tolerance_shortfall, receipt_kind
     FROM offline_receipts
     WHERE terminal_id = ? AND created_at >= ? AND voided = 0 AND is_training = 0
     ORDER BY created_at ASC`,
    [terminalId, toSqliteUtc(shiftOpenedAt)],
  );

  // 2. Fetch payment method lookup (id, code, name, is_physical)
  const paymentMethods = await queryAll<{ id: string; code: string; name: string; is_physical: number }>(
    db,
    `SELECT id, code, COALESCE(name, code) AS name, is_physical FROM payment_methods`,
  );
  const methodByCode = new Map(paymentMethods.map((m) => [m.code, m]));

  // 3. Aggregate
  let grossSales = '0';
  let netSales = '0';
  let taxAmount = '0';
  // Wave-2 fix-wave, adjacent to finding 4 (same defect class as finding 1's
  // X-report `sales_count: receipts.length`): `receipts` now contains v4
  // refund rows (§7.2), so a bare `receipts.length` reported every refund as
  // a SALE while the three sale aggregates right below deliberately skipped
  // it — an internally inconsistent preview.
  let salesCount = 0;
  let cashTenderedSum = '0';
  let cashChangeDueSum = '0';
  let toleranceTotal = '0';
  let toleranceCount = 0;
  let roundingTotal = '0';
  let roundingCount = 0;

  const vatByRate = new Map<string, { net: string; vat: string; gross: string }>();
  const perMethod = new Map<
    string,
    {
      payment_method_id: string;
      payment_method_code: string;
      payment_method_name: string;
      is_physical: boolean;
      total_amount: string;
      transaction_count: number;
    }
  >();

  for (const receipt of receipts) {
    const isRefund = receipt.receipt_kind === 'refund';

    // v3-refund-chain-integration spec §7.3a — grossSales/netSales/
    // taxAmount are SALE-ONLY, matching zReportService.ts's own §7.3
    // sale-only semantics: a refund row is skipped entirely for these
    // three totals (never added, never subtracted — refunds are their
    // own tracked figure below, never folded into gross/net sales).
    if (!isRefund) {
      salesCount += 1;
      grossSales = bcadd(grossSales, receipt.total);
      netSales = bcadd(netSales, receipt.subtotal);
      taxAmount = bcadd(taxAmount, receipt.tax_amount);
    }

    // Receipt-level rounding / tolerance columns (Task 9), written inside the
    // fiscal transaction from the sealed CheckoutPolicySnapshot. NULL means
    // "did not happen"; a stored zero would mean "happened and came to zero",
    // which is why both are compared rather than merely null-checked.
    // `tolerance_shortfall` is ALWAYS NULL on a refund row (§7.2a — no
    // tender-tolerance concept applies to a refund payout), so a refund
    // row never contributes to toleranceTotal at all -- the existing
    // null-check already excludes it, no branch needed.
    const shortfall = receipt.tolerance_shortfall;
    if (shortfall !== null && shortfall !== '' && bccomp(shortfall, '0') !== 0) {
      toleranceTotal = bcadd(toleranceTotal, shortfall);
      toleranceCount += 1;
    }
    // `cash_rounding_adjustment` is signed and mirrors the fiscal
    // payload's own value on EVERY row, sale or refund (§7.2a) -- a sale
    // row ADDS (unchanged); a refund row SUBTRACTS (a refund's own
    // rounding reverses the sale's). Canonical zero on every launch v4
    // refund (a refund payout never rounds), so this branch is currently
    // dead in practice but must be correct forward-compatibly.
    const adjustment = receipt.cash_rounding_adjustment;
    if (adjustment !== null && adjustment !== '' && bccomp(adjustment, '0') !== 0) {
      roundingTotal = isRefund
        ? bcsub(roundingTotal, adjustment)
        : bcadd(roundingTotal, adjustment);
      roundingCount += 1;
    }

    // VAT breakdown from receipt lines.
    //
    // Wave-2 review fix (finding 4 / codex C-3) — this loop USED to be
    // deliberately unbranched, on the stated assumption that a refund
    // row's negative-signed `lines` JSON made plain addition equivalent
    // to zReportService's explicit "bcabs-then-subtract" branch. The
    // equivalence held for the SIGN but not for the DECOMPOSITION: on a
    // refund row (as on a sale row) `line_total` is the GROSS/TTC line
    // amount with `tax_amount` EXTRACTED out of it, not the net. Adding
    // the VAT on top of it therefore booked net −12.00 / gross −14.00
    // for a 12.00-gross, 2.00-VAT refund instead of net −10.00 / gross
    // −12.00 — the same double-count the signed Z carried, additively.
    // The refund branch now derives net the only way it can be derived:
    // gross − vat, at the currency scale. The SALE branch is left
    // byte-identical (its own gross-as-net treatment predates this lane
    // and is not in this wave's scope — see the fix-wave report).
    const lines = JSON.parse(receipt.lines || '[]') as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };

      if (isRefund) {
        // Already negative-signed on the row (§7.2), so these stay
        // additive — only the net DECOMPOSITION changes.
        const lineGross = line.line_total ?? '0';
        const lineVat = line.tax_amount ?? '0';
        const lineNet = bcsub(lineGross, lineVat, scale);
        existing.net = bcadd(existing.net, lineNet, scale);
        existing.vat = bcadd(existing.vat, lineVat, scale);
        existing.gross = bcadd(existing.gross, lineGross, scale);
        vatByRate.set(rate, existing);
        continue;
      }

      const lineVat = line.tax_amount ?? '0';
      const lineNet = line.line_total ?? '0';
      const lineGross = bcadd(lineNet, lineVat);

      existing.net = bcadd(existing.net, lineNet);
      existing.vat = bcadd(existing.vat, lineVat);
      existing.gross = bcadd(existing.gross, lineGross);
      vatByRate.set(rate, existing);
    }

    // Per-payment aggregation from payments_json. Keyed on the RAW method_code
    // — exactly like the signed Z (zReportService aggregateReportData) — so a
    // tender is never dropped because the local payment_methods table no longer
    // lists its method (deactivated/removed by sync mid-shift). The lookup table
    // only supplies display metadata (id, name, is_physical).
    // `payments_json` is a POSITIVE magnitude on EVERY row, sale or refund
    // (§7.2a) — left unbranched here, a refund's positive amount would
    // simply ADD like a sale's, inflating expected cash by its own
    // magnitude instead of reducing it (the exact +2× error class §7.3
    // closed in zReportService.ts, reappearing here because this file's
    // loop was never touched until this fix).
    const payments = JSON.parse(receipt.payments_json || '[]') as PaymentJsonRow[];
    let receiptHasCash = false;
    for (const p of payments) {
      const method = methodByCode.get(p.method_code);

      const key = p.method_code;
      const existing = perMethod.get(key) ?? {
        payment_method_id: method?.id ?? '',
        payment_method_code: key,
        payment_method_name: method?.name ?? key,
        is_physical: method?.is_physical === 1,
        total_amount: '0',
        transaction_count: 0,
      };
      existing.total_amount = isRefund
        ? bcsub(existing.total_amount, p.amount)
        : bcadd(existing.total_amount, p.amount);
      existing.transaction_count += 1;
      perMethod.set(key, existing);

      if (key === 'CASH') {
        cashTenderedSum = isRefund
          ? bcsub(cashTenderedSum, p.amount)
          : bcadd(cashTenderedSum, p.amount);
        receiptHasCash = true;
      }
    }

    if (payments.length > 0) {
      // change_due is a receipt-level column (offline_receipts.change_due) and is
      // only ever non-zero when the receipt was paid (over-tendered) in cash, so
      // subtract it once per cash-paid receipt — never per payment row.
      // `change_due` is ALWAYS NULL on a refund row (§7.2a -- a refund has
      // no change concept), so this is a no-op for refund rows by
      // construction; not gated on `isRefund` for the same reason the
      // tolerance check above isn't.
      if (receiptHasCash) {
        cashChangeDueSum = bcadd(cashChangeDueSum, receipt.change_due ?? '0');
      }
    } else {
      // Legacy fallback (mirrors the signed Z): no usable payments_json →
      // attribute the whole sale to the primary method. receipt.total is ALREADY
      // net (drawer gains tendered − change = total), so do NOT subtract change.
      // An unresolvable primary method buckets under 'UNKNOWN' exactly like the
      // signed Z's paymentMethodMap miss — never silently dropped.
      const method = paymentMethods.find((m) => m.id === receipt.payment_method_id);
      const key = method?.code ?? 'UNKNOWN';
      const existing = perMethod.get(key) ?? {
        payment_method_id: method?.id ?? '',
        payment_method_code: key,
        payment_method_name: method?.name ?? key,
        is_physical: method?.is_physical === 1,
        total_amount: '0',
        transaction_count: 0,
      };
      existing.total_amount = bcadd(existing.total_amount, receipt.total);
      existing.transaction_count += 1;
      perMethod.set(key, existing);
      if (key === 'CASH') {
        cashTenderedSum = bcadd(cashTenderedSum, receipt.total);
      }
    }
  }

  // Net change into the CASH per-method figure (NF525 / DSFinV-K, 2026-06-11
  // research): the cash method total reflects net cash retained in the drawer,
  // not gross tendered. expected_cash already nets change below; keep the
  // per-method CASH line consistent (and identical to the signed Z).
  const cashEntry = perMethod.get('CASH');
  if (cashEntry) {
    cashEntry.total_amount = bcsub(cashEntry.total_amount, cashChangeDueSum);
  }

  // 3b. Seed all enabled physical methods that had no transactions
  for (const method of paymentMethods) {
    if (method.is_physical !== 1) continue;
    if (!perMethod.has(method.code)) {
      perMethod.set(method.code, {
        payment_method_id: method.id,
        payment_method_code: method.code,
        payment_method_name: method.name,
        is_physical: true,
        total_amount: '0',
        transaction_count: 0,
      });
    }
  }

  // 4. expected_cash = opening + net cash sales (Σ tendered − Σ change)
  //    + cash drawer deposits − payouts (NF525 drawer reality; mirrors the
  //    signed Z). deposit=+, payout=− per the device fetchDrawerBalance.
  // v3-refund-chain-integration spec §7.3a — `cashTenderedSum` is ALREADY
  // net of V4 refunds by construction of the receipt_kind branch above
  // (mirrors zReportService.ts's own §7.3 simplification exactly).
  //
  // Wave-2 review fix (TREASURY CRITICAL) — that is NOT true of LEGACY
  // refunds: they never touch `offline_receipts` at all (their only write
  // is `local_refund_records`, the legacy path's sole mechanism —
  // §9.3's coexistence ruling keeps this path live for every terminal
  // that has not yet completed its v4 capability rollout). Removing the
  // standalone cashRefundImpact term made this preview overstate expected
  // cash by the full amount of every legacy refund. Restored below,
  // disjoint from the v4 mechanism by construction (a legacy refund never
  // writes an `offline_receipts` `receipt_kind='refund'` row; a v4 refund
  // never writes `local_refund_records`) — a plain, non-overlapping sum.
  let drawerNet = '0';
  let cashRefundImpact = '0';
  // The §8.1 budget is keyed by shift id only — there is no timestamp bind, so
  // this read never touches the SQLite TEXT-boundary hazard that forces
  // toSqliteUtc() on the receipt window above. Stays NULL (unknown) without a
  // shift — see the field doc on EndOfDayPreview.
  let toleranceAutoAcceptCount: number | null = null;
  if (shiftId) {
    toleranceAutoAcceptCount = await getToleranceAutoAcceptCount(db, shiftId);
    const drawerOps = await getCashDrawerOpsForShift(db, shiftId);
    for (const op of drawerOps) {
      drawerNet = op.type === 'deposit' ? bcadd(drawerNet, op.amount, scale) : bcsub(drawerNet, op.amount, scale);
    }
    // Cash collected against customer credit accounts is drawer cash.
    const accountPayments = await getAccountPaymentRecordsForShift(db, shiftId);
    for (const ap of accountPayments) {
      drawerNet = bcadd(drawerNet, ap.cash_impact, scale);
    }
    // Cash-destination LEGACY refunds physically left this drawer (matches
    // the signed Z's own restored cashRefundImpact term so the preview
    // does not overstate expected cash for a non-acknowledged terminal).
    const refundRecords = await getRefundRecordsForShift(db, shiftId);
    for (const r of refundRecords) {
      cashRefundImpact = bcadd(cashRefundImpact, r.cash_impact, scale);
    }
  }
  const cashSalesNet = bcsub(
    bcsub(cashTenderedSum, cashChangeDueSum, scale),
    cashRefundImpact,
    scale,
  );
  const expectedCash = bcadd(
    bcadd(openingCash, cashSalesNet, scale),
    drawerNet,
    scale,
  );

  // 5. Build VAT breakdown sorted by rate ascending. tax_rate intentionally
  // remains a number to match the signed Z-report aggregate/wire shape; trim or
  // round only at display boundaries.
  const vatBreakdown: VatBreakdownItem[] = Array.from(vatByRate.entries())
    .sort(([a], [b]) => parseFloat(a) - parseFloat(b))
    .map(([rate, totals]) => ({
      tax_rate: parseFloat(rate),
      net_amount: bcformat(totals.net, scale),
      vat_amount: bcformat(totals.vat, scale),
      gross_amount: bcformat(totals.gross, scale),
    }));

  // 6. Build payment methods breakdown
  const paymentMethodsResult: PaymentMethodItem[] = Array.from(perMethod.values()).map((m) => ({
    ...m,
    total_amount: bcformat(m.total_amount, scale),
  }));

  return {
    sales_count: salesCount,
    gross_sales: bcformat(grossSales, scale),
    net_sales: bcformat(netSales, scale),
    tax_amount: bcformat(taxAmount, scale),
    opening_cash: bcformat(openingCash, scale),
    cash_sales_net: bcformat(cashSalesNet, scale),
    drawer_movements_net: bcformat(drawerNet, scale),
    expected_cash: bcformat(expectedCash, scale),
    variance: null,
    vat_breakdown: vatBreakdown,
    payment_methods: paymentMethodsResult,
    tolerance_summary:
      toleranceCount > 0
        ? {
            totalAmount: bcformat(toleranceTotal, scale),
            writeoffCount: toleranceCount,
            currencyCode: resolvedCurrency,
          }
        : null,
    cash_rounding_summary:
      roundingCount > 0
        ? { totalAdjustment: bcformat(roundingTotal, scale), receiptCount: roundingCount }
        : null,
    tolerance_auto_accept_count: toleranceAutoAcceptCount,
  };
}
