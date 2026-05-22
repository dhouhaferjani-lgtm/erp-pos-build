import type { AccountChargeCreditDecision } from '@/lib/fiscal/payloads/AccountChargePayload';

export type AccountChargeRejectionCode =
  | 'customer_tenant_mismatch'
  | 'customer_company_mismatch'
  | 'customer_inactive'
  | 'charge_account_disabled'
  | 'charge_policy_missing'
  | 'balance_snapshot_missing'
  | 'balance_snapshot_invalid'
  | 'balance_snapshot_hard_stale'
  | 'customer_alias_ambiguous'
  | 'money_scale_invalid'
  | 'credit_limit_exceeded';

export interface AccountChargeCreditDecisionInput {
  tenant_id: string;
  company_id: string;
  expected_tenant_id: string;
  expected_company_id: string;
  customer_id: string;
  customer_sync_status: 'synced' | 'pending_create';
  alias_candidates: string[];
  is_active: boolean | 0 | 1;
  charge_account_enabled: boolean | 0 | 1;
  charge_policy_version: string | null;
  receivable_balance: string;
  credit_balance: string;
  credit_limit: string | null;
  charge_amount: string;
  currency_scale: 0 | 2 | 3;
  balance_updated_at: string | null;
  now: Date;
  hard_stale_after_minutes: number;
}

export type AccountChargeCreditDecisionResult =
  | { ok: true; decision: AccountChargeCreditDecision }
  | { ok: false; error: { code: AccountChargeRejectionCode; field?: string } };

function reject(code: AccountChargeRejectionCode, field?: string): AccountChargeCreditDecisionResult {
  return { ok: false, error: field ? { code, field } : { code } };
}

function isEnabled(value: boolean | 0 | 1): boolean {
  return value === true || value === 1;
}

function parseMinorUnits(value: string, scale: number): bigint | null {
  if (scale === 0) {
    return /^\d+$/.test(value) ? BigInt(value) : null;
  }

  const match = new RegExp(`^(\\d+)\\.(\\d{${scale}})$`).exec(value);
  if (!match) return null;

  const [, whole, fractional] = match;
  return BigInt(`${whole}${fractional}`);
}

function formatMinorUnits(value: bigint, scale: number): string {
  if (scale === 0) return value.toString();

  const denominator = 10n ** BigInt(scale);
  const whole = value / denominator;
  const fractional = (value % denominator).toString().padStart(scale, '0');

  return `${whole}.${fractional}`;
}

function minutesBetween(a: Date, b: Date): number {
  return Math.floor((a.getTime() - b.getTime()) / 60_000);
}

export function evaluateAccountChargeCreditDecision(
  input: AccountChargeCreditDecisionInput,
): AccountChargeCreditDecisionResult {
  if (Number.isNaN(input.now.getTime())) {
    return reject('balance_snapshot_invalid', 'now');
  }

  if (input.tenant_id !== input.expected_tenant_id) {
    return reject('customer_tenant_mismatch', 'tenant_id');
  }

  if (input.company_id !== input.expected_company_id) {
    return reject('customer_company_mismatch', 'company_id');
  }

  if (!isEnabled(input.is_active)) {
    return reject('customer_inactive', 'is_active');
  }

  if (!isEnabled(input.charge_account_enabled)) {
    return reject('charge_account_disabled', 'charge_account_enabled');
  }

  if (input.charge_policy_version === null || input.charge_policy_version.trim() === '') {
    return reject('charge_policy_missing', 'charge_policy_version');
  }

  if (input.balance_updated_at === null) {
    return reject('balance_snapshot_missing', 'balance_updated_at');
  }

  const balanceUpdatedAt = new Date(input.balance_updated_at);
  if (Number.isNaN(balanceUpdatedAt.getTime())) {
    return reject('balance_snapshot_invalid', 'balance_updated_at');
  }

  if (balanceUpdatedAt.getTime() > input.now.getTime()) {
    return reject('balance_snapshot_invalid', 'balance_updated_at');
  }

  if (minutesBetween(input.now, balanceUpdatedAt) > input.hard_stale_after_minutes) {
    return reject('balance_snapshot_hard_stale', 'balance_updated_at');
  }

  if (input.customer_sync_status === 'pending_create' && input.alias_candidates.length > 1) {
    return reject('customer_alias_ambiguous', 'alias_candidates');
  }

  if (input.credit_limit === null) {
    return reject('charge_policy_missing', 'credit_limit');
  }

  const scale = input.currency_scale;
  const receivableBefore = parseMinorUnits(input.receivable_balance, scale);
  const creditBefore = parseMinorUnits(input.credit_balance, scale);
  const creditLimit = parseMinorUnits(input.credit_limit, scale);
  const chargeAmount = parseMinorUnits(input.charge_amount, scale);

  if (receivableBefore === null) return reject('money_scale_invalid', 'receivable_balance');
  if (creditBefore === null) return reject('money_scale_invalid', 'credit_balance');
  if (creditLimit === null) return reject('money_scale_invalid', 'credit_limit');
  if (chargeAmount === null) return reject('money_scale_invalid', 'charge_amount');

  const zero = 0n;
  const netBefore = receivableBefore > creditBefore ? receivableBefore - creditBefore : zero;
  const creditAvailableBefore = creditLimit - netBefore;
  const projectedReceivableAfter = receivableBefore + chargeAmount;
  const projectedNetAfter = projectedReceivableAfter > creditBefore
    ? projectedReceivableAfter - creditBefore
    : zero;
  const creditAvailableAfter = creditLimit - projectedNetAfter;

  if (creditAvailableAfter < zero) {
    return reject('credit_limit_exceeded', 'credit_limit');
  }

  return {
    ok: true,
    decision: {
      credit_available_after: formatMinorUnits(creditAvailableAfter, scale),
      credit_available_before: formatMinorUnits(creditAvailableBefore, scale),
      credit_limit: formatMinorUnits(creditLimit, scale),
      decision: 'approved',
      limit_exceeded: false,
      mirror_stale_at_authoring: false,
      policy_version: input.charge_policy_version,
      stale_policy_action: 'allow',
      warnings: [],
    },
  };
}
