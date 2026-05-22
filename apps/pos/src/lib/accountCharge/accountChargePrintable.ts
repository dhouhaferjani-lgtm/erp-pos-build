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

export interface AccountChargePrintable {
  title: 'ACCOUNT CHARGE RECEIPT';
  accountChargeUuid: string;
  fiscalEventId: string;
  fiscalHash: string;
  terminalName: string;
  cashierName: string;
  businessDate: string;
  eventTimeDevice: string;
  customerName: string;
  customerPhone: string | null;
  amountChargedToAccount: string;
  subtotal: string;
  vatTotal: string;
  balanceBefore: string;
  balanceAfter: string;
  creditAvailableAfter: string | null;
  dueDate: string | null;
  termsLabel: string | null;
  lines: AccountChargePrintableLine[];
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
    cashierName: payload.cashier_name,
    businessDate: payload.business_date,
    eventTimeDevice: payload.event_time_device,
    customerName: payload.customer.name,
    customerPhone: payload.customer.phone,
    amountChargedToAccount: payload.totals.amount_charged_to_account,
    subtotal: payload.totals.subtotal,
    vatTotal: payload.totals.vat_total,
    balanceBefore: payload.local_balance_snapshot.net_balance_before,
    balanceAfter: payload.local_balance_snapshot.projected_net_balance_after,
    creditAvailableAfter: payload.credit_decision.credit_available_after,
    dueDate: payload.charge_terms.due_date,
    termsLabel: payload.charge_terms.terms_label,
    lines: payload.line_items.map((line) => ({
      name: line.name,
      quantity: line.quantity,
      unitPrice: line.unit_price,
      lineSubtotal: line.line_subtotal,
      lineVat: line.line_vat,
      lineTotal: lineTotal(line),
    })),
  };
}
