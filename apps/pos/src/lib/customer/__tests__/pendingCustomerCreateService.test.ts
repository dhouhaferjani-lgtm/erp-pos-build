/**
 * T-0001 (Part A) — local visibility for POS "add customer".
 *
 * Root cause: both add-customer entry points (cart modal + Customers tab)
 * only wrote `pending_customer_outbox`, while search AND list read the
 * `customers` mirror — a freshly created customer was invisible locally.
 *
 * `createPendingCustomer` must enqueue the outbox row AND upsert an
 * optimistic row into the `customers` mirror so search/list see the new
 * customer immediately, using the client uuid as the interim id (the same
 * id the alias scheme in pendingCustomerSyncService resolves later).
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { getPendingCustomers } from '@/lib/db/repositories/pendingCustomerRepository';
import {
  getCustomerById,
  listCustomers,
  searchCustomers,
} from '@/lib/db/repositories/customerRepository';
import { createPendingCustomer } from '../pendingCustomerCreateService';

const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';
const CLIENT_UUID = '5bb57558-fcb5-48b1-8575-a99da4c58149';

describe('createPendingCustomer', () => {
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

  it('enqueues the outbox row AND makes the customer immediately visible to search and list', async () => {
    await createPendingCustomer(db, {
      client_customer_uuid: CLIENT_UUID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      name: 'Amina Trabelsi',
      phone: '+216 99 100 200',
      email: null,
      now: '2026-07-06T09:15:30.000Z',
    });

    // Outbox row still written (server push path unchanged).
    await expect(getPendingCustomers(db, TENANT_ID, COMPANY_ID)).resolves.toMatchObject([
      { client_customer_uuid: CLIENT_UUID, name: 'Amina Trabelsi', status: 'pending' },
    ]);

    // THE BUG: search/list read only the customers mirror. The optimistic
    // row must be there, keyed by the client uuid.
    const found = await searchCustomers(db, {
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      query: 'amina',
    });
    expect(found).toHaveLength(1);
    expect(found[0]).toMatchObject({
      id: CLIENT_UUID,
      name: 'Amina Trabelsi',
      phone: '+216 99 100 200',
      account_status: 'active',
      is_active: 1,
    });

    const listed = await listCustomers(db, TENANT_ID, COMPANY_ID);
    expect(listed.map((row) => row.id)).toEqual([CLIENT_UUID]);
  });

  it('stamps SQLite-format UTC timestamps (space separator) on the optimistic row', async () => {
    await createPendingCustomer(db, {
      client_customer_uuid: CLIENT_UUID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      name: 'Amina Trabelsi',
      phone: '+216 99 100 200',
      email: null,
      now: '2026-07-06T09:15:30.000Z',
    });

    const row = await getCustomerById(db, TENANT_ID, COMPANY_ID, CLIENT_UUID);
    expect(row).not.toBeNull();
    expect(row?.synced_at).toBe('2026-07-06 09:15:30');
    expect(row?.updated_at).toBe('2026-07-06 09:15:30');
  });

  it('returns the mirror row it wrote, with safe pending defaults', async () => {
    const returned = await createPendingCustomer(db, {
      client_customer_uuid: CLIENT_UUID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      name: 'Amina Trabelsi',
      phone: null,
      email: 'amina@example.test',
      now: '2026-07-06T09:15:30.000Z',
    });

    expect(returned).toMatchObject({
      id: CLIENT_UUID,
      name: 'Amina Trabelsi',
      phone: null,
      email: 'amina@example.test',
      customer_category: null,
      receivable_balance: '0.000',
      credit_balance: '0.000',
      credit_limit: null,
      charge_account_enabled: 0,
      account_status: 'active',
      account_status_version: 1,
      sync_version: null,
    });

    const persisted = await getCustomerById(db, TENANT_ID, COMPANY_ID, CLIENT_UUID);
    expect(persisted).toEqual(returned);
  });

  it('is idempotent for the same deterministic client uuid (re-create updates, never duplicates)', async () => {
    const input = {
      client_customer_uuid: CLIENT_UUID,
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      name: 'Amina Trabelsi',
      phone: '+216 99 100 200',
      email: null,
      now: '2026-07-06T09:15:30.000Z',
    };
    await createPendingCustomer(db, input);
    await createPendingCustomer(db, { ...input, now: '2026-07-06T09:20:00.000Z' });

    const listed = await listCustomers(db, TENANT_ID, COMPANY_ID);
    expect(listed).toHaveLength(1);
    expect(await getPendingCustomers(db, TENANT_ID, COMPANY_ID)).toHaveLength(1);
  });
});
