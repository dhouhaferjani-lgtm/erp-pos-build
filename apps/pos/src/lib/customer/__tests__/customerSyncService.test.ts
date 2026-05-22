import { describe, it, expect, vi, beforeEach } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';
import type { CustomerMirrorRow } from '../customerTypes';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
}));

vi.mock('@/lib/db/repositories/customerRepository', () => ({
  upsertCustomer: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
}));

import { apiGet } from '@/lib/api';
import { upsertCustomer } from '@/lib/db/repositories/customerRepository';
import {
  getSyncMetadata,
  setSyncMetadata,
} from '@/lib/db/repositories/syncLogRepository';
import {
  CustomerSyncResponseError,
  CustomerSyncScopeError,
  pullCustomers,
} from '../customerSyncService';

const db = {} as Database;
const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';
const SERVER_SYNCED_AT = '2026-05-21T11:00:00.000Z';

function customer(overrides: Partial<CustomerMirrorRow> = {}): CustomerMirrorRow {
  return {
    id: 'customer-1',
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    name: 'Sarah Ben Ali',
    phone: '+216 20 100 200',
    email: 'sarah@example.test',
    tax_number: 'TN1234567A',
    customer_category: 'individual',
    receivable_balance: '100.0000',
    credit_balance: '0.0000',
    credit_limit: '500.0000',
    payment_terms_days: 15,
    charge_account_enabled: true,
    charge_policy_version: 'phase3-v1',
    balance_updated_at: '2026-05-21T10:45:00.000Z',
    is_active: 1,
    sync_version: '2026-05-21T10:50:00.000Z',
    updated_at: '2026-05-21T10:50:00.000Z',
    synced_at: SERVER_SYNCED_AT,
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSyncMetadata).mockResolvedValue(null);
});

describe('pullCustomers', () => {
  it('pulls customers, validates tenant/company, upserts rows, and stores the cursor', async () => {
    vi.mocked(getSyncMetadata).mockResolvedValueOnce('2026-05-21T10:00:00.000Z');
    const row = customer();
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [row],
      has_more: false,
      next_updated_since: null,
      next_updated_since_id: null,
      synced_at: SERVER_SYNCED_AT,
    });

    const count = await pullCustomers(db, TENANT_ID, COMPANY_ID);

    expect(count).toBe(1);
    expect(apiGet).toHaveBeenCalledWith('/pos/customers/sync', {
      limit: '100',
      updated_since: '2026-05-21T10:00:00.000Z',
    });
    expect(upsertCustomer).toHaveBeenCalledWith(db, row);
    expect(setSyncMetadata).toHaveBeenCalledWith(
      db,
      'customers.updated_since',
      SERVER_SYNCED_AT,
    );
  });

  it('omits updated_since on the first pull and still stores the server cursor', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [],
      has_more: false,
      next_updated_since: null,
      next_updated_since_id: null,
      synced_at: SERVER_SYNCED_AT,
    });

    const count = await pullCustomers(db, TENANT_ID, COMPANY_ID);

    expect(count).toBe(0);
    expect(apiGet).toHaveBeenCalledWith('/pos/customers/sync', {
      limit: '100',
    });
    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).toHaveBeenCalledWith(
      db,
      'customers.updated_since',
      SERVER_SYNCED_AT,
    );
  });

  it('passes phase three credit fields through to the local mirror repository', async () => {
    const row = customer({
      credit_limit: '750.0000',
      payment_terms_days: 30,
      charge_account_enabled: false,
      charge_policy_version: 'phase3-custom-v2',
    });
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [row],
      has_more: false,
      next_updated_since: null,
      next_updated_since_id: null,
      synced_at: SERVER_SYNCED_AT,
    });

    await pullCustomers(db, TENANT_ID, COMPANY_ID);

    expect(upsertCustomer).toHaveBeenCalledWith(db, expect.objectContaining({
      credit_limit: '750.0000',
      payment_terms_days: 30,
      charge_account_enabled: false,
      charge_policy_version: 'phase3-custom-v2',
    }));
  });

  it('fails loudly when the server returns a row for another tenant or company', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [customer({ company_id: 'company-2' })],
      has_more: false,
      next_updated_since: null,
      next_updated_since_id: null,
      synced_at: SERVER_SYNCED_AT,
    });

    await expect(pullCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      CustomerSyncScopeError,
    );

    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('validates the full page before writing any row', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [
        customer({ id: 'customer-1' }),
        customer({ id: 'customer-2', tenant_id: 'tenant-2' }),
      ],
      has_more: false,
      next_updated_since: null,
      next_updated_since_id: null,
      synced_at: SERVER_SYNCED_AT,
    });

    await expect(pullCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      CustomerSyncScopeError,
    );

    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('pulls every page before advancing the stored cursor', async () => {
    vi.mocked(getSyncMetadata).mockResolvedValueOnce('2026-05-21T09:00:00.000Z');
    const firstPageRow = customer({
      id: 'customer-1',
      updated_at: '2026-05-21T10:00:00.000Z',
      sync_version: '2026-05-21T10:00:00.000Z',
    });
    const secondPageRow = customer({
      id: 'customer-2',
      updated_at: '2026-05-21T10:00:00.000Z',
      sync_version: '2026-05-21T10:00:00.000Z',
    });

    vi.mocked(apiGet)
      .mockResolvedValueOnce({
        customers: [firstPageRow],
        has_more: true,
        next_updated_since: '2026-05-21T10:00:00.000Z',
        next_updated_since_id: 'customer-1',
        synced_at: '2026-05-21T11:00:00.000Z',
      })
      .mockResolvedValueOnce({
        customers: [secondPageRow],
        has_more: false,
        next_updated_since: null,
        next_updated_since_id: null,
        synced_at: '2026-05-21T11:00:01.000Z',
      });

    const count = await pullCustomers(db, TENANT_ID, COMPANY_ID);

    expect(count).toBe(2);
    expect(apiGet).toHaveBeenNthCalledWith(1, '/pos/customers/sync', {
      limit: '100',
      updated_since: '2026-05-21T09:00:00.000Z',
    });
    expect(apiGet).toHaveBeenNthCalledWith(2, '/pos/customers/sync', {
      limit: '100',
      updated_since: '2026-05-21T10:00:00.000Z',
      updated_since_id: 'customer-1',
    });
    expect(upsertCustomer).toHaveBeenNthCalledWith(1, db, firstPageRow);
    expect(upsertCustomer).toHaveBeenNthCalledWith(2, db, secondPageRow);
    expect(setSyncMetadata).toHaveBeenCalledTimes(1);
    expect(setSyncMetadata).toHaveBeenCalledWith(
      db,
      'customers.updated_since',
      '2026-05-21T11:00:01.000Z',
    );
  });

  it('fails loudly when the server omits the customers array', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      has_more: false,
      next_updated_since: null,
      next_updated_since_id: null,
      synced_at: SERVER_SYNCED_AT,
    });

    await expect(pullCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      CustomerSyncResponseError,
    );

    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('fails loudly when the server omits the synced_at cursor', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [customer()],
      has_more: false,
      next_updated_since: null,
      next_updated_since_id: null,
    });

    await expect(pullCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      CustomerSyncResponseError,
    );

    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('fails loudly when a partial page omits the continuation cursor', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [customer()],
      has_more: true,
      next_updated_since: null,
      next_updated_since_id: 'customer-1',
      synced_at: SERVER_SYNCED_AT,
    });

    await expect(pullCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      CustomerSyncResponseError,
    );

    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });
});
