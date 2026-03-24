import type { FullReceiptResponse } from '@/types/receipt';
import type { ReceiptData } from '@/lib/printing';

function getCurrencySymbol(currencyCode: string): string {
  try {
    const parts = new Intl.NumberFormat('en', {
      style: 'currency',
      currency: currencyCode,
    }).formatToParts(0);
    return parts.find((p) => p.type === 'currency')?.value ?? currencyCode;
  } catch {
    return currencyCode;
  }
}

function getCurrencyDecimals(currencyCode: string): number {
  try {
    const opts = new Intl.NumberFormat('en', {
      style: 'currency',
      currency: currencyCode,
    }).resolvedOptions();
    return opts.maximumFractionDigits ?? 2;
  } catch {
    return 2;
  }
}

/**
 * Transforms a full receipt API response into the ESC/POS ReceiptData
 * structure expected by the Tauri thermal printing backend.
 */
export function buildEscPosReceiptData(
  receipt: FullReceiptResponse,
): ReceiptData {
  const currencySymbol = getCurrencySymbol(receipt.currency);
  const decimals = getCurrencyDecimals(receipt.currency);
  const totalPayments = receipt.payments.reduce(
    (sum, p) => sum + parseFloat(p.amount),
    0,
  );
  const changeDue = Math.max(0, totalPayments - parseFloat(receipt.total));

  return {
    company: {
      name: receipt.company.name,
      address_line1: receipt.company.address_street ?? '',
      address_line2: receipt.company.address_street_2 ?? null,
      city: receipt.company.address_city ?? '',
      postal_code: receipt.company.address_postal_code ?? '',
      country: receipt.company.country_code,
      tax_id: receipt.company.tax_id ?? '',
      phone: receipt.company.phone ?? null,
    },
    receipt_number: receipt.receipt_number,
    date_time: receipt.posted_at,
    terminal_name: receipt.terminal.name,
    operator_name: receipt.cashier_name,
    lines: receipt.lines.map((line) => ({
      name: line.product_name,
      quantity: line.quantity,
      unit_price: line.unit_price,
      line_total: line.line_total,
      modifiers: line.modifiers,
      discount:
        parseFloat(line.discount_amount) > 0 ? line.discount_amount : null,
    })),
    subtotal: receipt.subtotal,
    discount_amount: receipt.discount_amount,
    tax_amount: receipt.tax_amount,
    total: receipt.total,
    currency_symbol: currencySymbol,
    vat_breakdown: receipt.vat_details.map((vat) => ({
      rate: vat.tax_rate,
      taxable: vat.net_amount,
      tax: vat.vat_amount,
    })),
    payments: receipt.payments.map((p) => ({
      method: p.payment_method.name,
      amount: p.amount,
    })),
    change_due: changeDue.toFixed(decimals),
    fiscal_hash: receipt.fiscal_hash,
    fiscal_signature: null,
    customer_name: receipt.customer_name,
    notes: receipt.notes,
  };
}
