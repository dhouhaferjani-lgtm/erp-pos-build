import i18next from 'i18next';
import type { FullReceiptResponse } from '@/types/receipt';
import type {
  ReceiptData,
  ReceiptLabels,
  VoucherTicketData,
  VoucherTicketLabels,
} from '@/lib/printing';
import type { CartItem } from '@/types/cart';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';
import { bcadd, bcsub, bccomp, bcformat } from '@/lib/decimal';
import { getCurrencyDecimals } from '@/lib/currency';
import type { AccountPaymentPayload } from '@/lib/fiscal/payloads/AccountPaymentPayload';

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
 * Optional refund-specific extras printed on REMBOURSEMENT/REFUND receipts.
 * The fields are passed straight through to the Rust formatter, which gates
 * the AVOIR header + original-ticket reference block on `receipt_kind`.
 *
 * `qrToken` (when supplied) overrides any inference from the API response;
 * callers typically look it up via `findReceiptByQrToken` /
 * `findReceiptByNumber` in voucherRepository.ts before calling here.
 */
export interface ReceiptExtras {
  /** Receipt's own signed QR token (`v:kid:receipt_uuid:mac`). Null = no QR section. */
  qrToken?: string | null;
  /** Override the receipt-kind discriminator. Defaults to inferring from `receipt.receipt_type`. */
  receiptKind?: 'sale' | 'refund';
  /** Original (sale) receipt number — only meaningful on refund receipts. */
  originalReceiptNumber?: string | null;
  /** Original (sale) receipt's QR token — printed for further partial refunds. */
  originalReceiptQrToken?: string | null;
}

/**
 * Transforms a full receipt API response into the ESC/POS ReceiptData
 * structure expected by the Tauri thermal printing backend.
 *
 * @param receipt Full receipt response from the API
 * @param visibilitySettings Optional visibility flags from company receipt settings
 * @param isReprint True if this is a duplicata of an already-issued receipt
 * @param extras Optional QR token + refund cross-references
 */
export function buildEscPosReceiptData(
  receipt: FullReceiptResponse,
  visibilitySettings?: ReceiptVisibilitySettings,
  isReprint?: boolean,
  extras?: ReceiptExtras,
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

  // Receipt-kind discriminator. Explicit override wins; otherwise infer from
  // receipt_type ('return' = refund, anything else = sale).
  const receiptKind: 'sale' | 'refund' =
    extras?.receiptKind ?? (receipt.receipt_type === 'return' ? 'refund' : 'sale');

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
    qr_token: extras?.qrToken ?? null,
    receipt_kind: receiptKind,
    original_receipt_number: extras?.originalReceiptNumber ?? null,
    original_receipt_qr_token: extras?.originalReceiptQrToken ?? null,
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
  extras?: ReceiptExtras,
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
    qr_token: extras?.qrToken ?? null,
    receipt_kind: extras?.receiptKind ?? 'sale',
    original_receipt_number: extras?.originalReceiptNumber ?? null,
    original_receipt_qr_token: extras?.originalReceiptQrToken ?? null,
  };
}

export interface BuildAccountPaymentReceiptDataInput {
  payload: AccountPaymentPayload;
  fiscalEventId: string;
  fiscalHash: string;
  terminalName: string;
}

