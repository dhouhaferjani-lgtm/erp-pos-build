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
import type { AccountChargePrintable } from '@/lib/accountCharge/accountChargePrintable';
import type {
  IssuedVoucher,
  ReturnSettlementResponse,
} from '@/lib/refundFlow/refundSettlementService';
import {
  formatLegalIdentifierLines,
  resolveSellerIdentityWithSource,
  type LocationFiscalFields,
} from '@/lib/fiscal/sellerIdentity';
import { dedupeVatNumber } from '@/lib/receiptTaxIdentity';

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
 * Terminal-location identity passed by the print call sites (sourced from
 * `useTerminalStore.getState().terminal?.location` — this builder stays a
 * pure transform, so the store read happens at the caller like the other
 * header fields). Drives the atomic header identity per spec 2026-06-11 §4.6.
 */
export interface SellerDisplayLocation extends LocationFiscalFields {
  vat_number?: string | null;
  legal_identifiers?: Record<string, unknown> | null;
}

/**
 * Options for {@link buildEscPosReceiptData}. Folds what were four trailing
 * positional params (FU-3) into one bag, so callers no longer thread
 * `undefined` placeholders.
 */
export interface BuildEscPosReceiptOptions {
  /** Visibility flags from company receipt settings. */
  visibilitySettings?: ReceiptVisibilitySettings;
  /** True if this is a duplicata of an already-issued receipt. */
  isReprint?: boolean;
  /** QR token + refund cross-references. */
  extras?: ReceiptExtras;
  /** Terminal location for the atomic header identity (spec §4.6). */
  sellerLocation?: SellerDisplayLocation | null;
}

/**
 * Transforms a full receipt API response into the ESC/POS ReceiptData
 * structure expected by the Tauri thermal printing backend.
 *
 * Header identity is ATOMIC (spec 2026-06-11 §4.6): when `sellerLocation` is
 * fiscally complete, the tax id AND the address print from the location
 * (plus the display-only vat_number / legal identifier lines); otherwise the
 * header is wholesale the receipt's company block — never mixed.
 *
 * @param receipt Full receipt response from the API
 * @param options Display options — see {@link BuildEscPosReceiptOptions}.
 */
