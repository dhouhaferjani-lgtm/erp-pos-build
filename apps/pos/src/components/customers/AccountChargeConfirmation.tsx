import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { usePaymentStore } from '@/stores/paymentStore';
import { formatCurrency, getCurrencyDecimals } from '@/lib/currency';
import { bcadd } from '@/lib/decimal';
import {
  evaluateAccountChargeCreditDecision,
  type AccountChargeRejectionCode,
} from '@/lib/accountCharge/creditRulesEngine';
import type { AccountChargeOverrideApprovalInput } from '@/lib/accountCharge/accountChargeService';
import { ManagerPinPanel } from '@/components/pos/molecules/ManagerPinPanel';
import { fetchAuthorizedManagers, type AuthorizedManager } from '@/api/managersApi';
import { verifyManagerPin } from '@/api/managerPinApi';

const OVERRIDE_SCOPE: Partial<
  Record<AccountChargeRejectionCode, AccountChargeOverrideApprovalInput['approvalScope']>
> = {
  credit_limit_exceeded: 'credit_limit_override',
  account_suspended: 'account_status_override',
  account_disputed: 'account_status_override',
};

const REJECTION_DEFAULT: Record<AccountChargeRejectionCode, string> = {
  customer_tenant_mismatch: 'This customer belongs to a different tenant.',
  customer_company_mismatch: 'This customer belongs to a different company.',
  customer_inactive: 'This customer account is inactive.',
  account_suspended: 'This account is suspended. A manager can authorize an override.',
  account_closed: 'This account is closed and cannot be charged.',
  account_disputed: 'This account is in dispute. A manager can authorize an override.',
  charge_account_disabled: 'Charge-to-account is not enabled for this customer.',
  charge_policy_missing: 'No charge policy is configured for this customer.',
  balance_snapshot_missing: 'The customer balance is unavailable. Please refresh.',
  balance_snapshot_invalid: 'The customer balance is invalid. Please refresh.',
  balance_snapshot_hard_stale: 'The customer balance is too out of date. Please refresh.',
  customer_alias_ambiguous: 'This customer could not be uniquely identified.',
  money_scale_invalid: 'The amounts could not be validated.',
  credit_limit_exceeded: 'This charge exceeds the credit limit. A manager can authorize an override.',
  override_evidence_mismatch: 'The override authorization did not match this charge.',
};

const SCALE_DEFAULT = 2;

function toScale(decimals: number): 0 | 2 | 3 {
  if (decimals === 0) return 0;
  if (decimals === 3) return 3;
  return SCALE_DEFAULT;
}

export interface AccountChargeConfirmationProps {
  total: string;
  currency: string;
  cashierUserId: string;
  onConfirm: (overrideApproval: AccountChargeOverrideApprovalInput | null) => Promise<void>;
  onCancel: () => void;
  isProcessing: boolean;
  now?: () => Date;
}

