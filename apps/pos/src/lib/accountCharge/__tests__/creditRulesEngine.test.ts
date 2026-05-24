import { describe, expect, it } from 'vitest';
import {
  evaluateAccountChargeCreditDecision,
  type AccountChargeCreditDecisionInput,
  type AccountChargeOverrideEvidence,
} from '../creditRulesEngine';

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
    account_status: 'active',
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

function makeOverrideEvidence(
  overrides: Partial<AccountChargeOverrideEvidence> = {},
): AccountChargeOverrideEvidence {
  return {
    approval_event_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    approval_scope: 'credit_limit_override',
    override_event_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    policy_version: 'phase3-default-v1',
    target_account_status: 'active',
    target_amount: '119.000',
    target_customer_id: 'customer-1',
    ...overrides,
  };
}

describe('evaluateAccountChargeCreditDecision', () => {
  it.each([
    ['wrong_tenant', { tenant_id: 'other-tenant' }, 'customer_tenant_mismatch'],
    ['wrong_company', { company_id: 'other-company' }, 'customer_company_mismatch'],
    ['inactive_customer', { is_active: false }, 'customer_inactive'],
    ['suspended_account', { account_status: 'suspended' }, 'account_suspended'],
    ['closed_account', { account_status: 'closed', credit_limit: '0.000' }, 'account_closed'],
    ['disputed_account', { account_status: 'disputed' }, 'account_disputed'],
    ['charge_disabled', { charge_account_enabled: false }, 'charge_account_disabled'],
    ['missing_policy', { charge_policy_version: null }, 'charge_policy_missing'],
    ['limit_exceeded', { credit_limit: '500.000', receivable_balance: '450.000', credit_balance: '0.000' }, 'credit_limit_exceeded'],
    ['missing_balance_snapshot', { balance_updated_at: null }, 'balance_snapshot_missing'],
    ['invalid_balance_snapshot', { balance_updated_at: 'not-a-date' }, 'balance_snapshot_invalid'],
    ['future_balance_snapshot', { balance_updated_at: '2026-05-21T12:01:00.000Z' }, 'balance_snapshot_invalid'],
    ['invalid_authoring_clock', { now: new Date('not-a-date') }, 'balance_snapshot_invalid'],
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
        override_evidence: null,
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

  it('approves credit-limit overrides only when evidence exactly matches the customer, amount, status, and policy', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      credit_limit: '500.000',
      receivable_balance: '450.000',
      credit_balance: '0.000',
      override_evidence: makeOverrideEvidence(),
    }));

    expect(result).toMatchObject({
      ok: true,
      decision: {
        decision: 'approved_with_override',
        limit_exceeded: true,
        override_evidence: makeOverrideEvidence(),
      },
    });
  });

  it.each([
    ['amount', { target_amount: '118.999' }],
    ['customer', { target_customer_id: 'other-customer' }],
    ['status', { target_account_status: 'suspended' }],
    ['policy', { policy_version: 'other-policy' }],
    ['scope', { approval_scope: 'account_status_override' }],
  ] satisfies Array<[string, Partial<AccountChargeOverrideEvidence>]>)(
    'rejects credit-limit override evidence with mismatched %s',
    (_, evidenceOverrides) => {
      const result = evaluateAccountChargeCreditDecision(makeInput({
        credit_limit: '500.000',
        receivable_balance: '450.000',
        credit_balance: '0.000',
        override_evidence: makeOverrideEvidence(evidenceOverrides),
      }));

      expect(result).toMatchObject({
        ok: false,
        error: { code: 'override_evidence_mismatch' },
      });
    },
  );

  it.each(['suspended', 'disputed'] satisfies Array<'suspended' | 'disputed'>)(
    'approves %s account-status overrides with matching evidence',
    (accountStatus) => {
      const result = evaluateAccountChargeCreditDecision(makeInput({
        account_status: accountStatus,
        override_evidence: makeOverrideEvidence({
          approval_scope: 'account_status_override',
          target_account_status: accountStatus,
        }),
      }));

      expect(result).toMatchObject({
        ok: true,
        decision: {
          decision: 'approved_with_override',
          limit_exceeded: false,
          override_evidence: makeOverrideEvidence({
            approval_scope: 'account_status_override',
            target_account_status: accountStatus,
          }),
        },
      });
    },
  );

  it('keeps closed accounts non-overridable', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      account_status: 'closed',
      override_evidence: makeOverrideEvidence({
        approval_scope: 'account_status_override',
        target_account_status: 'closed',
      }),
    }));

    expect(result).toMatchObject({
      ok: false,
      error: { code: 'account_closed' },
    });
  });

  it('rejects override evidence when no override condition exists', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      override_evidence: makeOverrideEvidence(),
    }));

    expect(result).toMatchObject({
      ok: false,
      error: { code: 'override_evidence_mismatch' },
    });
  });

  it('lets existing credit balance offset the new charge before enforcing the limit', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      receivable_balance: '0.000',
      credit_balance: '100.000',
      credit_limit: '500.000',
      charge_amount: '550.000',
    }));

    expect(result).toMatchObject({
      ok: true,
      decision: {
        credit_available_before: '500.000',
        credit_available_after: '50.000',
        limit_exceeded: false,
      },
    });
  });

  it('supports two-decimal currency scales with exact minor-unit math', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      receivable_balance: '10.25',
      credit_balance: '0.25',
      credit_limit: '50.00',
      charge_amount: '19.75',
      currency_scale: 2,
    }));

    expect(result).toMatchObject({
      ok: true,
      decision: {
        credit_available_before: '40.00',
        credit_available_after: '20.25',
        credit_limit: '50.00',
      },
    });
  });

  it('supports zero-decimal currency scales with exact minor-unit math', () => {
    const result = evaluateAccountChargeCreditDecision(makeInput({
      receivable_balance: '10',
      credit_balance: '3',
      credit_limit: '50',
      charge_amount: '20',
      currency_scale: 0,
    }));

    expect(result).toMatchObject({
      ok: true,
      decision: {
        credit_available_before: '43',
        credit_available_after: '23',
        credit_limit: '50',
      },
    });
  });
});
