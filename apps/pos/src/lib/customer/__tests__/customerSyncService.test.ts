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
      synced_at: SERVER_SYNCED_AT,
    });

    const count = await pullCustomers(db, TENANT_ID, COMPANY_ID);

    expect(count).toBe(1);
    expect(apiGet).toHaveBeenCalledWith('/pos/customers/sync', {
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
      synced_at: SERVER_SYNCED_AT,
    });

    const count = await pullCustomers(db, TENANT_ID, COMPANY_ID);

    expect(count).toBe(0);
    expect(apiGet).toHaveBeenCalledWith('/pos/customers/sync', {});
    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).toHaveBeenCalledWith(
      db,
      'customers.updated_since',
      SERVER_SYNCED_AT,
    );
  });

  it('fails loudly when the server returns a row for another tenant or company', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      customers: [customer({ company_id: 'company-2' })],
      synced_at: SERVER_SYNCED_AT,
    });

    await expect(pullCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      CustomerSyncScopeError,
    );

    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('fails loudly when the server omits the customers array', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
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
    });

    await expect(pullCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      CustomerSyncResponseError,
    );

    expect(upsertCustomer).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });
});