export function buildEscPosAccountPaymentReceiptData(
  input: BuildAccountPaymentReceiptDataInput,
): ReceiptData {
  const { payload } = input;
  const currencySymbol = getCurrencySymbol(payload.currency_code);
  const scale = payload.currency_scale;

  return {
    company: {
      name: payload.seller.name,
      address_line1: payload.seller.address.street,
      address_line2: null,
      city: payload.seller.address.city,
      postal_code: payload.seller.address.postal_code,
      country: payload.seller.address.country_code,
      tax_id: payload.seller.tax_number,
      phone: null,
    },
    receipt_number: payload.account_payment_uuid,
    date_time: payload.event_time_device,
    terminal_name: input.terminalName,
    operator_name: payload.cashier_name,
    lines: [],
    subtotal: bcformat('0', scale),
    discount_amount: bcformat('0', scale),
    tax_amount: bcformat('0', scale),
    total: bcformat(payload.payment.amount, scale),
    currency_symbol: currencySymbol,
    vat_breakdown: [],
    payments: [{
      method: payload.payment.method_code,
      amount: bcformat(payload.payment.amount, scale),
    }],
    change_due: bcformat('0', scale),
    tolerance_writeoff: null,
    has_tolerance: false,
    fiscal_hash: input.fiscalHash,
    fiscal_signature: input.fiscalEventId,
    customer_name: payload.customer.name,
    notes: payload.notes,
    labels: buildReceiptLabels(),
    show_vat_breakdown: false,
    show_fiscal_info: true,
    show_payment_details: true,
    show_customer: true,
    receipt_kind: 'account_payment',
    original_receipt_number: null,
    original_receipt_qr_token: null,
    account_balance_before: bcformat(payload.local_balance_snapshot.net_balance_before, scale),
    account_balance_after: bcformat(payload.local_balance_snapshot.projected_net_balance_after, scale),
    account_snapshot_stale:
      payload.staleness.customer_snapshot_stale || payload.staleness.balance_snapshot_stale,
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
    refund_header: t('refundHeader'),
    original_ticket: t('originalTicket'),
    original_qr_label: t('originalQrLabel'),
    account_payment_header: t('accountPaymentHeader'),
    balance_before: t('balanceBefore'),
    balance_after: t('balanceAfter'),
    stale_balance: t('staleBalance'),
  };
}

/** Build localized voucher-ticket labels from i18n. */
export function buildVoucherTicketLabels(): VoucherTicketLabels {
  const t = (key: string) => i18next.t(`pos:receiptLabel.voucherTicket.${key}`);
  return {
    header: t('header'),
    code: t('code'),
    balance: t('balance'),
    expires: t('expires'),
    no_expiry: t('noExpiry'),
    mode_bearer: t('modeBearer'),
    mode_customer_bound: t('modeCustomerBound'),
    redemption_mode: t('redemptionMode'),
    issued_by: t('issuedBy'),
    issued_at: t('issuedAt'),
    terms: t('terms'),
  };
}

// ─── Voucher ticket builder ─────────────────────────────────────────────────

/**
 * Input shape for assembling a voucher ticket from server response or local
 * voucher record. Decoupled from the API DTO to keep the builder pure and
 * easy to test.
 *
 * Monetary values are decimal strings at the currency's display scale; the
 * formatter uses bcformat to render at the right precision.
 */
export interface VoucherTicketInput {
  code: string;
  initialBalance: string;
  currency: string;
  expiresAt: string | null;
  redemptionMode: 'Bearer' | 'CustomerBound';
  issuedAt: string;
  companyName: string;
  companyAddressLine1?: string | null;
  companyAddressLine2?: string | null;
  companyCity?: string | null;
  companyPostalCode?: string | null;
  companyCountry?: string | null;
  companyTaxId?: string | null;
  companyPhone?: string | null;
  terminalName: string;
  operatorName: string;
}

/**
 * Transform a voucher record (from the server's refund response) into the
 * VoucherTicketData payload sent to the Tauri `print_voucher_ticket` command.
 *
 * Currency-aware display: the initial_balance string is formatted to the
 * currency's native precision (EUR=2, TND=3, JPY=0, …) using bcformat.
 */
export function buildVoucherTicketData(input: VoucherTicketInput): VoucherTicketData {
  const decimals = getCurrencyDecimals(input.currency);
  const currencySymbol = getCurrencySymbol(input.currency);

  return {
    company: {
      name: input.companyName,
      address_line1: input.companyAddressLine1 ?? '',
      address_line2: input.companyAddressLine2 ?? null,
      city: input.companyCity ?? '',
      postal_code: input.companyPostalCode ?? '',
      country: input.companyCountry ?? '',
      tax_id: input.companyTaxId ?? '',
      phone: input.companyPhone ?? null,
    },
    code: input.code,
    initial_balance: bcformat(input.initialBalance, decimals),
    currency_symbol: currencySymbol,
    expires_at: input.expiresAt,
    redemption_mode: input.redemptionMode,
    issued_at: input.issuedAt,
    terminal_name: input.terminalName,
    operator_name: input.operatorName,
    labels: buildVoucherTicketLabels(),
  };
}
