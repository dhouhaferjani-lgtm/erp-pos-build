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

interface OfflineReceiptRow {
  id: string;
  total: string;
  subtotal: string;
  tax_amount: string;
  payments_json: string;
  lines: string;
  created_at: string;
}

interface PaymentJsonRow {
  payment_method_code: string;
  amount: string;
  change_due?: string;
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
    total_amount: string;
    writeoff_count: number;
    currency_code: string;
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
 */
export async function buildEndOfDayPreview(
  db: Database,
  terminalId: string,
  shiftOpenedAt: string,
  openingCash: string,
  currencyCode?: string,
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
    `SELECT id, total, subtotal, tax_amount, payments_json, lines, created_at
     FROM offline_receipts
     WHERE terminal_id = ? AND created_at >= ? AND voided = 0
     ORDER BY created_at ASC`,
    [terminalId, shiftOpenedAt],
  );

  // 2. Fetch payment method lookup (id, code, is_physical)
  const paymentMethods = await queryAll<{ id: string; code: string; is_physical: number }>(
    db,
    `SELECT id, code, is_physical FROM payment_methods`,
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
    for (const p of payments) {
      const method = methodByCode.get(p.payment_method_code);
      if (!method) continue;

      const key = method.code;
      const existing = perMethod.get(key) ?? {
        payment_method_id: method.id,
        payment_method_code: method.code,
        is_physical: method.is_physical === 1,
        total_amount: '0',
        transaction_count: 0,
      };
      existing.total_amount = bcadd(existing.total_amount, p.amount);
      existing.transaction_count += 1;
      perMethod.set(key, existing);

      if (method.code === 'CASH') {
        cashTenderedSum = bcadd(cashTenderedSum, p.amount);
        cashChangeDueSum = bcadd(cashChangeDueSum, p.change_due ?? '0');
        const writeoff = p.tolerance_writeoff ?? '0';
        if (writeoff !== '' && bccomp(writeoff, '0') !== 0) {
          toleranceTotal = bcadd(toleranceTotal, writeoff);
          toleranceCount += 1;
        }
      }
    }
  }

  // 4. expected_cash = opening + Σ(CASH tendered) − Σ(change_due)
  const expectedCash = bcsub(bcadd(openingCash, cashTenderedSum), cashChangeDueSum);

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
            total_amount: bcformat(toleranceTotal, scale),
            writeoff_count: toleranceCount,
            currency_code: resolvedCurrency,
          }
        : null,
  };
}
