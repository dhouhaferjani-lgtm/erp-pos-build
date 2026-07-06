import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { User, UserPlus, Wallet, X } from 'lucide-react';
import { getDatabase } from '@/lib/db';
import { createPendingCustomer } from '@/lib/customer/pendingCustomerCreateService';
import { isBalanceStale } from '@/lib/db/repositories/customerRepository';
import { usePaymentStore, type AttachedCheckoutCustomer } from '@/stores/paymentStore';
import { cn } from '@/lib/utils';
import { tokens } from '@/lib/designTokens';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { CustomerBalanceBadge } from './CustomerBalanceBadge';
import { CustomerSearchInput } from './CustomerSearchInput';
import { deterministicPendingCustomerUuid } from './customerAttachUtils';

export interface CustomerAttachPanelProps {
  tenantId: string | null | undefined;
  companyId: string | null | undefined;
  terminalId?: string | null | undefined;
  staleThresholdMinutes?: number;
  now?: () => Date;
  onAccountPaymentComplete?: () => void;
}

export interface CustomerAttachBodyProps {
  tenantId: string | null | undefined;
  companyId: string | null | undefined;
  terminalId?: string | null | undefined;
  staleThresholdMinutes?: number;
  now?: () => Date;
  onAccountPaymentComplete?: () => void;
  /** Called with true before account-payment await, false after (success or failure). */
  onProcessingChange?: (processing: boolean) => void;
  /** Called after a customer is attached (search-select or create-local success). */
  onSelected?: () => void;
  /**
   * When true (default) the body renders its own "Customer" heading + icon.
   * Set false when hosted inside a Modal whose title bar already shows it,
   * to avoid the duplicate heading.
   */
  showHeading?: boolean;
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

/**
 * The extracted body of the customer attach panel — renders search/create/selected
 * states. Accepts optional lifecycle callbacks so it can be hosted inside a Modal
 * without changing any attach/create/account-payment logic.
 *
 * `CustomerAttachPanel` wraps this body with a section container for inline use.
 */
export function CustomerAttachBody({
  tenantId,
  companyId,
  terminalId,
  staleThresholdMinutes = 30,
  now = () => new Date(),
  onAccountPaymentComplete,
  onProcessingChange,
  onSelected,
  showHeading = true,
}: CustomerAttachBodyProps) {
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
      setError(t('customer.scopeError'));
      return;
    }
    if (row.tenant_id !== tenantId || row.company_id !== companyId) {
      setError(t('customer.differentScope'));
      return;
    }
    attachCustomer(fromMirror(row));
    setError(null);
    onSelected?.();
  };

  const handleCreate = async () => {
    const name = newName.trim();
    const phone = newPhone.trim();
    const email = newEmail.trim();

    if (!tenantId || !companyId) {
      setError(t('customer.scopeError'));
      return;
    }
    if (name === '') {
      setError(t('customer.nameRequired'));
      return;
    }
    if (phone === '' && email === '') {
      setError(t('customer.contactRequired'));
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
      // T-0001: writes the outbox row AND an optimistic `customers` mirror
      // row so local search/list see the new customer immediately.
      await createPendingCustomer(db, {
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
      onSelected?.();
    } catch (createError) {
      setError(createError instanceof Error ? createError.message : t('customer.createFailed'));
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
        // Task 21 — skin fields are not part of AttachedCheckoutCustomer;
        // default to null when projecting onto CustomerMirrorRow for staleness check.
        skin_type: null,
        skin_advice_note: null,
      },
      now(),
      staleThresholdMinutes,
    )
    : false;

  const handleAccountPayment = async () => {
    const amount = accountPaymentAmount.trim();
    if (!terminalId) {
      setError(t('customer.terminalRequired'));
      return;
    }
    if (amount === '') {
      setError(t('customer.amountRequired'));
      return;
    }

    setError(null);
    onProcessingChange?.(true);
    try {
      const result = await processAccountPayment(terminalId, amount, {
        balanceSnapshotStale: stale,
      });
      if (result !== null) {
        setAccountPaymentAmount('');
        onAccountPaymentComplete?.();
      }
    } catch (paymentError) {
      setError(paymentError instanceof Error ? paymentError.message : t('customer.paymentFailed'));
    } finally {
      onProcessingChange?.(false);
    }
  };

  return (
    <>
      {(showHeading || selectedCustomer) && (
        <div className="mb-2 flex items-center justify-between gap-2">
          {showHeading ? (
            <div className="flex items-center gap-2">
              <User className="h-4 w-4 text-ink-muted" aria-hidden="true" />
              <h3 className="text-sm font-semibold text-ink">{t('customer.attach')}</h3>
            </div>
          ) : (
            <span />
          )}
          {selectedCustomer && <span className={tokens.badge.success}>{t('customer.attached')}</span>}
        </div>
      )}

      {selectedCustomer ? (
        <div className="space-y-2 rounded-md border border-border-subtle bg-surface-sunken p-2">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <div className="truncate text-sm font-semibold text-ink">{selectedCustomer.name}</div>
              <div className="truncate text-xs text-ink-faint">
                {[selectedCustomer.phone, selectedCustomer.email].filter(Boolean).join(' | ')}
              </div>
            </div>
            <button
              type="button"
              onClick={detachCustomer}
              aria-label={t('customer.detach')}
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-ink-faint hover:bg-surface-sunken hover:text-ink"
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
              aria-label={t('customer.amount')}
              value={accountPaymentAmount}
              onChange={(event) => setAccountPaymentAmount(event.target.value)}
              inputMode="decimal"
              placeholder={t('customer.amount')}
              className="min-w-0 rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
            />
            <button
              type="button"
              onClick={() => void handleAccountPayment()}
              disabled={isProcessing}
              className={cn(tokens.button.primary, 'px-3 py-2 text-sm')}
            >
              <Wallet className="h-4 w-4" aria-hidden="true" />
              {t('customer.record')}
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
              aria-label={t('customer.name')}
              value={newName}
              onChange={(event) => setNewName(event.target.value)}
              placeholder={t('customer.name')}
              className="rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
            />
            <input
              aria-label={t('customer.phone')}
              value={newPhone}
              onChange={(event) => setNewPhone(event.target.value)}
              placeholder={t('customer.phone')}
              className="rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
            />
            <input
              aria-label={t('customer.email')}
              value={newEmail}
              onChange={(event) => setNewEmail(event.target.value)}
              placeholder={t('customer.email')}
              className="rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-1 focus:ring-action"
            />
          </div>
          <button
            type="button"
            onClick={() => void handleCreate()}
            disabled={creating}
            className={cn(tokens.button.secondary, 'px-3 py-2 text-sm')}
          >
            <UserPlus className="h-4 w-4" aria-hidden="true" />
            {creating ? t('customer.creating') : t('customer.createLocal')}
          </button>
        </div>
      )}

      {error && <div className="mt-2 text-xs font-medium text-danger-strong">{error}</div>}
    </>
  );
}

export function CustomerAttachPanel({
  tenantId,
  companyId,
  terminalId,
  staleThresholdMinutes = 30,
  now = () => new Date(),
  onAccountPaymentComplete,
}: CustomerAttachPanelProps) {
  return (
    <section className="border-b border-border-subtle bg-surface-raised px-3 py-2">
      <CustomerAttachBody
        tenantId={tenantId}
        companyId={companyId}
        terminalId={terminalId}
        staleThresholdMinutes={staleThresholdMinutes}
        now={now}
        onAccountPaymentComplete={onAccountPaymentComplete}
      />
    </section>
  );
}
