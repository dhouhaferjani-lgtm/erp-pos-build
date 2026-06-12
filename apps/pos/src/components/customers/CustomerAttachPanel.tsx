import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { User, UserPlus, Wallet, X } from 'lucide-react';
import { getDatabase } from '@/lib/db';
import { enqueuePendingCustomer } from '@/lib/db/repositories/pendingCustomerRepository';
import { isBalanceStale } from '@/lib/db/repositories/customerRepository';
import { usePaymentStore, type AttachedCheckoutCustomer } from '@/stores/paymentStore';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { CustomerBalanceBadge } from './CustomerBalanceBadge';
import { CustomerSearchInput } from './CustomerSearchInput';
import { CUSTOMER_ATTACH_SCOPE_ERROR, deterministicPendingCustomerUuid } from './customerAttachUtils';

export interface CustomerAttachPanelProps {
  tenantId: string | null | undefined;
  companyId: string | null | undefined;
  terminalId?: string | null | undefined;
  staleThresholdMinutes?: number;
  now?: () => Date;
  onAccountPaymentComplete?: () => void;
}

function fromMirror(row: CustomerMirrorRow): AttachedCheckoutCustomer {
  return {
    id: row.id,
    tenant_id: row.tenant_id,
    company_id: row.company_id,
    name: row.name,
    phone: row.phone,
    email: row.email,
    tax_number: row.tax_number,
    customer_category: row.customer_category,
    receivable_balance: row.receivable_balance,
    credit_balance: row.credit_balance,
    credit_limit: row.credit_limit,
    payment_terms_days: row.payment_terms_days,
    charge_account_enabled: row.charge_account_enabled,
    charge_policy_version: row.charge_policy_version,
    account_status: row.account_status,
    account_status_changed_at: row.account_status_changed_at,
    account_status_reason: row.account_status_reason,
    account_status_version: row.account_status_version,
    balance_updated_at: row.balance_updated_at,
    is_active: row.is_active,
    customer_sync_status: 'synced',
  };
}

