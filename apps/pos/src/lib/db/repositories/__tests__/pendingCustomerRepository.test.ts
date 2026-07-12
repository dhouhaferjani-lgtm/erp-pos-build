import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { queryAll } from '@/lib/db';
import {
  assertCustomerAliasMatches,
  enqueuePendingCustomer,
  getCustomerAlias,
  getPendingCustomers,
  markPendingCustomerFailed,
  markPendingCustomerResolved,
  purgeOutboxRows,
  StaleCustomerAliasConflictError,
  storeCustomerAlias,
} from '../pendingCustomerRepository';

describe('pendingCustomerRepository', () => {
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

  it('stores pending customer outbox rows and resolves client id aliases', async () => {
    await enqueuePendingCustomer(db, {
      tenant_id: 'tenant-1',
      company_id: 'company-1',
      client_customer_uuid: 'client-customer-1',
      name: 'Sarah Ben Ali',
      phone: '+216 20 100 200',
      now: '2026-05-21T12:00:00.000Z',
    });

    await expect(getPendingCustomers(db, 'tenant-1', 'company-1')).resolves.toMatchObject([
      {
        tenant_id: 'tenant-1',
        company_id: 'company-1',
        client_customer_uuid: 'client-customer-1',
        status: 'pending',
        sync_error: null,
      },
    ]);

    await storeCustomerAlias(db, {
      tenant_id: 'tenant-1',
      company_id: 'company-1',
      client_customer_uuid: 'client-customer-1',
      server_partner_id: 'server-partner-1',
      resolved_at: '2026-05-21T12:01:00.000Z',
    });
    await markPendingCustomerResolved(
      db,
      'tenant-1',
      'company-1',
      'client-customer-1',
      '2026-05-21T12:02:00.000Z',
    );

    await expect(getCustomerAlias(db, 'tenant-1', 'company-1', 'client-customer-1')).resolves.toMatchObject({
      server_partner_id: 'server-partner-1',
      resolved_at: '2026-05-21T12:01:00.000Z',
    });
    await expect(getPendingCustomers(db, 'tenant-1', 'company-1')).resolves.toEqual([]);
  });

  it('blocks account payment authoring when a client id has a conflicting server alias', async () => {
    await storeCustomerAlias(db, {
      tenant_id: 'tenant-1',
      company_id: 'company-1',
      client_customer_uuid: 'client-customer-1',
      server_partner_id: 'server-partner-1',
      resolved_at: '2026-05-21T12:01:00.000Z',
    });

    await expect(
      assertCustomerAliasMatches(
        db,
        'tenant-1',
        'company-1',
        'client-customer-1',
        'server-partner-2',
      ),
    ).rejects.toBeInstanceOf(StaleCustomerAliasConflictError);

    await expect(
      storeCustomerAlias(db, {
        tenant_id: 'tenant-1',
        company_id: 'company-1',
        client_customer_uuid: 'client-customer-1',
        server_partner_id: 'server-partner-2',
        resolved_at: '2026-05-21T12:03:00.000Z',
      }),
    ).rejects.toBeInstanceOf(StaleCustomerAliasConflictError);
  });

  it('keeps aliases scoped by tenant and company', async () => {
    await storeCustomerAlias(db, {
      tenant_id: 'tenant-1',
      company_id: 'company-1',
      client_customer_uuid: 'client-customer-1',
      server_partner_id: 'server-partner-1',
      resolved_at: '2026-05-21T12:01:00.000Z',
    });
    await storeCustomerAlias(db, {
      tenant_id: 'tenant-2',
      company_id: 'company-9',
      client_customer_uuid: 'client-customer-1',
      server_partner_id: 'server-partner-9',
      resolved_at: '2026-05-21T12:01:00.000Z',
    });

    await expect(getCustomerAlias(db, 'tenant-2', 'company-9', 'client-customer-1')).resolves.toMatchObject({
      server_partner_id: 'server-partner-9',
    });
  });
});

