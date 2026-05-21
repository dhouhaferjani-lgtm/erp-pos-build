import { describe, it, expect, vi, beforeEach } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';
import type { PendingCustomerRow } from '@/lib/db/repositories/pendingCustomerRepository';

vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db/repositories/pendingCustomerRepository', () => ({
  getPendingCustomers: vi.fn(),
  markPendingCustomerResolved: vi.fn().mockResolvedValue(undefined),
  storeCustomerAlias: vi.fn().mockResolvedValue(undefined),
  StaleCustomerAliasConflictError: class StaleCustomerAliasConflictError extends Error {},
}));

import { apiPost } from '@/lib/api';
import {
  getPendingCustomers,
  markPendingCustomerResolved,
  storeCustomerAlias,
} from '@/lib/db/repositories/pendingCustomerRepository';
import {
  PendingCustomerSyncResponseError,
  PendingCustomerSyncScopeError,
  pushPendingCustomers,
} from '../pendingCustomerSyncService';

const db = {} as Database;
const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';

function pending(overrides: Partial<PendingCustomerRow> = {}): PendingCustomerRow {
  return {
    client_customer_uuid: 'client-customer-1',
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    name: 'Sarah Ben Ali',
    phone: '+216 20 100 200',
    email: null,
    status: 'pending',
    sync_error: null,
    created_at: '2026-05-21T12:00:00.000Z',
    updated_at: '2026-05-21T12:00:00.000Z',
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getPendingCustomers).mockResolvedValue([]);
});

describe('pushPendingCustomers', () => {
  it('pushes pending rows, persists aliases, and marks rows resolved', async () => {
    const row = pending();
    vi.mocked(getPendingCustomers).mockResolvedValueOnce([row]);
    vi.mocked(apiPost).mockResolvedValueOnce({
      client_customer_uuid: row.client_customer_uuid,
      server_partner_id: 'server-partner-1',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      resolved_at: '2026-05-21T12:01:00.000Z',
    });

    const count = await pushPendingCustomers(db, TENANT_ID, COMPANY_ID);

    expect(count).toBe(1);
    expect(apiPost).toHaveBeenCalledWith('/pos/customers/pending', {
      client_customer_uuid: 'client-customer-1',
      name: 'Sarah Ben Ali',
      phone: '+216 20 100 200',
      email: null,
    });
    expect(storeCustomerAlias).toHaveBeenCalledWith(db, {
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      client_customer_uuid: 'client-customer-1',
      server_partner_id: 'server-partner-1',
      resolved_at: '2026-05-21T12:01:00.000Z',
    });
    expect(markPendingCustomerResolved).toHaveBeenCalledWith(
      db,
      TENANT_ID,
      COMPANY_ID,
      'client-customer-1',
    );
  });

  it('fails loudly when the server returns another tenant or company', async () => {
    const row = pending();
    vi.mocked(getPendingCustomers).mockResolvedValueOnce([row]);
    vi.mocked(apiPost).mockResolvedValueOnce({
      client_customer_uuid: row.client_customer_uuid,
      server_partner_id: 'server-partner-1',
      tenant_id: TENANT_ID,
      company_id: 'company-2',
      resolved_at: '2026-05-21T12:01:00.000Z',
    });

    await expect(pushPendingCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      PendingCustomerSyncScopeError,
    );

    expect(storeCustomerAlias).not.toHaveBeenCalled();
    expect(markPendingCustomerResolved).not.toHaveBeenCalled();
  });

  it('fails loudly when the server returns a mismatched client customer uuid', async () => {
    const row = pending();
    vi.mocked(getPendingCustomers).mockResolvedValueOnce([row]);
    vi.mocked(apiPost).mockResolvedValueOnce({
      client_customer_uuid: 'client-customer-2',
      server_partner_id: 'server-partner-1',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      resolved_at: '2026-05-21T12:01:00.000Z',
    });

    await expect(pushPendingCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toBeInstanceOf(
      PendingCustomerSyncResponseError,
    );

    expect(storeCustomerAlias).not.toHaveBeenCalled();
    expect(markPendingCustomerResolved).not.toHaveBeenCalled();
  });
});