export function AccountChargeConfirmation(props: AccountChargeConfirmationProps) {
  const { t } = useTranslation('pos');
  const customer = usePaymentStore((s) => s.selectedCustomer);
  const now = props.now ?? (() => new Date());
  const scale = toScale(getCurrencyDecimals(props.currency));

  const [managers, setManagers] = useState<AuthorizedManager[]>([]);
  const [throttle, setThrottle] = useState<{ until: string | null; failedAttempts: number }>({
    until: null,
    failedAttempts: 0,
  });
  const [override, setOverride] = useState<AccountChargeOverrideApprovalInput | null>(null);

  const decision = useMemo(() => {
    if (!customer) return null;
    return evaluateAccountChargeCreditDecision({
      tenant_id: customer.tenant_id,
      company_id: customer.company_id,
      expected_tenant_id: customer.tenant_id,
      expected_company_id: customer.company_id,
      customer_id: customer.id,
      customer_sync_status: customer.customer_sync_status,
      alias_candidates: [],
      is_active: customer.is_active,
      account_status: customer.account_status,
      charge_account_enabled: customer.charge_account_enabled,
      charge_policy_version: customer.charge_policy_version,
      receivable_balance: customer.receivable_balance,
      credit_balance: customer.credit_balance,
      credit_limit: customer.credit_limit,
      charge_amount: props.total,
      currency_scale: scale,
      balance_updated_at: customer.balance_updated_at,
      now: now(),
      hard_stale_after_minutes: 240,
      override_evidence: null,
    });
    // `now` is intentionally read each render via now(); excluded from deps to avoid loops.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customer, props.total, scale]);

  const rejection: AccountChargeRejectionCode | null =
    decision && !decision.ok ? decision.error.code : null;
  const overridableScope = rejection ? OVERRIDE_SCOPE[rejection] ?? null : null;
  const isStale = rejection === 'balance_snapshot_hard_stale';

  useEffect(() => {
    if (overridableScope !== null && managers.length === 0) {
      void fetchAuthorizedManagers()
        .then(setManagers)
        .catch(() => setManagers([]));
    }
  }, [overridableScope, managers.length]);

  // A captured override is scope-only and not amount-bound server-side, so a
  // stale override could confirm a larger charge than was approved. Clear it
  // whenever the charge amount or the attached customer changes.
  useEffect(() => {
    setOverride(null);
  }, [props.total, customer?.id]);

  const confirmable = decision?.ok === true || override !== null;

  const handlePinSuccess = (supervisorUserId: string, supervisorName: string): void => {
    if (!overridableScope || !rejection) return;
    const at = now();
    setOverride({
      approvalId: crypto.randomUUID(),
      approvalScope: overridableScope,
      cashierUserId: props.cashierUserId,
      reasonCode: rejection,
      reasonText: null,
      requestedAtDevice: at,
      resolvedAtDevice: at,
      supervisorUserId,
      supervisorUserSnapshot: { name: supervisorName },
    });
  };

  if (!customer) {
    return (
      <div
        className="flex h-[28rem] w-[26rem] flex-col items-center justify-center rounded-lg border border-gray-200 bg-white p-6"
        data-testid="account-charge-confirmation"
      >
        <p className="text-sm text-gray-600">
          {t('account_charge.no_customer', {
            defaultValue: 'Attach a customer before charging to account.',
          })}
        </p>
        <button
          type="button"
          onClick={props.onCancel}
          className="mt-4 rounded-md border border-gray-300 px-4 py-2 text-sm"
        >
          {t('account_charge.cancel', { defaultValue: 'Cancel' })}
        </button>
      </div>
    );
  }

  const fmt = (value: string): string => formatCurrency(value, props.currency, scale);

  const chargeAmountDisplay = fmt(props.total);
  const currentReceivable = fmt(customer.receivable_balance);
  const projectedReceivable = fmt(bcadd(customer.receivable_balance, props.total, scale));
  const creditLimitDisplay = customer.credit_limit !== null ? fmt(customer.credit_limit) : '—';
  const rawCreditBefore = decision?.ok === true ? decision.decision.credit_available_before : null;
  const rawCreditAfter = decision?.ok === true ? decision.decision.credit_available_after : null;
  const creditBefore = rawCreditBefore !== null ? fmt(rawCreditBefore) : null;
  const creditAfter = rawCreditAfter !== null ? fmt(rawCreditAfter) : null;
  const dueTermsDays = customer.payment_terms_days;

  return (
    <div
      className="flex h-[34rem] w-[26rem] flex-col rounded-lg border border-gray-200 bg-white p-6"
      data-testid="account-charge-confirmation"
    >
      <h2 className="text-lg font-semibold text-gray-900">
        {t('account_charge.title', { defaultValue: 'Charge to account' })}
      </h2>

      <div className="mt-4 flex-1 space-y-4 overflow-y-auto">
        <div>
          <p className="text-xs uppercase tracking-wide text-gray-500">
            {t('account_charge.customer', { defaultValue: 'Customer' })}
          </p>
          <p className="text-base font-medium text-gray-900">{customer.name}</p>
        </div>

        <div className="flex items-center justify-between">
          <span className="text-sm text-gray-600">
            {t('account_charge.charge_amount', { defaultValue: 'Charge amount' })}
          </span>
          <span className="text-base font-semibold text-gray-900">
            {chargeAmountDisplay}
          </span>
        </div>

        <div className="rounded-md bg-gray-50 p-3">
          <p className="text-xs uppercase tracking-wide text-gray-500">
            {t('account_charge.receivable_balance', { defaultValue: 'Receivable balance' })}
          </p>
          <div className="mt-1 flex items-center justify-between text-sm text-gray-900">
            <span data-testid="receivable-current">
              {t('account_charge.current', { defaultValue: 'Current' })}: {currentReceivable}
            </span>
            <span aria-hidden className="px-2 text-gray-400">
              →
            </span>
            <span data-testid="receivable-projected" className="font-semibold">
              {t('account_charge.projected', { defaultValue: 'Projected' })}: {projectedReceivable}
            </span>
          </div>
        </div>

        <div className="rounded-md bg-gray-50 p-3">
          <div className="flex items-center justify-between text-sm text-gray-900">
            <span>
              {t('account_charge.credit_limit', { defaultValue: 'Credit limit' })}
            </span>
            <span className="font-semibold" data-testid="credit-limit">
              {creditLimitDisplay}
            </span>
          </div>
          {creditBefore !== null && creditAfter !== null && (
            <div className="mt-1 flex items-center justify-between text-sm text-gray-700">
              <span data-testid="credit-available-before">
                {t('account_charge.available_before', { defaultValue: 'Available' })}: {creditBefore}
              </span>
              <span aria-hidden className="px-2 text-gray-400">
                →
              </span>
              <span data-testid="credit-available-after" className="font-semibold">
                {t('account_charge.available_after', { defaultValue: 'After' })}: {creditAfter}
              </span>
            </div>
          )}
        </div>

        {dueTermsDays !== null && (
          <div className="flex items-center justify-between text-sm text-gray-600">
            <span>{t('account_charge.payment_terms', { defaultValue: 'Payment terms' })}</span>
            <span data-testid="payment-terms">
              {t('account_charge.terms_days', {
                defaultValue: '{{days}} days',
                days: dueTermsDays,
              })}
            </span>
          </div>
        )}

        {isStale && (
          <p data-testid="staleness-warning" className="text-sm text-amber-700">
            {t('account_charge.stale_warning', {
              defaultValue: 'The customer balance is out of date. Please refresh before charging.',
            })}
          </p>
        )}

        {rejection !== null && !isStale && override === null && (
          <div className="space-y-3">
            <p
              data-testid="rejection-message"
              className={overridableScope !== null ? 'text-sm text-amber-700' : 'text-sm text-red-600'}
            >
              {t(`account_charge.reject.${rejection}`, {
                defaultValue: REJECTION_DEFAULT[rejection],
              })}
            </p>

            {overridableScope !== null && (
              <ManagerPinPanel
                authorizedManagers={managers}
                excludeUserId={props.cashierUserId}
                onVerify={verifyManagerPin}
                onSuccess={handlePinSuccess}
                throttle={throttle}
                onThrottleUpdate={setThrottle}
                disabled={props.isProcessing}
              />
            )}
          </div>
        )}

        {override !== null && (
          <p data-testid="override-captured" className="text-sm text-green-700">
            {t('account_charge.override_captured', {
              defaultValue: 'Override authorized by a manager.',
            })}
          </p>
        )}
      </div>

      <div className="mt-4 flex gap-3">
        <button
          type="button"
          onClick={props.onCancel}
          disabled={props.isProcessing}
          className="flex-1 rounded-md border border-gray-300 py-2 text-sm text-gray-700 disabled:opacity-50"
        >
          {t('account_charge.cancel', { defaultValue: 'Cancel' })}
        </button>
        <button
          type="button"
          onClick={() => void props.onConfirm(override)}
          disabled={!confirmable || props.isProcessing}
          data-testid="account-charge-confirm"
          className="flex-1 rounded-md bg-blue-600 py-2 text-sm font-medium text-white disabled:bg-gray-400"
        >
          {t('account_charge.confirm', { defaultValue: 'Charge to account' })}
        </button>
      </div>
    </div>
  );
}
