import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getDatabase } from '@/lib/db';
import { migrations } from '@/lib/db/migrations';
import { upsertCustomer } from '@/lib/db/repositories/customerRepository';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { CustomerSearchInput } from './CustomerSearchInput';

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

describe('CustomerSearchInput', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
    vi.mocked(getDatabase).mockResolvedValue(db);
  });

  afterEach(() => {
    adapter.close();
    vi.clearAllMocks();
  });

  it('searches customers and emits the selected row', async () => {
    const onSelect = vi.fn();
    await upsertCustomer(db, customer());
    await upsertCustomer(db, customer({ id: 'other-company', company_id: 'company-2', name: 'Mariam Other' }));

    render(
      <CustomerSearchInput
        tenantId="tenant-1"
        companyId="company-1"
        onSelect={onSelect}
      />,
    );

    fireEvent.change(screen.getByLabelText('customer.searchLabel'), { target: { value: 'mariam' } });

    await screen.findByText('Mariam Ben Ali');
    expect(screen.queryByText('Mariam Other')).not.toBeInTheDocument();

    fireEvent.click(screen.getByText('Mariam Ben Ali'));

    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({
      id: 'customer-1',
      tenant_id: 'tenant-1',
      company_id: 'company-1',
    }));
  });

  it('blocks search when tenant/company are missing', async () => {
    const onSelect = vi.fn();

    render(
      <CustomerSearchInput
        tenantId={null}
        companyId="company-1"
        onSelect={onSelect}
      />,
    );

    // Measure only this test's behavior — a prior test's async search effect
    // can settle across the test boundary and pollute the shared spy.
    vi.mocked(getDatabase).mockClear();

    fireEvent.change(screen.getByLabelText('customer.searchLabel'), { target: { value: 'mariam' } });

    await waitFor(() => {
      expect(screen.getByText('customer.scopeError')).toBeInTheDocument();
    });
    expect(onSelect).not.toHaveBeenCalled();
    expect(getDatabase).not.toHaveBeenCalled();
  });
});
