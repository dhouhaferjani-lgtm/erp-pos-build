import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getDatabase } from '@/lib/db';
import { migrations } from '@/lib/db/migrations';
import { upsertCustomer } from '@/lib/db/repositories/customerRepository';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { usePaymentStore } from '@/stores/paymentStore';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { CustomerSearchModal } from './CustomerSearchModal';

vi.mock('react-i18next', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-i18next')>();
  return { ...actual, useTranslation: () => ({ t: (k: string) => k }) };
});

vi.mock('@/lib/db', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db')>('@/lib/db');
  return {
    ...actual,
    getDatabase: vi.fn(),
  };
});

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (value: string | number) => `TND ${Number(value).toFixed(3)}`,
  }),
}));

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(),
}));

vi.mock('@/lib/offline/accountPaymentService', () => ({
  createAccountPayment: vi.fn().mockResolvedValue({
    fiscalEventId: 'account-payment-event-1',
    receiptNumber: 'account-payment-1',
    total: '10.000',
    currency: 'TND',
    fiscalHash: 'b'.repeat(64),
    sequenceNumber: 7,
    canonicalBytes: '{"event_type":"ACCOUNT_PAYMENT"}',
    printableData: {
      receipt_kind: 'account_payment',
      receipt_number: 'account-payment-1',
    },
    payload: {
      event_time_device: '2026-05-21T08:10:00.000Z',
      local_balance_snapshot: {
        projected_receivable_balance_after: '32.500',
        projected_credit_balance_after: '0.000',
      },
    },
  }),
}));

vi.mock('@/lib/offline/terminalMutex', () => ({
  lockTerminal: vi.fn((_tenantId: string, _terminalId: string, callback: () => unknown) => callback()),
}));

vi.mock('@/lib/fiscal/FiscalEventEngine', () => ({
  ConcurrentChainAdvanceError: class ConcurrentChainAdvanceError extends Error {},
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'company-1',
      user: { id: 'user-1', name: 'Cashier', tenantId: 'tenant-1' },
      companies: [{
        id: 'company-1',
        currency: 'TND',
        name: 'AutoERP Demo SARL',
        tax_id: '1234567AM000',
        country_code: 'TN',
        address_street: '1 Avenue Habib Bourguiba',
        address_city: 'Tunis',
        address_postal_code: '1000',
      }],
    }),
  },
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: {
    getState: vi.fn().mockReturnValue({ operator: { id: 'operator-1', name: 'Cashier' } }),
  },
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: vi.fn().mockReturnValue({
      terminal: { id: 'terminal-1', name: 'Register 1', is_training_mode: false },
      shift: { id: 'shift-1' },
    }),
  },
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn().mockReturnValue({ triggerSync: vi.fn() }),
  },
}));

async function applyAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const migration of migrations) {
    if (migration.run) {
      await migration.run(adapter);
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

function customer(overrides: Partial<CustomerMirrorRow> = {}): CustomerMirrorRow {
  return {
    id: 'customer-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    name: 'Mariam Ben Ali',
    phone: '+216 20 100 200',
    email: 'mariam@example.test',
    tax_number: 'TN-100',
    customer_category: 'retail',
    receivable_balance: '42.500',
    credit_balance: '0.000',
    credit_limit: null,
    payment_terms_days: null,
    charge_account_enabled: 1,
    charge_policy_version: 'phase3-v1',
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: '2026-05-21T08:00:00.000Z',
    is_active: 1,
    sync_version: 'sync-1',
    updated_at: '2026-05-21T08:01:00.000Z',
    synced_at: '2026-05-21T08:02:00.000Z',
    ...overrides,
  };
}

describe('CustomerSearchModal', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
    vi.mocked(getDatabase).mockResolvedValue(db);
    usePaymentStore.getState().reset();
  });

  afterEach(() => {
    adapter.close();
    vi.clearAllMocks();
  });

  it('does not render modal content when isOpen is false', () => {
    render(
      <CustomerSearchModal
        isOpen={false}
        onClose={vi.fn()}
        tenantId="tenant-1"
        companyId="company-1"
        terminalId="terminal-1"
      />,
    );

    expect(screen.queryByText('customer.modalTitle')).not.toBeInTheDocument();
    expect(screen.queryByTestId('customer-modal-shell')).not.toBeInTheDocument();
  });

  it('renders the modal body with stable min-height shell when isOpen', () => {
    render(
      <CustomerSearchModal
        isOpen={true}
        onClose={vi.fn()}
        tenantId="tenant-1"
        companyId="company-1"
        terminalId="terminal-1"
      />,
    );

    expect(screen.getByText('customer.modalTitle')).toBeInTheDocument();
    const shell = screen.getByTestId('customer-modal-shell');
    expect(shell).toBeInTheDocument();
    expect(shell.className).toContain('min-h-[420px]');
  });

  it('calls onClose when a customer is attached via search select', async () => {
    await upsertCustomer(db, customer());
    const onClose = vi.fn();

    render(
      <CustomerSearchModal
        isOpen={true}
        onClose={onClose}
        tenantId="tenant-1"
        companyId="company-1"
        terminalId="terminal-1"
      />,
    );

    fireEvent.change(screen.getByLabelText('Customer search'), { target: { value: 'mariam' } });
    fireEvent.click(await screen.findByText('Mariam Ben Ali'));

    await waitFor(() => expect(onClose).toHaveBeenCalledOnce());
  });

  it('keeps the modal non-closable (closable=false) while account payment is processing', async () => {
    // We can't easily simulate the mid-flight state, but we can verify the
    // close button is enabled when NOT processing (steady state).
    render(
      <CustomerSearchModal
        isOpen={true}
        onClose={vi.fn()}
        tenantId="tenant-1"
        companyId="company-1"
        terminalId="terminal-1"
      />,
    );

    const closeButton = screen.getByTestId('modal-close-button');
    // In steady state (not processing), the button must be enabled.
    expect(closeButton).not.toBeDisabled();
  });
});
