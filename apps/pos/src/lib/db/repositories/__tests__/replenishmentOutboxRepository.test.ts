import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { queryAll } from '@/lib/db';
import {
  getAllOpenRequests,
  getOpenRequestForProduct,
  replaceOpenRequests,
} from '../openReplenishmentRepository';
import {
  enqueueReplenishmentRequest,
  getPendingReplenishmentRequests,
  markReplenishmentFailed,
  markReplenishmentResolved,
  purgeOutboxRows,
} from '../replenishmentOutboxRepository';

describe('replenishment repositories', () => {
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

  it('queues requests in update order and resolves them within scope', async () => {
    await enqueueReplenishmentRequest(db, request('request-2', '2026-07-10T12:01:00.000Z'));
    await enqueueReplenishmentRequest(db, request('request-1', '2026-07-10T12:00:00.000Z'));

    await expect(getPendingReplenishmentRequests(db, 'tenant-1', 'company-1')).resolves.toMatchObject([
      { client_request_uuid: 'request-1', status: 'pending' },
      { client_request_uuid: 'request-2', status: 'pending' },
    ]);

    await markReplenishmentResolved(db, 'tenant-1', 'company-1', 'request-1');

    await expect(getPendingReplenishmentRequests(db, 'tenant-1', 'company-1')).resolves.toMatchObject([
      { client_request_uuid: 'request-2' },
    ]);
  });

  it('idempotent re-enqueue resets failed rows to pending', async () => {
    await enqueueReplenishmentRequest(db, request('request-1', '2026-07-10T12:00:00.000Z'));
    await markReplenishmentFailed(
      db,
      'tenant-1',
      'company-1',
      'request-1',
      '422: invalid quantity',
      '2026-07-10T12:01:00.000Z',
    );

    await enqueueReplenishmentRequest(db, {
      ...request('request-1', '2026-07-10T12:02:00.000Z'),
      requested_qty: '3.5000',
      note: 'Still empty',
    });

    await expect(getPendingReplenishmentRequests(db, 'tenant-1', 'company-1')).resolves.toMatchObject([
      {
        client_request_uuid: 'request-1',
        requested_qty: '3.5000',
        note: 'Still empty',
        status: 'pending',
        sync_error: null,
      },
    ]);
  });

  it('enforces non-empty tenant and company scope', async () => {
    await expect(getPendingReplenishmentRequests(db, '', 'company-1')).rejects.toThrow(
      '[replenishment] tenant_id is required',
    );
    await expect(getPendingReplenishmentRequests(db, 'tenant-1', '')).rejects.toThrow(
      '[replenishment] company_id is required',
    );
    await expect(
      enqueueReplenishmentRequest(db, { ...request('request-1'), tenant_id: '' }),
    ).rejects.toThrow('[replenishment] tenant_id is required');
  });

  it('replaces the location feed while retaining closed feedback rows', async () => {
    await replaceOpenRequests(
      db,
      'tenant-1',
      'company-1',
      [
        serverRow('open-1', 'product-1', 'pending'),
        serverRow('closed-1', 'product-2', 'fulfilled'),
      ],
      '2026-07-10T12:00:00.000Z',
    );

    await expect(
      getOpenRequestForProduct(db, 'tenant-1', 'company-1', 'product-1', ''),
    ).resolves.toMatchObject({ request_id: 'open-1', status: 'pending' });
    await expect(
      getOpenRequestForProduct(db, 'tenant-1', 'company-1', 'product-2', ''),
    ).resolves.toBeNull();
    await expect(getAllOpenRequests(db, 'tenant-1', 'company-1')).resolves.toHaveLength(2);

    await replaceOpenRequests(
      db,
      'tenant-1',
      'company-1',
      [serverRow('closed-1', 'product-2', 'rejected')],
      '2026-07-10T12:05:00.000Z',
    );

    await expect(getAllOpenRequests(db, 'tenant-1', 'company-1')).resolves.toMatchObject([
      { request_id: 'closed-1', status: 'rejected', fetched_at: '2026-07-10T12:05:00.000Z' },
    ]);
  });

  it('keeps absent cache rows when the server feed is truncated', async () => {
    await replaceOpenRequests(
      db,
      'tenant-1',
      'company-1',
      [
        serverRow('request-1', 'product-1', 'pending'),
        serverRow('request-2', 'product-2', 'pending'),
      ],
      '2026-07-10T12:00:00.000Z',
    );

    await replaceOpenRequests(
      db,
      'tenant-1',
      'company-1',
      [serverRow('request-1', 'product-1', 'in_progress')],
      '2026-07-10T12:05:00.000Z',
      false,
    );

    await expect(getAllOpenRequests(db, 'tenant-1', 'company-1')).resolves.toMatchObject([
      { request_id: 'request-1', status: 'in_progress' },
      { request_id: 'request-2', status: 'pending' },
    ]);
  });
});

