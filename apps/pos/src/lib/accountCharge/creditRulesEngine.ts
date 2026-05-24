import type {
  AccountChargeCreditDecision,
  AccountChargeOverrideEvidence,
} from '@/lib/fiscal/payloads/AccountChargePayload';
import type { CustomerAccountStatus } from '@/lib/customer/customerTypes';

export type { AccountChargeOverrideEvidence } from '@/lib/fiscal/payloads/AccountChargePayload';

export type AccountChargeRejectionCode =
  | 'customer_tenant_mismatch'
  | 'customer_company_mismatch'
  | 'customer_inactive'
  | 'account_suspended'
  | 'account_closed'
  | 'account_disputed'
  | 'charge_account_disabled'
  | 'charge_policy_missing'
  | 'balance_snapshot_missing'
  | 'balance_snapshot_invalid'
  | 'balance_snapshot_hard_stale'
  | 'customer_alias_ambiguous'
  | 'money_scale_invalid'
  | 'credit_limit_exceeded'
  | 'override_evidence_mismatch';

export interface AccountChargeCreditDecisionInput {
  tenant_id: string;
  company_id: string;
  expected_tenant_id: string;
  expected_company_id: string;
  customer_id: string;
  customer_sync_status: 'synced' | 'pending_create';
  alias_candidates: string[];
  is_active: boolean | 0 | 1;
  account_status: CustomerAccountStatus;
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
  override_evidence?: AccountChargeOverrideEvidence | null;
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

function matchesOverrideEvidence(
  input: AccountChargeCreditDecisionInput,
  scope: AccountChargeOverrideEvidence['approval_scope'],
): boolean {
  const evidence = input.override_evidence ?? null;
  return evidence !== null &&
    evidence.approval_event_id.trim() !== '' &&
    evidence.override_event_id.trim() !== '' &&
    evidence.approval_scope === scope &&
    evidence.target_customer_id === input.customer_id &&
    evidence.target_amount === input.charge_amount &&
    evidence.target_account_status === input.account_status &&
    evidence.policy_version === input.charge_policy_version;
}

function buildApprovedDecision(input: {
  creditAvailableAfter: bigint;
  creditAvailableBefore: bigint;
  creditLimit: bigint;
  limitExceeded: boolean;
  overrideEvidence: AccountChargeOverrideEvidence | null;
  policyVersion: string;
  scale: number;
}): AccountChargeCreditDecision {
  const zero = 0n;
  const creditAvailableAfter = input.creditAvailableAfter < zero ? zero : input.creditAvailableAfter;
  const creditAvailableBefore = input.creditAvailableBefore < zero ? zero : input.creditAvailableBefore;

  return {
    credit_available_after: formatMinorUnits(creditAvailableAfter, input.scale),
    credit_available_before: formatMinorUnits(creditAvailableBefore, input.scale),
    credit_limit: formatMinorUnits(input.creditLimit, input.scale),
    decision: input.overrideEvidence === null ? 'approved' : 'approved_with_override',
    limit_exceeded: input.limitExceeded,
    mirror_stale_at_authoring: false,
    override_evidence: input.overrideEvidence,
    policy_version: input.policyVersion,
    stale_policy_action: 'allow',
    warnings: [],
  };
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

  let appliedOverrideEvidence: AccountChargeOverrideEvidence | null = null;

  if (input.account_status === 'closed') {
    return reject('account_closed', 'account_status');
  }

  if (input.account_status === 'suspended') {
    if (matchesOverrideEvidence(input, 'account_status_override')) {
      appliedOverrideEvidence = input.override_evidence ?? null;
    } else if (input.override_evidence !== null && input.override_evidence !== undefined) {
      return reject('override_evidence_mismatch', 'override_evidence');
    } else {
      return reject('account_suspended', 'account_status');
    }
  }

  if (input.account_status === 'disputed') {
    if (matchesOverrideEvidence(input, 'account_status_override')) {
      appliedOverrideEvidence = input.override_evidence ?? null;
    } else if (input.override_evidence !== null && input.override_evidence !== undefined) {
      return reject('override_evidence_mismatch', 'override_evidence');
    } else {
      return reject('account_disputed', 'account_status');
    }
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
    if (!matchesOverrideEvidence(input, 'credit_limit_override')) {
      if (input.override_evidence !== null && input.override_evidence !== undefined) {
        return reject('override_evidence_mismatch', 'override_evidence');
      }

      return reject('credit_limit_exceeded', 'credit_limit');
    }
    appliedOverrideEvidence = input.override_evidence ?? null;
  } else if (
    appliedOverrideEvidence === null &&
    input.override_evidence !== null &&
    input.override_evidence !== undefined
  ) {
    return reject('override_evidence_mismatch', 'override_evidence');
  }

  return {
    ok: true,
    decision: buildApprovedDecision({
      creditAvailableAfter,
      creditAvailableBefore,
      creditLimit,
      limitExceeded: creditAvailableAfter < zero,
      overrideEvidence: appliedOverrideEvidence,
      policyVersion: input.charge_policy_version,
      scale,
    }),
  };
}
