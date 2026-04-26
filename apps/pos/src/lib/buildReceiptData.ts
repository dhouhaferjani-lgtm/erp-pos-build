import i18next from 'i18next';
import type { FullReceiptResponse } from '@/types/receipt';
import type { ReceiptData, ReceiptLabels } from '@/lib/printing';
import type { CartItem } from '@/types/cart';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';
import { bcadd, bcsub, bccomp, bcformat } from '@/lib/decimal';
import { getCurrencyDecimals } from '@/lib/currency';

function formatReceiptDateTime(date: Date, locale: string): string {
  try {
    return new Intl.DateTimeFormat(locale, {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    }).format(date);
  } catch {
    // Invalid locale — fall back to en-GB (day-month-year), safer default for EU tenants.
    return new Intl.DateTimeFormat('en-GB', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
    }).format(date);
  }
}

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
  // Compute has_tolerance on the TS side using arbitrary-precision decimal.
  // The Rust receipt formatter reads this flag directly and never parses
  // monetary strings (recurring lesson: parseFloat on monetary values is a
  // smell, even when the parsed value is only compared to zero today).
  const toleranceWriteoff = receipt.tolerance_writeoff;
  const hasTolerance =
    toleranceWriteoff !== null
    && toleranceWriteoff !== ''
    && bccomp(toleranceWriteoff, '0') > 0;

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
      unit_price: bcformat(line.unit_price, decimals),
      line_total: bcformat(line.line_total, decimals),
      modifiers: line.modifiers,
      discount:
        bccomp(line.discount_amount, '0') > 0
          ? bcformat(line.discount_amount, decimals)
          : null,
    })),
    subtotal: bcformat(receipt.subtotal, decimals),
    discount_amount: bcformat(receipt.discount_amount, decimals),
    tax_amount: bcformat(receipt.tax_amount, decimals),
    total: bcformat(receipt.total, decimals),
    currency_symbol: currencySymbol,
    vat_breakdown: receipt.vat_details.map((vat) => ({
      rate: vat.tax_rate,
      taxable: bcformat(vat.net_amount, decimals),
      tax: bcformat(vat.vat_amount, decimals),
    })),
    payments: receipt.payments.map((p) => ({
      method: p.payment_method.name,
      amount: bcformat(p.amount, decimals),
    })),
    change_due: changeDue,
    tolerance_writeoff: toleranceWriteoff !== null && toleranceWriteoff !== ''
      ? bcformat(toleranceWriteoff, decimals)
      : null,
    has_tolerance: hasTolerance,
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
  locale: string = 'en',
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
    date_time: formatReceiptDateTime(new Date(), locale),
    terminal_name: terminalName,
    operator_name: operatorName,
    lines: cartItems.map((item) => ({
      name: item.product.name,
      quantity: String(item.quantity),
      unit_price: bcformat(item.unit_price, decimals),
      line_total: bcformat(item.line_total, decimals),
      modifiers: item.product.selectedModifiers?.map((m) => ({
        name: m.name,
        price: bcformat(m.price_adjustment, decimals),
      })) ?? null,
      discount: item.discount_amount && bccomp(item.discount_amount, '0') > 0
        ? bcformat(item.discount_amount, decimals)
        : null,
    })),
    subtotal: bcformat(result.subtotal, decimals),
    discount_amount: bcformat(result.discountAmount, decimals),
    tax_amount: bcformat(result.taxAmount, decimals),
    total: bcformat(result.total, decimals),
    currency_symbol: currencySymbol,
    vat_breakdown: Array.from(vatByRate.entries())
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([rate, { taxable, tax }]) => ({
        rate,
        taxable: bcformat(taxable, decimals),
        tax: bcformat(tax, decimals),
      })),
    payments: [{
      method: paymentMethodName,
      amount: bcformat(result.total, decimals),
    }],
    change_due: bcformat(result.changeDue, decimals),
    tolerance_writeoff: null,
    has_tolerance: false,
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
export function buildReceiptLabels(): ReceiptLabels {
  const t = (key: string) => i18next.t(`pos:receiptLabel.${key}`);
  const cc = (key: string) => i18next.t(`pos:cash_count.${key}`);
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
    rounding: t('rounding'),
    vat_rate: t('vatRate'),
    taxable: t('taxable'),
    tax_col: t('taxCol'),
    thank_you: t('thankYou'),
    tax_id: t('taxId'),
    tel: t('tel'),
    cash_count_section_title: cc('section_title'),
    cash_count_total_variance: cc('table.variance'),
    cash_count_approved_by: i18next.t('pos:cash_count.manager_pin.verified', { name: '' }).trimEnd(),
    cash_count_reason: cc('reason_label'),
    cash_count_col_tender: cc('table.tender'),
    cash_count_col_expected: cc('table.expected'),
    cash_count_col_actual: cc('table.actual'),
    cash_count_col_variance: cc('table.variance'),
  };
}