// ─── GB-3 — retention sweep (purgeOutboxRows) ─────────────────────────────────
//
// Timestamps in pending_customer_outbox are JS-authored ISO 8601 strings, so
// the age cutoffs are computed in JS and passed as bound params (rule 20). The
// helper deletes resolved rows > 30 days and failed rows > 90 days; pending
// rows are NEVER purged.

const NOW = '2026-07-12T12:00:00.000Z';

function daysAgo(n: number): string {
  return new Date(new Date(NOW).getTime() - n * 864e5).toISOString();
}

function pendingCustomer(clientCustomerUuid: string, now: string, overrides: { tenant_id?: string; company_id?: string } = {}) {
  return {
    tenant_id: overrides.tenant_id ?? 'tenant-1',
    company_id: overrides.company_id ?? 'company-1',
    client_customer_uuid: clientCustomerUuid,
    name: 'Sarah Ben Ali',
    now,
  };
}

async function allRows(
  db: ReturnType<SqliteTestAdapter['asDatabase']>,
  tenantId = 'tenant-1',
  companyId = 'company-1',
): Promise<Array<{ client_customer_uuid: string; status: string }>> {
  return queryAll(
    db,
    `SELECT client_customer_uuid, status
       FROM pending_customer_outbox
      WHERE tenant_id = $1 AND company_id = $2
      ORDER BY client_customer_uuid`,
    [tenantId, companyId],
  );
}

describe('pendingCustomerRepository.purgeOutboxRows', () => {
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

  it('purges a resolved row older than 30 days', async () => {
    await enqueuePendingCustomer(db, pendingCustomer('old-resolved', daysAgo(40)));
    await markPendingCustomerResolved(db, 'tenant-1', 'company-1', 'old-resolved', daysAgo(31));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([]);
  });

  it('retains a resolved row that is only 29 days old', async () => {
    await enqueuePendingCustomer(db, pendingCustomer('fresh-resolved', daysAgo(40)));
    await markPendingCustomerResolved(db, 'tenant-1', 'company-1', 'fresh-resolved', daysAgo(29));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([
      { client_customer_uuid: 'fresh-resolved', status: 'resolved' },
    ]);
  });

  it('NEVER purges a pending row even at 40 days old', async () => {
    await enqueuePendingCustomer(db, pendingCustomer('old-pending', daysAgo(40)));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([
      { client_customer_uuid: 'old-pending', status: 'pending' },
    ]);
  });

  it('retains a failed row at 60 days but purges it at 91 days', async () => {
    await enqueuePendingCustomer(db, pendingCustomer('failed-60', daysAgo(70)));
    await markPendingCustomerFailed(db, 'tenant-1', 'company-1', 'failed-60', 'boom', daysAgo(60));
    await enqueuePendingCustomer(db, pendingCustomer('failed-91', daysAgo(100)));
    await markPendingCustomerFailed(db, 'tenant-1', 'company-1', 'failed-91', 'boom', daysAgo(91));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([
      { client_customer_uuid: 'failed-60', status: 'failed' },
    ]);
  });

  it('only purges rows within the requested tenant/company scope', async () => {
    await enqueuePendingCustomer(db, pendingCustomer('c-t1c1', daysAgo(40)));
    await markPendingCustomerResolved(db, 'tenant-1', 'company-1', 'c-t1c1', daysAgo(31));

    await enqueuePendingCustomer(db, pendingCustomer('c-t2c1', daysAgo(40), { tenant_id: 'tenant-2' }));
    await markPendingCustomerResolved(db, 'tenant-2', 'company-1', 'c-t2c1', daysAgo(31));

    await enqueuePendingCustomer(db, pendingCustomer('c-t1c2', daysAgo(40), { company_id: 'company-2' }));
    await markPendingCustomerResolved(db, 'tenant-1', 'company-2', 'c-t1c2', daysAgo(31));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db, 'tenant-1', 'company-1')).resolves.toEqual([]);
    await expect(allRows(db, 'tenant-2', 'company-1')).resolves.toEqual([
      { client_customer_uuid: 'c-t2c1', status: 'resolved' },
    ]);
    await expect(allRows(db, 'tenant-1', 'company-2')).resolves.toEqual([
      { client_customer_uuid: 'c-t1c2', status: 'resolved' },
    ]);
  });
});