// ─── GB-3 — retention sweep (purgeOutboxRows) ─────────────────────────────────
//
// Timestamps in replenishment_outbox are JS-authored ISO 8601 strings, so the
// age cutoffs are computed in JS and passed as bound params (rule 20). The
// helper deletes resolved rows > 30 days and failed rows > 90 days; pending
// rows are NEVER purged.

const NOW = '2026-07-12T12:00:00.000Z';

function daysAgo(n: number): string {
  return new Date(new Date(NOW).getTime() - n * 864e5).toISOString();
}

async function allRows(
  db: ReturnType<SqliteTestAdapter['asDatabase']>,
  tenantId = 'tenant-1',
  companyId = 'company-1',
): Promise<Array<{ client_request_uuid: string; status: string }>> {
  return queryAll(
    db,
    `SELECT client_request_uuid, status
       FROM replenishment_outbox
      WHERE tenant_id = $1 AND company_id = $2
      ORDER BY client_request_uuid`,
    [tenantId, companyId],
  );
}

describe('replenishmentOutboxRepository.purgeOutboxRows', () => {
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
    await enqueueReplenishmentRequest(db, request('old-resolved', daysAgo(40)));
    await markReplenishmentResolved(db, 'tenant-1', 'company-1', 'old-resolved', daysAgo(31));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([]);
  });

  it('retains a resolved row that is only 29 days old', async () => {
    await enqueueReplenishmentRequest(db, request('fresh-resolved', daysAgo(40)));
    await markReplenishmentResolved(db, 'tenant-1', 'company-1', 'fresh-resolved', daysAgo(29));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([
      { client_request_uuid: 'fresh-resolved', status: 'resolved' },
    ]);
  });

  it('NEVER purges a pending row even at 40 days old', async () => {
    await enqueueReplenishmentRequest(db, request('old-pending', daysAgo(40)));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([
      { client_request_uuid: 'old-pending', status: 'pending' },
    ]);
  });

  it('retains a failed row at 60 days but purges it at 91 days', async () => {
    await enqueueReplenishmentRequest(db, request('failed-60', daysAgo(70)));
    await markReplenishmentFailed(db, 'tenant-1', 'company-1', 'failed-60', 'boom', daysAgo(60));
    await enqueueReplenishmentRequest(db, request('failed-91', daysAgo(100)));
    await markReplenishmentFailed(db, 'tenant-1', 'company-1', 'failed-91', 'boom', daysAgo(91));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    await expect(allRows(db)).resolves.toEqual([
      { client_request_uuid: 'failed-60', status: 'failed' },
    ]);
  });

  it('only purges rows within the requested tenant/company scope', async () => {
    // Purgeable resolved rows in three scopes.
    await enqueueReplenishmentRequest(db, { ...request('r-t1c1', daysAgo(40)) });
    await markReplenishmentResolved(db, 'tenant-1', 'company-1', 'r-t1c1', daysAgo(31));

    await enqueueReplenishmentRequest(db, {
      ...request('r-t2c1', daysAgo(40)),
      tenant_id: 'tenant-2',
    });
    await markReplenishmentResolved(db, 'tenant-2', 'company-1', 'r-t2c1', daysAgo(31));

    await enqueueReplenishmentRequest(db, {
      ...request('r-t1c2', daysAgo(40)),
      company_id: 'company-2',
    });
    await markReplenishmentResolved(db, 'tenant-1', 'company-2', 'r-t1c2', daysAgo(31));

    await purgeOutboxRows(db, 'tenant-1', 'company-1', NOW);

    // Only the tenant-1/company-1 row is gone; other scopes untouched.
    await expect(allRows(db, 'tenant-1', 'company-1')).resolves.toEqual([]);
    await expect(allRows(db, 'tenant-2', 'company-1')).resolves.toEqual([
      { client_request_uuid: 'r-t2c1', status: 'resolved' },
    ]);
    await expect(allRows(db, 'tenant-1', 'company-2')).resolves.toEqual([
      { client_request_uuid: 'r-t1c2', status: 'resolved' },
    ]);
  });
});

function request(clientRequestUuid: string, now = '2026-07-10T12:00:00.000Z') {
  return {
    client_request_uuid: clientRequestUuid,
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    terminal_id: 'terminal-1',
    product_id: 'product-1',
    variant_id: null,
    requested_qty: '1.0000',
    note: null,
    now,
  };
}

function serverRow(id: string, productId: string, status: string) {
  return {
    id,
    product_id: productId,
    variant_id: null,
    status,
    requested_qty: '1.0000',
    request_count: 1,
    last_requested_at: '2026-07-10T11:59:00.000Z',
  };
}
