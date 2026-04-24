/**
 * End-of-day preview builder.
 *
 * Computes a preview of the shift totals from local SQLite data WITHOUT
 * hashing, persisting, or modifying any state. This is a read-only
 * aggregation used to show the operator what they are about to close.
 */

import type Database from '@tauri-apps/plugin-sql';
import Big from 'big.js';
import { queryAll } from '@/lib/db';
import { getCurrencyDecimals } from '@/lib/currency';
import { bcadd } from '@/lib/decimal';
import { useAuthStore } from '@/stores/authStore';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

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
  payment_type: string;
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
}

/**
 * Build a read-only end-of-day preview from local SQLite receipts.
 * Does NOT hash, persist, or mutate any state.
 *
 * @param db - SQLite database connection
 * @param terminalId - Terminal UUID
 * @param shiftOpenedAt - ISO 8601 timestamp when the shift was opened
 * @param openingCash - Opening cash amount for the shift
 */
export async function buildEndOfDayPreview(
  db: Database,
  terminalId: string,
  shiftOpenedAt: string,
  openingCash: string,
): Promise<EndOfDayPreview> {
  const authState = useAuthStore.getState();
  const company = authState.companies.find((c) => c.id === authState.companyId);
  const decimals = getCurrencyDecimals(company?.currency ?? 'EUR');

  // 1. Fetch all receipts for this terminal since the shift opened
  const receipts = await queryAll<OfflineReceipt>(
    db,
    `SELECT * FROM offline_receipts
     WHERE terminal_id = $1 AND created_at >= $2
     ORDER BY hash_sequence ASC`,
    [terminalId, shiftOpenedAt],
  );

  // 2. Fetch payment method code lookup
  const methods = await queryAll<{ id: string; code: string }>(
    db,
    'SELECT id, code FROM payment_methods',
    [],
  );
  const methodMap = new Map<string, string>();
  for (const m of methods) {
    methodMap.set(m.id, m.code);
  }

  // 3. Aggregate
  let grossSales = 0;
  let netSales = 0;
  let taxAmount = 0;

  const vatByRate = new Map<string, { net: number; vat: number; gross: number }>();
  const paymentByType = new Map<string, { amount: number; count: number }>();

  for (const receipt of receipts) {
    grossSales += parseFloat(receipt.total);
    netSales += parseFloat(receipt.subtotal);
    taxAmount += parseFloat(receipt.tax_amount);

    const lines = JSON.parse(receipt.lines) as ReceiptLineJson[];
    for (const line of lines) {
      const rate = line.tax_rate ?? '0';
      const lineVat = parseFloat(line.tax_amount ?? '0');
      const lineNet = parseFloat(line.line_total ?? '0');
      const lineGross = lineNet + lineVat;

      const existing = vatByRate.get(rate) ?? { net: 0, vat: 0, gross: 0 };
      existing.net += lineNet;
      existing.vat += lineVat;
      existing.gross += lineGross;
      vatByRate.set(rate, existing);
    }

    const methodCode = methodMap.get(receipt.payment_method_id) ?? 'UNKNOWN';
    const payExisting = paymentByType.get(methodCode) ?? { amount: 0, count: 0 };
    payExisting.amount += parseFloat(receipt.total);
    payExisting.count += 1;
    paymentByType.set(methodCode, payExisting);
  }

  // 4. Compute expected cash = opening + all CASH sales (Big.js — no IEEE 754 drift)
  const cashEntry = paymentByType.get('CASH');
  const cashSales = cashEntry ? cashEntry.amount.toFixed(decimals) : new Big(0).toFixed(decimals);
  const expectedCash = bcadd(openingCash, cashSales, decimals);

  // 5. Build VAT breakdown sorted by rate
  const vatBreakdown: VatBreakdownItem[] = Array.from(vatByRate.entries())
    .sort(([a], [b]) => parseFloat(a) - parseFloat(b))
    .map(([rate, totals]) => ({
      tax_rate: parseFloat(rate),
      net_amount: totals.net.toFixed(decimals),
      vat_amount: totals.vat.toFixed(decimals),
      gross_amount: totals.gross.toFixed(decimals),
    }));

  // 6. Build payment methods breakdown
  const paymentMethods: PaymentMethodItem[] = Array.from(paymentByType.entries()).map(
    ([type, data]) => ({
      payment_type: type,
      total_amount: data.amount.toFixed(decimals),
      transaction_count: data.count,
    }),
  );

  return {
    sales_count: receipts.length,
    gross_sales: grossSales.toFixed(decimals),
    net_sales: netSales.toFixed(decimals),
    tax_amount: taxAmount.toFixed(decimals),
    opening_cash: new Big(openingCash).toFixed(decimals),
    expected_cash: new Big(expectedCash).toFixed(decimals),
    variance: null,
    vat_breakdown: vatBreakdown,
    payment_methods: paymentMethods,
  };
}
