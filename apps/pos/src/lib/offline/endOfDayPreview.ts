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
import { bcadd, bcsub, bcformat, bccomp } from '@/lib/decimal';
import { getCurrencyDecimals } from '@/lib/currency';
import { getCashDrawerOpsForShift } from '@/lib/db/repositories/cashDrawerRepository';
import { getAccountPaymentRecordsForShift } from '@/lib/db/repositories/localAccountPaymentRecordRepository';
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
}

interface PaymentJsonRow {
  // The writer (receiptService.ts) persists `method_code` and an `amount` that
  // is the cashier's PHYSICALLY TENDERED amount (backend contract:
  // CashCountToleranceVarianceRegressionTest.php). `change_due` is NOT on the
  // payment row — it is a receipt-level column (see OfflineReceiptRow).
  method_code: string;
  amount: string;
  tolerance_writeoff?: string;
}

interface ReceiptLineJson {
  tax_rate?: string;
  tax_amount?: string;
  line_total?: string;
}

export interface VatBreakdownItem {
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
  expected_cash: string;
  variance: string | null;
  vat_breakdown: VatBreakdownItem[];
  payment_methods: PaymentMethodItem[];
  tolerance_summary: {
    totalAmount: string;
    writeoffCount: number;
    currencyCode: string;
  } | null;
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

  // 1. Fetch all non-voided receipts for this terminal since the shift opened
  const receipts = await queryAll<OfflineReceiptRow>(
    db,
    `SELECT id, total, subtotal, tax_amount, payments_json, lines, created_at, change_due, payment_method_id
     FROM offline_receipts
     WHERE terminal_id = ? AND created_at >= ? AND voided = 0 AND is_training = 0
     ORDER BY created_at ASC`,
    [terminalId, shiftOpenedAt],
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
  let cashTenderedSum = '0';
  let cashChangeDueSum = '0';
  let toleranceTotal = '0';
  let toleranceCount = 0;

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
    grossSales = bcadd(grossSales, receipt.total);
    netSales = bcadd(netSales, receipt.subtotal);
    taxAmount = bcadd(taxAmount, receipt.tax_amount);

    // VAT breakdown from receipt lines
    const lines = JSON.parse(receipt.lines || '[]') as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const lineVat = line.tax_amount ?? '0';
      const lineNet = line.line_total ?? '0';
      const lineGross = bcadd(lineNet, lineVat);

      const existing = vatByRate.get(rate) ?? { net: '0', vat: '0', gross: '0' };
      existing.net = bcadd(existing.net, lineNet);
      existing.vat = bcadd(existing.vat, lineVat);
      existing.gross = bcadd(existing.gross, lineGross);
      vatByRate.set(rate, existing);
    }

    // Per-payment aggregation from payments_json
    const payments = JSON.parse(receipt.payments_json || '[]') as PaymentJsonRow[];
    let receiptHasCash = false;
    for (const p of payments) {
      const method = methodByCode.get(p.method_code);
      if (!method) continue;

      const key = method.code;
      const existing = perMethod.get(key) ?? {
        payment_method_id: method.id,
        payment_method_code: method.code,
        payment_method_name: method.name,
        is_physical: method.is_physical === 1,
        total_amount: '0',
        transaction_count: 0,
      };
      existing.total_amount = bcadd(existing.total_amount, p.amount);
      existing.transaction_count += 1;
      perMethod.set(key, existing);

      if (method.code === 'CASH') {
        cashTenderedSum = bcadd(cashTenderedSum, p.amount);
        receiptHasCash = true;
        const writeoff = p.tolerance_writeoff ?? '0';
        if (writeoff !== '' && bccomp(writeoff, '0') !== 0) {
          toleranceTotal = bcadd(toleranceTotal, writeoff);
          toleranceCount += 1;
        }
      }
    }

    if (payments.length > 0) {
      // change_due is a receipt-level column (offline_receipts.change_due) and is
      // only ever non-zero when the receipt was paid (over-tendered) in cash, so
      // subtract it once per cash-paid receipt — never per payment row.
      if (receiptHasCash) {
        cashChangeDueSum = bcadd(cashChangeDueSum, receipt.change_due ?? '0');
      }
    } else {
      // Legacy fallback (mirrors the signed Z): no usable payments_json →
      // attribute the whole sale to the primary method. receipt.total is ALREADY
      // net (drawer gains tendered − change = total), so do NOT subtract change.
      const method = paymentMethods.find((m) => m.id === receipt.payment_method_id);
      if (method) {
        const key = method.code;
        const existing = perMethod.get(key) ?? {
          payment_method_id: method.id,
          payment_method_code: method.code,
          payment_method_name: method.name,
          is_physical: method.is_physical === 1,
          total_amount: '0',
          transaction_count: 0,
        };
        existing.total_amount = bcadd(existing.total_amount, receipt.total);
        existing.transaction_count += 1;
        perMethod.set(key, existing);
        if (method.code === 'CASH') {
          cashTenderedSum = bcadd(cashTenderedSum, receipt.total);
        }
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
  let drawerNet = '0';
  let cashRefundImpact = '0';
  if (shiftId) {
    const drawerOps = await getCashDrawerOpsForShift(db, shiftId);
    for (const op of drawerOps) {
      drawerNet = op.type === 'deposit' ? bcadd(drawerNet, op.amount) : bcsub(drawerNet, op.amount);
    }
    // Cash collected against customer credit accounts is drawer cash.
    const accountPayments = await getAccountPaymentRecordsForShift(db, shiftId);
    for (const ap of accountPayments) {
      drawerNet = bcadd(drawerNet, ap.cash_impact);
    }
    // Cash-destination refunds physically left this drawer (matches the signed
    // Z's cashRefundImpact so the preview does not overstate expected cash).
    const refundRecords = await getRefundRecordsForShift(db, shiftId);
    for (const r of refundRecords) {
      cashRefundImpact = bcadd(cashRefundImpact, r.cash_impact);
    }
  }
  const expectedCash = bcsub(
    bcadd(bcsub(bcadd(openingCash, cashTenderedSum), cashChangeDueSum), drawerNet),
    cashRefundImpact,
  );

  // 5. Build VAT breakdown sorted by rate ascending
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
    sales_count: receipts.length,
    gross_sales: bcformat(grossSales, scale),
    net_sales: bcformat(netSales, scale),
    tax_amount: bcformat(taxAmount, scale),
    opening_cash: bcformat(openingCash, scale),
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
  };
}
