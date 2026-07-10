import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
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