export function CustomerAttachPanel({
  tenantId,
  companyId,
  terminalId,
  staleThresholdMinutes = 30,
  now = () => new Date(),
  onAccountPaymentComplete,
}: CustomerAttachPanelProps) {
  const { t } = useTranslation('pos');
  const selectedCustomer = usePaymentStore((state) => state.selectedCustomer);
  const attachCustomer = usePaymentStore((state) => state.attachCustomer);
  const detachCustomer = usePaymentStore((state) => state.detachCustomer);
  const processAccountPayment = usePaymentStore((state) => state.processAccountPayment);
  const isProcessing = usePaymentStore((state) => state.isProcessing);
  const [newName, setNewName] = useState('');
  const [newPhone, setNewPhone] = useState('');
  const [newEmail, setNewEmail] = useState('');
  const [accountPaymentAmount, setAccountPaymentAmount] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const handleAttach = (row: CustomerMirrorRow) => {
    if (!tenantId || !companyId) {
      setError(CUSTOMER_ATTACH_SCOPE_ERROR);
      return;
    }
    if (row.tenant_id !== tenantId || row.company_id !== companyId) {
      setError(t('customerAttach.errorWrongScope'));
      return;
    }
    attachCustomer(fromMirror(row));
    setError(null);
  };

  const handleCreate = async () => {
    const name = newName.trim();
    const phone = newPhone.trim();
    const email = newEmail.trim();

    if (!tenantId || !companyId) {
      setError(CUSTOMER_ATTACH_SCOPE_ERROR);
      return;
    }
    if (name === '') {
      setError(t('customerAttach.errorNameRequired'));
      return;
    }
    if (phone === '' && email === '') {
      setError(t('customerAttach.errorPhoneOrEmailRequired'));
      return;
    }

    setCreating(true);
    setError(null);
    try {
      const clientCustomerUuid = deterministicPendingCustomerUuid({
        tenantId,
        companyId,
        name,
        phone: phone || null,
        email: email || null,
      });
      const timestamp = now().toISOString();
      const db = await getDatabase(companyId);
      await enqueuePendingCustomer(db, {
        client_customer_uuid: clientCustomerUuid,
        tenant_id: tenantId,
        company_id: companyId,
        name,
        phone: phone || null,
        email: email || null,
        now: timestamp,
      });
      attachCustomer({
        id: clientCustomerUuid,
        tenant_id: tenantId,
        company_id: companyId,
        name,
        phone: phone || null,
        email: email || null,
        tax_number: null,
        customer_category: 'retail',
        receivable_balance: '0.000',
        credit_balance: '0.000',
        credit_limit: null,
        payment_terms_days: null,
        charge_account_enabled: false,
        charge_policy_version: null,
        account_status: 'active',
        account_status_changed_at: null,
        account_status_reason: null,
        account_status_version: 1,
        balance_updated_at: null,
        is_active: 1,
        customer_sync_status: 'pending_create',
      });
      setNewName('');
      setNewPhone('');
      setNewEmail('');
    } catch (createError) {
      setError(createError instanceof Error ? createError.message : t('customerAttach.errorCreateFailed'));
    } finally {
      setCreating(false);
    }
  };

  const stale = selectedCustomer
    ? isBalanceStale(
      {
        ...selectedCustomer,
        is_active: 1,
        sync_version: null,
        updated_at: null,
        synced_at: selectedCustomer.balance_updated_at ?? now().toISOString(),
      },
      now(),
      staleThresholdMinutes,
    )
    : false;

  const handleAccountPayment = async () => {
    const amount = accountPaymentAmount.trim();
    if (!terminalId) {
      setError(t('customerAttach.errorTerminalRequired'));
      return;
    }
    if (amount === '') {
      setError(t('customerAttach.errorAmountRequired'));
      return;
    }

    setError(null);
    try {
      const result = await processAccountPayment(terminalId, amount, {
        balanceSnapshotStale: stale,
      });
      if (result !== null) {
        setAccountPaymentAmount('');
        onAccountPaymentComplete?.();
      }
    } catch (paymentError) {
      setError(paymentError instanceof Error ? paymentError.message : t('customerAttach.errorPaymentFailed'));
    }
  };

  return (
    <section className="border-b border-gray-200 bg-white px-3 py-2">
      <div className="mb-2 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <User className="h-4 w-4 text-gray-600" aria-hidden="true" />
          <h3 className="text-sm font-semibold text-gray-900">{t('customerAttach.title')}</h3>
        </div>
        {selectedCustomer && (
          <span className="rounded-md bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">
            {t('customerAttach.attached')}
          </span>
        )}
      </div>

      {selectedCustomer ? (
        <div className="space-y-2 rounded-md border border-gray-200 bg-gray-50 p-2">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="truncate text-sm font-semibold text-gray-900">{selectedCustomer.name}</div>
              <div className="truncate text-xs text-gray-500">
                {[selectedCustomer.phone, selectedCustomer.email].filter(Boolean).join(' | ')}
              </div>
            </div>
            <button
              type="button"
              onClick={detachCustomer}
              aria-label={t('customerAttach.detachLabel')}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-200 hover:text-gray-900"
            >
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          </div>
          <CustomerBalanceBadge
            receivableBalance={selectedCustomer.receivable_balance}
            creditBalance={selectedCustomer.credit_balance}
            balanceUpdatedAt={selectedCustomer.balance_updated_at}
            stale={stale}
          />
          <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
            <input
              aria-label={t('customerAttach.accountPaymentAmountLabel')}
              value={accountPaymentAmount}
              onChange={(event) => setAccountPaymentAmount(event.target.value)}
              inputMode="decimal"
              placeholder={t('customerAttach.accountPaymentAmountPlaceholder')}
              className="min-w-0 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <button
              type="button"
              onClick={() => void handleAccountPayment()}
              disabled={isProcessing}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              <Wallet className="h-4 w-4" aria-hidden="true" />
              {t('customerAttach.recordButton')}
            </button>
          </div>
        </div>
      ) : (
        <div className="grid gap-2">
          <CustomerSearchInput
            tenantId={tenantId}
            companyId={companyId}
            onSelect={handleAttach}
          />
          <div className="grid grid-cols-1 gap-2">
            <input
              aria-label={t('customerAttach.newNameLabel')}
              value={newName}
              onChange={(event) => setNewName(event.target.value)}
              placeholder={t('customerAttach.newNamePlaceholder')}
              className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <input
              aria-label={t('customerAttach.newPhoneLabel')}
              value={newPhone}
              onChange={(event) => setNewPhone(event.target.value)}
              placeholder={t('customerAttach.newPhonePlaceholder')}
              className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <input
              aria-label={t('customerAttach.newEmailLabel')}
              value={newEmail}
              onChange={(event) => setNewEmail(event.target.value)}
              placeholder={t('customerAttach.newEmailPlaceholder')}
              className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <button
            type="button"
            onClick={() => void handleCreate()}
            disabled={creating}
            className="inline-flex items-center justify-center gap-2 rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <UserPlus className="h-4 w-4" aria-hidden="true" />
            {creating ? t('customerAttach.creating') : t('customerAttach.createButton')}
          </button>
        </div>
      )}

      {error && <div className="mt-2 text-xs font-medium text-red-700">{error}</div>}
    </section>
  );
}
