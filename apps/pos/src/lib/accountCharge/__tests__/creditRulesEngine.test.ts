import { describe, expect, it } from 'vitest';
import { evaluateAccountChargeCreditDecision, type AccountChargeCreditDecisionInput } from '../creditRulesEngine';

const NOW = new Date('2026-05-21T12:00:00.000Z');

function makeInput(overrides: Partial<AccountChargeCreditDecisionInput> = {}): AccountChargeCreditDecisionInput {
  return {
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    expected_tenant_id: 'tenant-1',
    expected_company_id: 'company-1',
    customer_id: 'customer-1',
    customer_sync_status: 'synced',
    alias_candidates: [],
    is_active: 1,
    charge_account_enabled: true,
    charge_policy_version: 'phase3-default-v1',
    receivable_balance: '300.000',
    credit_balance: '0.000',
    credit_limit: '500.000',
    charge_amount: '119.000',
    currency_scale: 3,
    balance_updated_at: '2026-05-21T10:10:00.000Z',
    now: NOW,
    hard_stale_after_minutes: 240,
    ...overrides,
  };
}

describe('evaluateAccountChargeCreditDecision', () => {
  it.each([
    ['wrong_tenant', { tenant_id: 'other-tenant' }, 'customer_tenant_mismatch'],
    ['wrong_company', { company_id: 'other-company' }, 'customer_company_mismatch'],
    ['inactive_customer', { is_active: false }, 'customer_inactive'],
    ['charge_disabled', { charge_account_enabled: false }, 'charge_account_disabled'],
    ['missing_policy', { charge_policy_version: null }, 'charge_policy_missing'],
    ['limit_exceeded', { credit_limit: '500.000', receivable_balance: '450.000', credit_balance: '0.000' }, 'credit_limit_exceeded'],
    ['missing_balance_snapshot', { balance_updated_at: null }, 'balance_snapshot_missing'],
    ['invalid_balance_snapshot', { balance_updated_at: 'not-a-date' }, 'balance_snapshot_invalid'],
    ['hard_stale', { balance_updated_at: '2026-05-01T00:00:00.000Z' }, 'balance_snapshot_hard_stale'],
    [
      'ambiguous_alias',
      { customer_sync_status: 'pending_create', alias_candidates: ['alias-a', 'alias-b'] },
      'customer_alias_ambiguous',
    ],
    ['invalid_money', { charge_amount: '119.00' }, 'money_scale_invalid'],
  ] satisfies Array<[string, Partial<AccountChargeCreditDecisionInput>, string]>)(
    'rejects %s',
    (_, overrides, expectedCode) => {
      const result = evaluateAccountChargeCreditDecision(makeInput(overrides));

      expect(result.ok).toBe(false);
      if (!result.ok) {
        expect(result.error.code).toBe(expectedCode);
      }
    },
  );

  it('approves and records balance math inputs', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      receivable_balance: '300.000',
      credit_balance: '0.000',
      credit_limit: '500.000',
      charge_amount: '119.000',
    }));

    expect(result).toMatchObject({
      ok: true,
      decision: {
        decision: 'approved',
        credit_available_before: '200.000',
        credit_available_after: '81.000',
        credit_limit: '500.000',
        limit_exceeded: false,
        mirror_stale_at_authoring: false,
        policy_version: 'phase3-default-v1',
        stale_policy_action: 'allow',
        warnings: [],
      },
    });
  });

  it('uses integer minor-unit math instead of JavaScript floating point', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      receivable_balance: '0.100',
      credit_balance: '0.000',
      credit_limit: '0.300',
      charge_amount: '0.200',
    }));

    expect(result).toMatchObject({
      ok: true,
      decision: {
        credit_available_before: '0.200',
        credit_available_after: '0.000',
        limit_exceeded: false,
      },
    });
  });
});
