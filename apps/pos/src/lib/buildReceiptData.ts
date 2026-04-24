import i18next from 'i18next';
import type { FullReceiptResponse } from '@/types/receipt';
import type { ReceiptData, ReceiptLabels } from '@/lib/printing';
import type { CartItem } from '@/types/cart';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';
import { bcadd, bcsub, bccomp } from '@/lib/decimal';

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
  isReprint?: boolean,
): ReceiptData {
  const currencySymbol = getCurrencySymbol(receipt.currency);
  const decimals = getCurrencyDecimals(receipt.currency);
  const totalPayments = receipt.payments.reduce(
    (sum, p) => bcadd(sum, p.amount, decimals),
    '0',
  );
  const changeDue = bccomp(totalPayments, receipt.total) > 0
    ? bcsub(totalPayments, receipt.total, decimals)
    : (0).toFixed(decimals);

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
    change_due: changeDue,
    fiscal_hash: receipt.fiscal_hash,
    fiscal_signature: null,
    customer_name: receipt.customer_name,
    notes: receipt.notes,
    labels: buildReceiptLabels(),
    show_vat_breakdown: visibilitySettings?.show_vat_breakdown,
    show_fiscal_info: visibilitySettings?.show_fiscal_info,
    show_payment_details: visibilitySettings?.show_payment_details,
    show_customer: visibilitySettings?.show_customer,
    is_reprint: isReprint ?? undefined,
  };
}

/**
 * Builds ESC/POS receipt data from local cart data when the receipt was
 * created offline and the server cannot be reached for the full receipt.
 */
export function buildEscPosFromOfflineReceipt(
  result: CheckoutResult,
  cartItems: CartItem[],
  companyName: string,
  terminalName: string,
  operatorName: string,
  paymentMethodName: string,
  visibilitySettings?: ReceiptVisibilitySettings,
): ReceiptData {
  const currencySymbol = getCurrencySymbol(result.currency);
  const decimals = getCurrencyDecimals(result.currency);

  // Build VAT breakdown from cart items
  const vatByRate = new Map<string, { taxable: number; tax: number }>();
  for (const item of cartItems) {
    const rate = item.tax_rate;
    const tax = parseFloat(item.tax_amount);
    if (tax === 0) continue;
    const lineTotal = parseFloat(item.line_total);
    const taxable = lineTotal - tax;
    const existing = vatByRate.get(rate) ?? { taxable: 0, tax: 0 };
    vatByRate.set(rate, {
      taxable: existing.taxable + taxable,
      tax: existing.tax + tax,
    });
  }

  return {
    company: {
      name: companyName,
      address_line1: '',
      address_line2: null,
      city: '',
      postal_code: '',
      country: '',
      tax_id: '',
      phone: null,
    },
    receipt_number: result.receiptNumber,
    date_time: new Date().toISOString(),
    terminal_name: terminalName,
    operator_name: operatorName,
    lines: cartItems.map((item) => ({
      name: item.product.name,
      quantity: String(item.quantity),
      unit_price: item.unit_price,
      line_total: item.line_total,
      modifiers: item.product.selectedModifiers?.map((m) => ({
        name: m.name,
        price: m.price_adjustment,
      })) ?? null,
      discount: item.discount_amount && parseFloat(item.discount_amount) > 0
        ? item.discount_amount
        : null,
    })),
    subtotal: result.subtotal,
    discount_amount: result.discountAmount,
    tax_amount: result.taxAmount,
    total: result.total,
    currency_symbol: currencySymbol,
    vat_breakdown: Array.from(vatByRate.entries())
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([rate, { taxable, tax }]) => ({
        rate,
        taxable: taxable.toFixed(decimals),
        tax: tax.toFixed(decimals),
      })),
    payments: [{
      method: paymentMethodName,
      amount: result.total,
    }],
    change_due: result.changeDue.toFixed(decimals),
    fiscal_hash: result.fiscalHash ?? null,
    fiscal_signature: null,
    customer_name: null,
    notes: null,
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
