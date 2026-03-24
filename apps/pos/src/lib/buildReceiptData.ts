import i18next from 'i18next';
import type { FullReceiptResponse } from '@/types/receipt';
import type { ReceiptData, ReceiptLabels } from '@/lib/printing';

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

/** Receipt visibility settings matching company receipt configuration. */
export interface ReceiptVisibilitySettings {
  show_vat_breakdown?: boolean;
  show_fiscal_info?: boolean;
  show_payment_details?: boolean;
  show_customer?: boolean;
}

/**
 * Transforms a full receipt API response into the ESC/POS ReceiptData
 * structure expected by the Tauri thermal printing backend.
 *
 * @param receipt Full receipt response from the API
 * @param visibilitySettings Optional visibility flags from company receipt settings
 */
export function buildEscPosReceiptData(
  receipt: FullReceiptResponse,
  visibilitySettings?: ReceiptVisibilitySettings,
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
    labels: buildReceiptLabels(),
    show_vat_breakdown: visibilitySettings?.show_vat_breakdown,
    show_fiscal_info: visibilitySettings?.show_fiscal_info,
    show_payment_details: visibilitySettings?.show_payment_details,
    show_customer: visibilitySettings?.show_customer,
  };
}

/** Build localized receipt labels from i18n. */
function buildReceiptLabels(): ReceiptLabels {
  const t = (key: string) => i18next.t(`pos:receiptLabel.${key}`);
  return {
    receipt: t('receipt'),
    date: t('date'),
    terminal: t('terminal'),
    operator: t('operator'),
    customer: t('customer'),
    item: t('item'),
    qty: t('qty'),
    amount: t('amount'),
    subtotal: t('subtotal'),
    discount: t('discount'),
    tax: t('tax'),
    total: t('total'),
    payments: t('payments'),
    change_due: t('changeDue'),
    vat_rate: t('vatRate'),
    taxable: t('taxable'),
    tax_col: t('taxCol'),
    thank_you: t('thankYou'),
    tax_id: t('taxId'),
    tel: t('tel'),
  };
}
