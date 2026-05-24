import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import {
  assertCustomerAliasMatches,
  enqueuePendingCustomer,
  getCustomerAlias,
  getPendingCustomers,
  markPendingCustomerResolved,
  StaleCustomerAliasConflictError,
  storeCustomerAlias,
} from '../pendingCustomerRepository';

async function applyAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const migration of migrations) {
    if (migration.run) {
      await migration.run(adapter);
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

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
