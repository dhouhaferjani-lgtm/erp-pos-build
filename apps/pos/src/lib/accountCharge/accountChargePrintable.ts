import type {
  AccountChargeLineItem,
  AccountChargePayload,
} from '@/lib/fiscal/payloads/AccountChargePayload';

export interface AccountChargePrintableLine {
  name: string;
  quantity: string;
  unitPrice: string;
  lineSubtotal: string;
  lineVat: string;
  lineTotal: string;
}

export interface AccountChargePrintableVatRow {
  rate: string;
  taxCategoryCode: string;
  netAmount: string;
  vatAmount: string;
  grossAmount: string;
}

export interface AccountChargePrintable {
  title: 'ACCOUNT CHARGE RECEIPT';
  accountChargeUuid: string;
  fiscalEventId: string;
  fiscalHash: string;
  terminalName: string;
  terminalId: string;
  shiftId: string;
  cashierName: string;
  businessDate: string;
  eventTimeDevice: string;
  sellerName: string;
  sellerTaxNumber: string;
  sellerAddress: string;
  customerName: string;
  customerPhone: string | null;
  customerCategory: string | null;
  accountIdentifier: string | null;
  amountChargedToAccount: string;
  chargeAmount: string;
  subtotal: string;
  vatTotal: string;
  balanceBefore: string;
  balanceAfter: string;
  creditLimit: string | null;
  creditAvailableBefore: string | null;
  creditAvailableAfter: string | null;
  dueDate: string | null;
  termsLabel: string | null;
  customerSnapshotStale: boolean;
  balanceSnapshotStale: boolean;
  stalenessReason: string | null;
  trainingFlag: boolean;
  lines: AccountChargePrintableLine[];
  vatBreakdown: AccountChargePrintableVatRow[];
}

function lineTotal(line: AccountChargeLineItem): string {
  return line.line_vat === '0' || /^0\.0+$/.test(line.line_vat)
    ? line.line_subtotal
    : addDecimalStrings(line.line_subtotal, line.line_vat);
}

function addDecimalStrings(a: string, b: string): string {
  const scale = Math.max(decimalScale(a), decimalScale(b));
  const left = toMinorUnits(a, scale);
  const right = toMinorUnits(b, scale);
  const sum = left + right;

  if (scale === 0) return sum.toString();

  const denominator = 10n ** BigInt(scale);
  const whole = sum / denominator;
  const fractional = (sum % denominator).toString().padStart(scale, '0');

  return `${whole}.${fractional}`;
}

function decimalScale(value: string): number {
  return value.includes('.') ? value.split('.')[1]?.length ?? 0 : 0;
}

function toMinorUnits(value: string, scale: number): bigint {
  const [whole = '0', fraction = ''] = value.split('.');
  return BigInt(`${whole}${fraction.padEnd(scale, '0')}`);
}

export function buildAccountChargePrintable(input: {
  payload: AccountChargePayload;
  fiscalEventId: string;
  fiscalHash: string;
  terminalName: string;
}): AccountChargePrintable {
  const { payload } = input;

  return {
    title: 'ACCOUNT CHARGE RECEIPT',
    accountChargeUuid: payload.account_charge_uuid,
    fiscalEventId: input.fiscalEventId,
    fiscalHash: input.fiscalHash,
    terminalName: input.terminalName,
    terminalId: payload.terminal_id,
    shiftId: payload.shift_id,
    cashierName: payload.cashier_name,
    businessDate: payload.business_date,
    eventTimeDevice: payload.event_time_device,
    sellerName: payload.seller.name,
    sellerTaxNumber: payload.seller.tax_number,
    sellerAddress: [
      payload.seller.address.street,
      `${payload.seller.address.postal_code} ${payload.seller.address.city}`,
      payload.seller.address.country_code,
    ].join(', '),
    customerName: payload.customer.name,
    customerPhone: payload.customer.phone,
    customerCategory: payload.customer.customer_category,
    accountIdentifier: payload.customer.account_identifier,
    amountChargedToAccount: payload.totals.amount_charged_to_account,
    chargeAmount: payload.local_balance_snapshot.charge_amount,
    subtotal: payload.totals.subtotal,
    vatTotal: payload.totals.vat_total,
    balanceBefore: payload.local_balance_snapshot.net_balance_before,
    balanceAfter: payload.local_balance_snapshot.projected_net_balance_after,
    creditLimit: payload.credit_decision.credit_limit,
    creditAvailableBefore: payload.credit_decision.credit_available_before,
    creditAvailableAfter: payload.credit_decision.credit_available_after,
    dueDate: payload.charge_terms.due_date,
    termsLabel: payload.charge_terms.terms_label,
    customerSnapshotStale: payload.staleness.customer_snapshot_stale,
    balanceSnapshotStale: payload.staleness.balance_snapshot_stale,
    stalenessReason: payload.staleness.staleness_reason,
    trainingFlag: payload.training_flag,
    lines: payload.line_items.map((line) => ({
      name: line.name,
      quantity: line.quantity,
      unitPrice: line.unit_price,
      lineSubtotal: line.line_subtotal,
      lineVat: line.line_vat,
      lineTotal: lineTotal(line),
    })),
    vatBreakdown: payload.vat_breakdown.map((row) => ({
      rate: row.rate,
      taxCategoryCode: row.tax_category_code,
      netAmount: row.net_amount,
      vatAmount: row.vat_amount,
      grossAmount: row.gross_amount,
    })),
  };
}
