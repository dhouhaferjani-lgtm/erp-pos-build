import type Database from '@tauri-apps/plugin-sql';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiRequestError } from '@/lib/api';
import type { ReplenishmentOutboxRow } from '@/lib/db/repositories/replenishmentOutboxRepository';

vi.mock('@/api/replenishmentApi', () => ({
  fetchOpenReplenishment: vi.fn(),
  pushReplenishmentRequest: vi.fn(),
}));

vi.mock('@/lib/db/repositories/replenishmentOutboxRepository', () => ({
  getPendingReplenishmentRequests: vi.fn(),
  markReplenishmentFailed: vi.fn().mockResolvedValue(undefined),
  markReplenishmentResolved: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/openReplenishmentRepository', () => ({
  replaceOpenRequests: vi.fn().mockResolvedValue(undefined),
}));

import {
  fetchOpenReplenishment,
  pushReplenishmentRequest,
} from '@/api/replenishmentApi';
import { replaceOpenRequests } from '@/lib/db/repositories/openReplenishmentRepository';
import {
  getPendingReplenishmentRequests,
  markReplenishmentFailed,
  markReplenishmentResolved,
} from '@/lib/db/repositories/replenishmentOutboxRepository';
import {
  pullOpenReplenishment,
  pushReplenishmentRequests,
} from '../replenishmentSyncService';

const db = {} as Database;
const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getPendingReplenishmentRequests).mockResolvedValue([]);
});

describe('replenishment sync', () => {
  it('pushes and resolves pending replenishment rows', async () => {
    const row = pending();
    vi.mocked(getPendingReplenishmentRequests).mockResolvedValueOnce([row]);
    vi.mocked(pushReplenishmentRequest).mockResolvedValueOnce(serverRow());

    await expect(pushReplenishmentRequests(db, TENANT_ID, COMPANY_ID)).resolves.toBe(1);

    expect(pushReplenishmentRequest).toHaveBeenCalledWith({
      client_request_uuid: row.client_request_uuid,
      terminal_id: row.terminal_id,
      product_id: row.product_id,
      variant_id: null,
      requested_qty: '1.0000',
      note: null,
    });
    expect(markReplenishmentResolved).toHaveBeenCalledWith(
      db,
      TENANT_ID,
      COMPANY_ID,
      row.client_request_uuid,
    );
  });

  it('marks a 422 failed and continues with the next row', async () => {
    const first = pending();
    const second = pending({ client_request_uuid: 'request-2', product_id: 'product-2' });
    vi.mocked(getPendingReplenishmentRequests).mockResolvedValueOnce([first, second]);
    vi.mocked(pushReplenishmentRequest)
      .mockRejectedValueOnce(new ApiRequestError(422, 'Invalid quantity', 'VALIDATION_ERROR'))
      .mockResolvedValueOnce(serverRow({ id: 'server-2', product_id: 'product-2' }));

    await expect(pushReplenishmentRequests(db, TENANT_ID, COMPANY_ID)).resolves.toBe(1);

    expect(markReplenishmentFailed).toHaveBeenCalledWith(
      db,
      TENANT_ID,
      COMPANY_ID,
      first.client_request_uuid,
      '422: Invalid quantity',
    );
    expect(markReplenishmentResolved).toHaveBeenCalledWith(
      db,
      TENANT_ID,
      COMPANY_ID,
      second.client_request_uuid,
    );
  });

  it('rethrows network failures and leaves the row pending', async () => {
    vi.mocked(getPendingReplenishmentRequests).mockResolvedValueOnce([pending()]);
    vi.mocked(pushReplenishmentRequest).mockRejectedValueOnce(new Error('network down'));

    await expect(pushReplenishmentRequests(db, TENANT_ID, COMPANY_ID)).rejects.toThrow(
      'network down',
    );

    expect(markReplenishmentFailed).not.toHaveBeenCalled();
    expect(markReplenishmentResolved).not.toHaveBeenCalled();
  });

  it('parks a row whose persisted scope mismatches the active scope', async () => {
    const row = pending({ company_id: 'company-2' });
    vi.mocked(getPendingReplenishmentRequests).mockResolvedValueOnce([row]);

    await expect(pushReplenishmentRequests(db, TENANT_ID, COMPANY_ID)).resolves.toBe(0);

    expect(pushReplenishmentRequest).not.toHaveBeenCalled();
    expect(markReplenishmentFailed).toHaveBeenCalledWith(
      db,
      TENANT_ID,
      COMPANY_ID,
      row.client_request_uuid,
      expect.stringContaining('outside active scope'),
    );
  });

  it('pulls the terminal feed into the scoped cache', async () => {
    const rows = [serverRow()];
    vi.mocked(fetchOpenReplenishment).mockResolvedValueOnce({
      data: rows,
      as_of: '2026-07-10T12:05:00.000Z',
      truncated: false,
    });

    await pullOpenReplenishment(db, TENANT_ID, COMPANY_ID, 'terminal-1');

    expect(replaceOpenRequests).toHaveBeenCalledWith(
      db,
      TENANT_ID,
      COMPANY_ID,
      rows,
      '2026-07-10T12:05:00.000Z',
      true,
    );
  });

  it.each([
    ['missing', undefined],
    ['non-string', 4],
  ])('rejects a pull row with %s suggested_qty', async (_label, suggestedQty) => {
    vi.mocked(fetchOpenReplenishment).mockResolvedValueOnce({
      data: [serverRow({ suggested_qty: suggestedQty })],
      as_of: '2026-07-10T12:05:00.000Z',
      truncated: false,
    });

    await expect(
      pullOpenReplenishment(db, TENANT_ID, COMPANY_ID, 'terminal-1'),
    ).rejects.toThrow('invalid suggested_qty');
    expect(replaceOpenRequests).not.toHaveBeenCalled();
  });

  it('upserts without deleting absent cache rows when the pull feed is truncated', async () => {
    const rows = [serverRow()];
    vi.mocked(fetchOpenReplenishment).mockResolvedValueOnce({
      data: rows,
      as_of: '2026-07-10T12:05:00.000Z',
      truncated: true,
    });

    await pullOpenReplenishment(db, TENANT_ID, COMPANY_ID, 'terminal-1');

    expect(replaceOpenRequests).toHaveBeenCalledWith(
      db,
      TENANT_ID,
      COMPANY_ID,
      rows,
      '2026-07-10T12:05:00.000Z',
      false,
    );
  });
});

function pending(overrides: Partial<ReplenishmentOutboxRow> = {}): ReplenishmentOutboxRow {
  return {
    client_request_uuid: 'request-1',
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    terminal_id: 'terminal-1',
    product_id: 'product-1',
    variant_id: null,
    requested_qty: '1.0000',
    note: null,
    status: 'pending',
    sync_error: null,
    created_at: '2026-07-10T12:00:00.000Z',
    updated_at: '2026-07-10T12:00:00.000Z',
    ...overrides,
  };
}

function serverRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 'server-1',
    product_id: 'product-1',
    variant_id: null,
    status: 'pending',
    requested_qty: '1.0000',
    suggested_qty: '6.0000',
    request_count: 1,
    last_requested_at: '2026-07-10T12:01:00.000Z',
    ...overrides,
  };
}
