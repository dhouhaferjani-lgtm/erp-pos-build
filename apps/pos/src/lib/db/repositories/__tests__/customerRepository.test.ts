import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import {
  CustomerCompanyDriftError,
  getCustomerById,
  isBalanceStale,
  searchCustomers,
  upsertCustomer,
} from '../customerRepository';

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
    name: 'Sarah Ben Ali',
    phone: '+216 20 100 200',
    email: 'sarah@example.test',
    tax_number: 'MF1234567A',
    customer_category: 'para-pharmacy',
    receivable_balance: '42.5000',
    credit_balance: '0.0000',
    balance_updated_at: '2026-05-21T08:00:00.000Z',
    is_active: 1,
    sync_version: 'sync-v1',
    updated_at: '2026-05-21T08:01:00.000Z',
    synced_at: '2026-05-21T08:02:00.000Z',
    ...overrides,
  };
}

describe('customerRepository', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('upserts customers by tenant, company, and id', async () => {
    await upsertCustomer(db, customer());
    await upsertCustomer(db, customer({ name: 'Sarah Updated', receivable_balance: '12.2500' }));

    const row = await getCustomerById(db, 'tenant-1', 'company-1', 'customer-1');

    expect(row).toMatchObject({
      id: 'customer-1',
      tenant_id: 'tenant-1',
      company_id: 'company-1',
      name: 'Sarah Updated',
      receivable_balance: '12.2500',
    });
  });

  it('allows the same customer id in another tenant but rejects company drift within one tenant', async () => {
    await upsertCustomer(db, customer());
    await upsertCustomer(db, customer({ tenant_id: 'tenant-2', company_id: 'company-9', name: 'Other Tenant Sarah' }));

    await expect(
      upsertCustomer(db, customer({ company_id: 'company-2' })),
    ).rejects.toBeInstanceOf(CustomerCompanyDriftError);

    expect(await getCustomerById(db, 'tenant-2', 'company-9', 'customer-1')).toMatchObject({
      tenant_id: 'tenant-2',
      company_id: 'company-9',
      name: 'Other Tenant Sarah',
    });
  });

  it('searches active customers by name, phone, and tax number within tenant and company', async () => {
    await upsertCustomer(db, customer({ id: 'active-name', name: 'Sarah Ben Ali' }));
    await upsertCustomer(db, customer({ id: 'active-phone', name: 'Karim Trabelsi', phone: '+216 55 555 555' }));
    await upsertCustomer(db, customer({ id: 'active-tax', name: 'Amel Saidi', tax_number: 'TN-998877' }));
    await upsertCustomer(db, customer({ id: 'inactive', name: 'Sarah Inactive', is_active: 0 }));
    await upsertCustomer(db, customer({ id: 'wrong-company', company_id: 'company-2', name: 'Sarah Wrong Company' }));
    await upsertCustomer(db, customer({ id: 'wrong-tenant', tenant_id: 'tenant-2', name: 'Sarah Wrong Tenant' }));

    await expect(
      searchCustomers(db, { tenant_id: 'tenant-1', company_id: 'company-1', query: 'sarah' }),
    ).resolves.toHaveLength(1);

    await expect(
      searchCustomers(db, { tenant_id: 'tenant-1', company_id: 'company-1', query: '55 555' }),
    ).resolves.toMatchObject([{ id: 'active-phone' }]);

    await expect(
      searchCustomers(db, { tenant_id: 'tenant-1', company_id: 'company-1', query: '998877' }),
    ).resolves.toMatchObject([{ id: 'active-tax' }]);
  });

  it('fails loudly when required tenant, company, or customer ids are missing', async () => {
    await expect(upsertCustomer(db, customer({ tenant_id: '' }))).rejects.toThrow('tenant_id');
    await expect(upsertCustomer(db, customer({ company_id: '' }))).rejects.toThrow('company_id');
    await expect(upsertCustomer(db, customer({ id: '' }))).rejects.toThrow('id');

    await expect(
      searchCustomers(db, { tenant_id: '', company_id: 'company-1', query: 'sarah' }),
    ).rejects.toThrow('tenant_id');
  });

  it('marks missing or expired balance snapshots as stale', () => {
    const now = new Date('2026-05-21T09:00:00.000Z');

    expect(isBalanceStale(customer({ balance_updated_at: null }), now, 30)).toBe(true);
    expect(isBalanceStale(customer({ balance_updated_at: '2026-05-21T08:20:00.000Z' }), now, 30)).toBe(true);
    expect(isBalanceStale(customer({ balance_updated_at: '2026-05-21T08:45:00.000Z' }), now, 30)).toBe(false);
  });
});
