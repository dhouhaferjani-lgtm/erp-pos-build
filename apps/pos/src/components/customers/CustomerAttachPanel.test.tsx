import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getDatabase } from '@/lib/db';
import { migrations } from '@/lib/db/migrations';
import { getPendingCustomers } from '@/lib/db/repositories/pendingCustomerRepository';
import { upsertCustomer } from '@/lib/db/repositories/customerRepository';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { usePaymentStore } from '@/stores/paymentStore';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { CustomerAttachPanel } from './CustomerAttachPanel';

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
        tax_id: '1234567A/A/A/000',
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
    balance_updated_at: '2026-05-21T08:00:00.000Z',
    is_active: 1,
    sync_version: 'sync-1',
    updated_at: '2026-05-21T08:01:00.000Z',
    synced_at: '2026-05-21T08:02:00.000Z',
    ...overrides,
  };
}

describe('CustomerAttachPanel', () => {
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

  it('searches customers and attaches the selected row to checkout state', async () => {
    await upsertCustomer(db, customer());

    render(
      <CustomerAttachPanel
        tenantId="tenant-1"
        companyId="company-1"
        now={() => new Date('2026-05-21T08:10:00.000Z')}
      />,
    );

    fireEvent.change(screen.getByLabelText('Customer search'), { target: { value: 'mariam' } });
    fireEvent.click(await screen.findByText('Mariam Ben Ali'));

    expect(usePaymentStore.getState().selectedCustomer).toMatchObject({
      id: 'customer-1',
      name: 'Mariam Ben Ali',
      customer_sync_status: 'synced',
    });
    expect(screen.getByText('Attached')).toBeInTheDocument();
  });

  it('creates a pending local customer with deterministic client id and outbox row', async () => {
    render(
      <CustomerAttachPanel
        tenantId="tenant-1"
        companyId="company-1"
        now={() => new Date('2026-05-21T08:10:00.000Z')}
      />,
    );

    fireEvent.change(screen.getByLabelText('New customer name'), { target: { value: 'Amina Trabelsi' } });
    fireEvent.change(screen.getByLabelText('New customer phone'), { target: { value: '+216 99 100 200' } });
    fireEvent.click(screen.getByText('Create local customer'));

    await waitFor(() => {
      expect(usePaymentStore.getState().selectedCustomer).toMatchObject({
        name: 'Amina Trabelsi',
        customer_sync_status: 'pending_create',
      });
    });

    const selected = usePaymentStore.getState().selectedCustomer;
    expect(selected?.id).toBe('5bb57558-fcb5-48b1-8575-a99da4c58149');

    await expect(getPendingCustomers(db, 'tenant-1', 'company-1')).resolves.toMatchObject([
      {
        client_customer_uuid: '5bb57558-fcb5-48b1-8575-a99da4c58149',
        name: 'Amina Trabelsi',
        phone: '+216 99 100 200',
        status: 'pending',
      },
    ]);
  });

  it('blocks attach when tenant/company are missing', async () => {
    render(
      <CustomerAttachPanel
        tenantId={null}
        companyId="company-1"
        now={() => new Date('2026-05-21T08:10:00.000Z')}
      />,
    );

    fireEvent.change(screen.getByLabelText('New customer name'), { target: { value: 'Amina Trabelsi' } });
    fireEvent.change(screen.getByLabelText('New customer phone'), { target: { value: '+216 99 100 200' } });
    fireEvent.click(screen.getByText('Create local customer'));

    await waitFor(() => {
      expect(screen.getByText('Tenant and company are required to attach a customer.')).toBeInTheDocument();
    });
    expect(usePaymentStore.getState().selectedCustomer).toBeNull();
  });

  it('rejects a customer row from another company before attach', async () => {
    await upsertCustomer(db, customer({ id: 'wrong-company', company_id: 'company-2', name: 'Wrong Company' }));

    render(
      <CustomerAttachPanel
        tenantId="tenant-1"
        companyId="company-1"
        now={() => new Date('2026-05-21T08:10:00.000Z')}
      />,
    );

    fireEvent.change(screen.getByLabelText('Customer search'), { target: { value: 'Wrong' } });

    await waitFor(() => {
      expect(screen.queryByText('Wrong Company')).not.toBeInTheDocument();
    });
    expect(usePaymentStore.getState().selectedCustomer).toBeNull();
  });

  it('detaches the selected customer before seal', async () => {
    await upsertCustomer(db, customer());

    render(
      <CustomerAttachPanel
        tenantId="tenant-1"
        companyId="company-1"
        now={() => new Date('2026-05-21T08:10:00.000Z')}
      />,
    );

    fireEvent.change(screen.getByLabelText('Customer search'), { target: { value: 'mariam' } });
    fireEvent.click(await screen.findByText('Mariam Ben Ali'));
    fireEvent.click(screen.getByLabelText('Detach customer'));

    expect(usePaymentStore.getState().selectedCustomer).toBeNull();
    expect(screen.queryByText('Attached')).not.toBeInTheDocument();
  });

  it('records an account payment for the attached customer and opens the printable success path', async () => {
    await upsertCustomer(db, customer());
    const onComplete = vi.fn();
    usePaymentStore.setState({
      paymentMethods: [{
        id: 'pm-cash',
        code: 'CASH',
        name: 'Cash',
        is_physical: true,
        has_maturity: false,
        requires_third_party: false,
        is_push: false,
        has_deducted_fees: false,
        is_restricted: false,
        fee_type: null,
        fee_fixed: '0.000',
        fee_percent: '0.000',
        restriction_type: null,
        is_active: true,
        position: 1,
      }],
      paymentRepositories: [{
        id: 'repo-cash',
        code: 'CASH',
        name: 'Drawer',
        type: 'cash_register',
        bank_name: null,
        account_number: null,
        iban: null,
        bic: null,
        balance: '0.000',
        is_active: true,
      }],
    });

    render(
      <CustomerAttachPanel
        tenantId="tenant-1"
        companyId="company-1"
        terminalId="terminal-1"
        now={() => new Date('2026-05-21T08:10:00.000Z')}
        onAccountPaymentComplete={onComplete}
      />,
    );

    fireEvent.change(screen.getByLabelText('Customer search'), { target: { value: 'mariam' } });
    fireEvent.click(await screen.findByText('Mariam Ben Ali'));
    fireEvent.change(screen.getByLabelText('Account payment amount'), { target: { value: '10.000' } });
    fireEvent.click(screen.getByText('Record'));

    await waitFor(() => expect(onComplete).toHaveBeenCalledOnce());
    expect(usePaymentStore.getState().lastReceipt?.receipt_number).toBe('account-payment-1');
    expect(usePaymentStore.getState().lastReceiptPrintData?.receipt_kind).toBe('account_payment');
    expect(usePaymentStore.getState().selectedCustomer?.receivable_balance).toBe('32.500');
  });
});