export function buildEscPosReceiptData(
  receipt: FullReceiptResponse,
  options: BuildEscPosReceiptOptions = {},
): ReceiptData {
  const { visibilitySettings, isReprint, extras, sellerLocation } = options;
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

  // Same TS-boundary treatment for the SIGNED rounding adjustment: the Rust
  // formatter branches on the flag and prints the string verbatim, so it never
  // parses money. Unlike tolerance this compares !== 0, not > 0 — the
  // adjustment can legitimately be negative (round down).
  const cashRoundingAdjustment = receipt.cash_rounding_adjustment ?? null;
  const hasCashRounding =
    cashRoundingAdjustment !== null
    && cashRoundingAdjustment !== ''
    && bccomp(cashRoundingAdjustment, '0') !== 0;

  // Receipt-kind discriminator. Explicit override wins; otherwise infer from
  // receipt_type ('return' = refund, anything else = sale).
  // D-1: was this receipt sealed with the remise ventilated into its base?
  // `discount_allocated` is written verbatim by the projection at
  // `event_version >= 5` and left NULL for every earlier receipt, so the
  // presence of a share on ANY sealed row is the era.
  const isPostRemiseReceipt = receipt.vat_details.some(
    (vat) => vat.discount_allocated !== null && vat.discount_allocated !== undefined,
  );

  const receiptKind: 'sale' | 'refund' =
    extras?.receiptKind ?? (receipt.receipt_type === 'return' ? 'refund' : 'sale');

  // Atomic header identity (spec 2026-06-11 §4.6): complete location →
  // location tax id + address; otherwise wholesale company. The resolver
  // reports which source it used (FU-3) so the vat/legal-identifier display
  // below can't drift from the resolved identity. The company branch reads the
  // snake_case receipt.company fields directly.
  const { identity, source } = resolveSellerIdentityWithSource(receipt.company, sellerLocation);
  const locationComplete = source === 'location';

  return {
    company: {
      name: receipt.company.name,
      address_line1: identity.street ?? '',
      // address_street_2 has no location counterpart — printing it under a
      // location street would mix identities, so it only prints with the
      // company address.
      address_line2: locationComplete ? null : receipt.company.address_street_2 ?? null,
      city: identity.city ?? '',
      postal_code: identity.postalCode ?? '',
      country: identity.countryCode ?? receipt.company.country_code,
      tax_id: identity.taxNumber ?? '',
      phone: receipt.company.phone ?? null,
      // DEV-QA-092: the template prints tax_id and vat_number as two
      // unconditional lines, and both come from the SAME establishment record.
      // In TN the matricule fiscal IS the VAT id, so it printed twice — the
      // dedup is display-only and never reaches the signed seller block.
      vat_number: dedupeVatNumber(
        identity.taxNumber,
        locationComplete ? sellerLocation?.vat_number ?? null : null,
      ),
      legal_identifier_lines: locationComplete
        ? formatLegalIdentifierLines(sellerLocation?.legal_identifiers)
        : null,
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
    // D-1 (owner ruling 2026-08-25): on a POST-remise receipt the printed
    // `Subtotal` is the ticket's GROSS (TTC) BEFORE the remise, so the
    // customer's own arithmetic lands: `Subtotal − Remise (+ rounding) ==
    // TOTAL`. `receipts.subtotal` is the post-remise taxable base there, and
    // printing it verbatim beside a Remise line would double-count the
    // discount; the base and VAT the customer is entitled to see are in the
    // per-rate ventilation table.
    //
    // Gate r1 finding 8 — a REPRINT of a pre-D-1 receipt must stay
    // byte-faithful to the ticket the customer was handed. On those receipts
    // `receipts.subtotal` was the PRE-discount NET, and re-deriving would show
    // 640.000 where the original showed 569.000. NF525 reprint fidelity is a
    // defensible expectation, so the era decides, read off the same
    // discriminator the ledger and the return path use.
    subtotal: isPostRemiseReceipt
      ? bcformat(
        bcsub(
          bcadd(receipt.total, receipt.discount_amount, decimals),
          hasCashRounding && cashRoundingAdjustment !== null ? cashRoundingAdjustment : '0',
          decimals,
        ),
        decimals,
      )
      : bcformat(receipt.subtotal, decimals),
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
    cash_rounding_adjustment: hasCashRounding
      ? bcformat(cashRoundingAdjustment, decimals)
      : null,
    has_cash_rounding: hasCashRounding,
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

  // D-1 (owner ruling 2026-08-25): the printed VAT block is the SEALED
  // per-rate breakdown, POST-remise. Re-deriving it from the cart lines here
  // would print the PRE-discount base — the very figure the ruling removed —
  // and put the customer's ticket at odds with the fiscal event the chain
  // carries. The fallback below is reached only when the sealed rows are
  // unavailable (idempotency replay whose canonical bytes could not be
  // re-read); it is deliberately the old line roll-up, which is exact for the
  // discount-free tickets that fallback can serve.
  const sealedVatBreakdown = (result.vatBreakdown ?? [])
    .filter((group) => bccomp(group.netAmount, '0') !== 0 || bccomp(group.vatAmount, '0') !== 0)
    .map((group) => ({
      rate: group.rate,
      taxable: bcformat(group.netAmount, decimals),
      tax: bcformat(group.vatAmount, decimals),
    }));

  const vatByRate = new Map<string, { taxable: string; tax: string }>();
  if (sealedVatBreakdown.length === 0) {
    for (const item of cartItems) {
      const rate = item.tax_rate;
      if (bccomp(item.tax_amount, '0') === 0) continue;
      const taxable = bcsub(item.line_total, item.tax_amount, decimals);
      const existing = vatByRate.get(rate) ?? {
        taxable: (0).toFixed(decimals),
        tax: (0).toFixed(decimals),
      };
      vatByRate.set(rate, {
        taxable: bcadd(existing.taxable, taxable, decimals),
        tax: bcadd(existing.tax, item.tax_amount, decimals),
      });
    }
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
    vat_breakdown: sealedVatBreakdown.length > 0
      ? sealedVatBreakdown
      : Array.from(vatByRate.entries())
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([rate, { taxable, tax }]) => ({
          rate,
          taxable: bcformat(taxable, decimals),
          tax: bcformat(tax, decimals),
        })),
    // A 100 %-comp ticket tenders nothing (D-1 / G3-A): the remise line
    // carries the story, so no tender row is printed either.
    payments: bccomp(result.total, '0') === 0 && bccomp(result.discountAmount, '0') > 0
      ? []
      : [{
        method: paymentMethodName,
        amount: bcformat(result.total, decimals),
      }],
    change_due: bcformat(result.changeDue, decimals),
    tolerance_writeoff: null,
    has_tolerance: false,
    cash_rounding_adjustment: null,
    has_cash_rounding: false,
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
    cash_rounding_adjustment: null,
    has_cash_rounding: false,
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
    business_date: payload.business_date,
    terminal_id: payload.terminal_id,
    shift_id: payload.shift_id,
    training_flag: payload.training_flag,
    customer_account_id: payload.customer.customer_id,
    customer_phone: payload.customer.phone,
  };
}

export interface BuildAccountChargeReceiptDataInput {
  payload: AccountChargePrintable;
  currencyCode: string;
}

export function buildEscPosAccountChargeReceiptData(
  input: BuildAccountChargeReceiptDataInput,
): ReceiptData {
  const p = input.payload;
  const currencySymbol = getCurrencySymbol(input.currencyCode);
  const scale = getCurrencyDecimals(input.currencyCode);

  return {
    // AccountChargePrintable carries the seller address as a single
    // pre-formatted string (sellerAddress). It is placed in address_line1 and
    // the structured city/postal_code/country are intentionally left empty —
    // the full address still prints via address_line1.
    company: {
      name: p.sellerName,
      address_line1: p.sellerAddress,
      address_line2: null,
      city: '',
      postal_code: '',
      country: '',
      tax_id: p.sellerTaxNumber,
      phone: null,
    },
    receipt_number: p.accountChargeUuid,
    date_time: p.eventTimeDevice,
    terminal_name: p.terminalName,
    operator_name: p.cashierName,
    lines: p.lines.map((l) => ({
      name: l.name,
      quantity: l.quantity,
      // unit_price is the GROSS / tax-inclusive price, verbatim from the cart.
      // This matches the SALE_RECEIPT printed-receipt convention (tax-inclusive
      // markets) used by buildEscPosReceiptData for sale lines.
      unit_price: bcformat(l.unitPrice, scale),
      line_total: bcformat(l.lineTotal, scale),
      modifiers: null,
      discount: null,
    })),
    subtotal: bcformat(p.subtotal, scale),
    discount_amount: bcformat('0', scale),
    tax_amount: bcformat(p.vatTotal, scale),
    total: bcformat(p.amountChargedToAccount, scale),
    currency_symbol: currencySymbol,
    vat_breakdown: p.vatBreakdown.map((v) => ({
      rate: v.rate,
      taxable: bcformat(v.netAmount, scale),
      tax: bcformat(v.vatAmount, scale),
    })),
    payments: [],
    change_due: bcformat('0', scale),
    tolerance_writeoff: null,
    has_tolerance: false,
    cash_rounding_adjustment: null,
    has_cash_rounding: false,
    fiscal_hash: p.fiscalHash,
    fiscal_signature: p.fiscalEventId,
    customer_name: p.customerName,
    notes: null,
    labels: buildReceiptLabels(),
    show_vat_breakdown: true,
    show_fiscal_info: true,
    show_payment_details: false,
    show_customer: true,
    receipt_kind: 'account_charge',
    original_receipt_number: null,
    original_receipt_qr_token: null,
    account_balance_before: bcformat(p.balanceBefore, scale),
    account_balance_after: bcformat(p.balanceAfter, scale),
    account_snapshot_stale: p.customerSnapshotStale || p.balanceSnapshotStale,
    business_date: p.businessDate,
    terminal_id: p.terminalId,
    shift_id: p.shiftId,
    training_flag: p.trainingFlag,
    // accountIdentifier is the human-readable account code (e.g. 'CUST-0001').
    // It may be null for accounts created before the identifier was introduced.
    customer_account_id: p.accountIdentifier,
    customer_phone: p.customerPhone,
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
    tolerance: t('tolerance'),
    vat_rate: t('vatRate'),
    taxable: t('taxable'),
    tax_col: t('taxCol'),
    thank_you: t('thankYou'),
    tax_id: t('taxId'),
    vat_number: t('vatNumber'),
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
    qr_scan_label: t('qrScanLabel'),
    account_payment_header: t('accountPaymentHeader'),
    balance_before: t('balanceBefore'),
    balance_after: t('balanceAfter'),
    stale_balance: t('staleBalance'),
    business_date: t('businessDate'),
    terminal_id: t('terminalId'),
    shift_id: t('shiftId'),
    training: t('training'),
    customer_account: t('customerAccount'),
    customer_phone: t('customerPhone'),
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

// ─── Refund (AVOIR) receipt builder — Phase 3 ───────────────────────────────

/**
 * Local context for the AVOIR print. The /return response carries the return
 * receipt itself (number, totals, lines, qr_token) but not the company /
 * terminal / operator header nor the ORIGINAL ticket reference — those come
 * from the refund session and the auth/terminal/operator stores (same
 * assembly pattern as getOfflineReceiptForPrint).
 */
export interface RefundReceiptPrintContext {
  companyName: string;
  companyCountryCode: string;
  terminalName: string;
  operatorName: string;
  /** Original (sale) receipt number — null when the session lost it (resumed draft edge). */
  originalReceiptNumber: string | null;
  /** Original (sale) receipt's QR token — re-printed for further partial refunds. */
  originalReceiptQrToken: string | null;
}

/**
 * Transform the POST /pos/receipts/{id}/return settlement response into the
 * ESC/POS ReceiptData for the REMBOURSEMENT/AVOIR template
 * (`receipt_kind: 'refund'`).
 *
 * Amount signing: the server already signs the return receipt NEGATIVE
 * (quantities `-N`, line_total/subtotal/tax/total negative; unit_price stays
 * positive). The Rust template prints monetary strings verbatim, so the
 * server-signed values pass through, re-rendered at the receipt currency's
 * native scale (EUR=2, TND=3, …) via bcformat.
 *
 * The response carries no tender lines and no per-rate VAT breakdown, so the
 * payments / VAT sections are force-hidden regardless of company visibility
 * settings (an empty section header would print otherwise).
 */
export function buildEscPosRefundReceiptData(
  response: ReturnSettlementResponse,
  context: RefundReceiptPrintContext,
  visibilitySettings?: ReceiptVisibilitySettings,
): ReceiptData {
  const decimals = getCurrencyDecimals(response.currency);
  const currencySymbol = getCurrencySymbol(response.currency);

  return {
    company: {
      name: context.companyName,
      address_line1: '',
      address_line2: null,
      city: '',
      postal_code: '',
      country: context.companyCountryCode,
      tax_id: '',
      phone: null,
    },
    receipt_number: response.receipt_number,
    date_time: response.posted_at,
    terminal_name: context.terminalName,
    operator_name: context.operatorName,
    lines: response.lines.map((line) => ({
      name: line.product_name,
      quantity: line.quantity,
      unit_price: bcformat(line.unit_price, decimals),
      line_total: bcformat(line.line_total, decimals),
      modifiers: null,
      discount: null,
    })),
    subtotal: bcformat(response.subtotal, decimals),
    discount_amount: bcformat('0', decimals),
    tax_amount: bcformat(response.tax_amount, decimals),
    total: bcformat(response.total, decimals),
    currency_symbol: currencySymbol,
    vat_breakdown: [],
    payments: [],
    change_due: bcformat('0', decimals),
    tolerance_writeoff: null,
    has_tolerance: false,
    cash_rounding_adjustment: null,
    has_cash_rounding: false,
    fiscal_hash: null,
    fiscal_signature: null,
    customer_name: null,
    notes: null,
    labels: buildReceiptLabels(),
    show_vat_breakdown: false,
    show_fiscal_info: visibilitySettings?.show_fiscal_info,
    show_payment_details: false,
    show_customer: false,
    qr_token: response.qr_token,
    receipt_kind: 'refund',
    original_receipt_number: context.originalReceiptNumber,
    original_receipt_qr_token: context.originalReceiptQrToken,
  };
}

/** Context for the voucher ticket printed alongside a store_voucher refund. */
export interface RefundVoucherPrintContext {
  companyName: string;
  companyCountryCode: string;
  terminalName: string;
  operatorName: string;
  /** Voucher issuance instant — the settlement's posted_at (the response carries no issued_at). */
  issuedAt: string;
}

/**
 * Normalize the server RedemptionMode enum values ('bearer' /
 * 'customer_bound') onto the template's discriminator. Unknown values fall
 * back to Bearer — the more permissive label is the safer print (the server
 * remains the redemption authority either way).
 */
function normalizeRedemptionMode(value: string): 'Bearer' | 'CustomerBound' {
  return value === 'customer_bound' || value === 'CustomerBound'
    ? 'CustomerBound'
    : 'Bearer';
}

/**
 * Transform the /return response's `issued_voucher` into the
 * `print_voucher_ticket` payload (code / amount / expiry from the response).
 */
export function buildRefundVoucherTicketData(
  voucher: IssuedVoucher,
  context: RefundVoucherPrintContext,
): VoucherTicketData {
  return buildVoucherTicketData({
    code: voucher.code,
    initialBalance: voucher.initial_balance,
    currency: voucher.currency,
    expiresAt: voucher.expires_at,
    redemptionMode: normalizeRedemptionMode(voucher.redemption_mode),
    issuedAt: context.issuedAt,
    companyName: context.companyName,
    companyCountry: context.companyCountryCode,
    terminalName: context.terminalName,
    operatorName: context.operatorName,
  });
}
