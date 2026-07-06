/**
 * T-0001 (Part A, round-trip half) — the optimistic mirror row written by
 * `createPendingCustomer` must survive the push→pull cycle WITHOUT
 * duplicating:
 *
 *   1. create   → mirror row keyed by client uuid (visible immediately)
 *   2. push     → server returns server_partner_id; alias stored; the
 *                 optimistic row is RE-KEYED to the server id
 *   3. pull     → upserts the server row under the server id → single row
 *
 * Real SQLite + real repositories; only the HTTP layer (`apiPost`) is mocked.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}));

import { apiPost } from '@/lib/api';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  getCustomerAlias,
  getPendingCustomers,
} from '@/lib/db/repositories/pendingCustomerRepository';
import {
  listCustomers,
  searchCustomers,
  upsertCustomer,
} from '@/lib/db/repositories/customerRepository';
import type { CustomerMirrorRow } from '@/lib/customer/customerTypes';
import { createPendingCustomer } from '../pendingCustomerCreateService';
import { pushPendingCustomers } from '../pendingCustomerSyncService';

const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';
const CLIENT_UUID = '5bb57558-fcb5-48b1-8575-a99da4c58149';
const SERVER_PARTNER_ID = '0197a1c2-0000-7000-8000-000000000001';

function serverRow(overrides: Partial<CustomerMirrorRow> = {}): CustomerMirrorRow {
  return {
    id: SERVER_PARTNER_ID,
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    name: 'Amina Trabelsi',
    phone: '+216 99 100 200',
    email: null,
    tax_number: null,
    customer_category: 'retail',
    receivable_balance: '0.000',
    credit_balance: '0.000',
    credit_limit: null,
    payment_terms_days: null,
    charge_account_enabled: 0,
    charge_policy_version: null,
    account_status: 'active',
    account_status_changed_at: null,
    account_status_reason: null,
    account_status_version: 1,
    balance_updated_at: null,
    is_active: 1,
    sync_version: 'server-v1',
    updated_at: '2026-07-06 09:16:00',
    synced_at: '2026-07-06 09:16:05',
    skin_type: null,
    skin_advice_note: null,
    ...overrides,
  };
}

async function createAmina(db: ReturnType<SqliteTestAdapter['asDatabase']>): Promise<void> {
  await createPendingCustomer(db, {
    client_customer_uuid: CLIENT_UUID,
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    name: 'Amina Trabelsi',
    phone: '+216 99 100 200',
    email: null,
    now: '2026-07-06T09:15:30.000Z',
  });
}

describe('pushPendingCustomers (integration, real SQLite)', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;

  beforeEach(async () => {
    vi.clearAllMocks();
    adapter = new SqliteTestAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('re-keys the optimistic mirror row to the server partner id when the push resolves', async () => {
    await createAmina(db);
    vi.mocked(apiPost).mockResolvedValueOnce({
      client_customer_uuid: CLIENT_UUID,
      server_partner_id: SERVER_PARTNER_ID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      resolved_at: '2026-07-06T09:16:00.000Z',
    });

    await expect(pushPendingCustomers(db, TENANT_ID, COMPANY_ID)).resolves.toBe(1);

    // Alias persisted, outbox drained.
    await expect(getCustomerAlias(db, TENANT_ID, COMPANY_ID, CLIENT_UUID)).resolves.toMatchObject({
      server_partner_id: SERVER_PARTNER_ID,
    });
    await expect(getPendingCustomers(db, TENANT_ID, COMPANY_ID)).resolves.toEqual([]);

    // Exactly ONE mirror row, now keyed by the server id.
    const listed = await listCustomers(db, TENANT_ID, COMPANY_ID);
    expect(listed).toHaveLength(1);
    expect(listed[0]).toMatchObject({ id: SERVER_PARTNER_ID, name: 'Amina Trabelsi' });
  });

  it('does not duplicate when the same-tick pull upserts the server row after the push', async () => {
    await createAmina(db);
    vi.mocked(apiPost).mockResolvedValueOnce({
      client_customer_uuid: CLIENT_UUID,
      server_partner_id: SERVER_PARTNER_ID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      resolved_at: '2026-07-06T09:16:00.000Z',
    });
    await pushPendingCustomers(db, TENANT_ID, COMPANY_ID);

    // pullCustomers delegates row writes to upsertCustomer — simulate the
    // delta pull delivering the server's canonical row.
    await upsertCustomer(db, serverRow({ name: 'Amina Trabelsi', tax_number: 'TN-200' }));

    const listed = await listCustomers(db, TENANT_ID, COMPANY_ID);
    expect(listed).toHaveLength(1);
    expect(listed[0]).toMatchObject({
      id: SERVER_PARTNER_ID,
      tax_number: 'TN-200',
      sync_version: 'server-v1',
    });
  });

  it('drops the stale optimistic row when a pull already delivered the server row before the push resolved', async () => {
    await createAmina(db);
    // Race shape: server row landed via pull first (e.g. created from web
    // admin between ticks), THEN the outbox push resolves to the same partner.
    await upsertCustomer(db, serverRow());
    vi.mocked(apiPost).mockResolvedValueOnce({
      client_customer_uuid: CLIENT_UUID,
      server_partner_id: SERVER_PARTNER_ID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      resolved_at: '2026-07-06T09:16:00.000Z',
    });

    await pushPendingCustomers(db, TENANT_ID, COMPANY_ID);

    const listed = await listCustomers(db, TENANT_ID, COMPANY_ID);
    expect(listed).toHaveLength(1);
    expect(listed[0]).toMatchObject({ id: SERVER_PARTNER_ID, sync_version: 'server-v1' });
  });

  it('keeps the optimistic row searchable when the push fails (offline tick)', async () => {
    await createAmina(db);
    vi.mocked(apiPost).mockRejectedValueOnce(new Error('Network error'));

    await expect(pushPendingCustomers(db, TENANT_ID, COMPANY_ID)).rejects.toThrow('Network error');

    // Still pending in the outbox AND still visible locally under the client uuid.
    await expect(getPendingCustomers(db, TENANT_ID, COMPANY_ID)).resolves.toHaveLength(1);
    const found = await searchCustomers(db, {
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      query: 'amina',
    });
    expect(found).toHaveLength(1);
    expect(found[0]).toMatchObject({ id: CLIENT_UUID });
  });

  it('parks a contract-violating row as failed and still syncs the rows behind it', async () => {
    const SECOND_UUID = '7cc68669-0db6-59c2-9686-b00eb5d69250';
    const SECOND_SERVER_ID = '0197a1c2-0000-7000-8000-000000000002';
    await createAmina(db);
    await createPendingCustomer(db, {
      client_customer_uuid: SECOND_UUID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      name: 'Bilel Haddad',
      phone: '+216 98 300 400',
      email: null,
      now: '2026-07-06T09:15:40.000Z',
    });

    // First (front-of-queue) row gets a mismatched uuid back — a permanent
    // contract violation. Second row resolves normally.
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        client_customer_uuid: 'not-the-uuid-we-sent',
        server_partner_id: SERVER_PARTNER_ID,
        tenant_id: TENANT_ID,
        company_id: COMPANY_ID,
        resolved_at: '2026-07-06T09:16:00.000Z',
      })
      .mockResolvedValueOnce({
        client_customer_uuid: SECOND_UUID,
        server_partner_id: SECOND_SERVER_ID,
        tenant_id: TENANT_ID,
        company_id: COMPANY_ID,
        resolved_at: '2026-07-06T09:16:01.000Z',
      });

    // Poison row does NOT abort the loop: one resolved, none left pending.
    await expect(pushPendingCustomers(db, TENANT_ID, COMPANY_ID)).resolves.toBe(1);
    await expect(getPendingCustomers(db, TENANT_ID, COMPANY_ID)).resolves.toEqual([]);

    // Second customer promoted to its server id; poison row's optimistic
    // mirror stays searchable under the client uuid.
    const listed = await listCustomers(db, TENANT_ID, COMPANY_ID);
    expect(listed.map((row) => row.id).sort()).toEqual([SECOND_SERVER_ID, CLIENT_UUID].sort());
  });
});
