/**
 * Task 9 — pullLocationStock (location-aware stock sync).
 *
 * Contract under test (plan §4.3):
 *   - delta mode sends the PERSISTED server-issued cursor verbatim as
 *     `updated_since` (never device time) and stores the NEW `as_of`
 *     from the response afterwards;
 *   - delta with a missing cursor degrades to a FULL pull (first pull
 *     after migration);
 *   - full → replaceAllStock; delta → upsertStockRows; replaceIncoming
 *     ALWAYS (page-1's complete `incoming` array);
 *   - page loop accumulates ALL stock rows before ANY DB write — a
 *     partial multi-page failure must not advance the cursor or write;
 *   - Menu tenants and unclaimed terminals short-circuit to {count: 0}
 *     without any API call;
 *   - error classification mirrors pullProductsCore: FetchTimeoutError
 *     propagates verbatim, 5xx → PullLocationStockError('http_5xx'),
 *     and NO failure ever writes the cursor or the repository.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';

const terminalState = vi.hoisted(() => ({
  terminal: { id: 'term-1' } as { id: string } | null,
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: () => terminalState,
  },
}));

vi.mock('@/api/stockApi', () => ({
  fetchLocationStock: vi.fn(),
}));

vi.mock('@/lib/db/repositories/locationStockRepository', () => ({
  upsertStockRows: vi.fn(),
  replaceAllStock: vi.fn(),
  replaceIncoming: vi.fn(),
  deleteForProducts: vi.fn(),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  upsertProducts: vi.fn(),
  deleteProducts: vi.fn(),
  reconcileMenuProducts: vi.fn(),
}));

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return {
    ...actual,
    apiGet: vi.fn(),
  };
});

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn(),
  logSyncOperation: vi.fn(),
  cleanupOldSyncLogs: vi.fn(),
}));

vi.mock('@/api/productApi', () => ({
  fetchCompanyConfig: vi.fn(),
  fetchPOSProducts: vi.fn(),
  fetchProductByBarcode: vi.fn(),
  fetchActiveMenu: vi.fn(),
  flattenMenuToProducts: vi.fn(),
}));

import {
  pullLocationStock,
  pullProducts,
  PullLocationStockError,
} from '@/lib/sync/syncService';
import { useProductStore } from '@/stores/productStore';
import { fetchLocationStock } from '@/api/stockApi';
import type { LocationStockPage } from '@/api/stockApi';
import {
  upsertStockRows,
  replaceAllStock,
  replaceIncoming,
  deleteForProducts,
} from '@/lib/db/repositories/locationStockRepository';
import type {
  ServerIncomingRow,
  ServerStockRow,
} from '@/lib/db/repositories/locationStockRepository';
import {
  getSyncMetadata,
  setSyncMetadata,
} from '@/lib/db/repositories/syncLogRepository';
import { ApiRequestError, apiGet } from '@/lib/api';
import { FetchTimeoutError } from '@/lib/fetchWithTimeout';

const db = {} as Database;

const STOCK_CURSOR_KEY = 'location_stock_as_of';

function stockRow(productId: string): ServerStockRow {
  return {
    product_id: productId,
    variant_id: null,
    quantity: '10.0000',
    reserved: '0.0000',
    available: '10.0000',
    updated_at: '2026-06-10T08:00:00Z',
  };
}

function incomingRow(productId: string): ServerIncomingRow {
  return {
    product_id: productId,
    variant_id: null,
    incoming_transfer: '5.0000',
    incoming_po: '0.0000',
  };
}

function makePage(args: {
  stock?: ServerStockRow[];
  incoming?: ServerIncomingRow[];
  asOf?: string;
  current?: number;
  last?: number;
  total?: number;
}): LocationStockPage {
  return {
    data: {
      stock: args.stock ?? [],
      incoming: args.incoming ?? [],
      as_of: args.asOf ?? '2026-06-11T12:00:00Z',
    },
    meta: {
      pagination: {
        current_page: args.current ?? 1,
        last_page: args.last ?? 1,
        total: args.total ?? (args.stock?.length ?? 0),
      },
    },
  };
}

function setStandardTenant(): void {
  useProductStore.setState({
    companyConfig: {
      company_id: 'company-1',
      all_enabled_modules: ['POS'],
    } as never,
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSyncMetadata).mockResolvedValue(null);
  terminalState.terminal = { id: 'term-1' };
  setStandardTenant();
});

describe('pullLocationStock — cursor semantics', () => {
  it('delta sends the PERSISTED cursor verbatim as updated_since and stores the NEW as_of after', async () => {
    const storedCursor = '2026-06-10T09:30:00Z';
    vi.mocked(getSyncMetadata).mockImplementation((_db, key) =>
      Promise.resolve(key === STOCK_CURSOR_KEY ? storedCursor : null),
    );
    vi.mocked(fetchLocationStock).mockResolvedValueOnce(
      makePage({ stock: [stockRow('p1')], asOf: '2026-06-11T12:00:00Z' }),
    );

    const result = await pullLocationStock(db, 'delta');

    expect(result.count).toBe(1);
    expect(fetchLocationStock).toHaveBeenCalledTimes(1);
    const [terminalId, params] = vi.mocked(fetchLocationStock).mock.calls[0]!;
    expect(terminalId).toBe('term-1');
    expect(params.updated_since).toBe(storedCursor);
    expect(setSyncMetadata).toHaveBeenCalledWith(
      db,
      STOCK_CURSOR_KEY,
      '2026-06-11T12:00:00Z',
    );
  });

  it('delta with a missing cursor behaves as FULL: replaceAllStock, no updated_since param', async () => {
    vi.mocked(getSyncMetadata).mockResolvedValue(null);
    vi.mocked(fetchLocationStock).mockResolvedValueOnce(
      makePage({ stock: [stockRow('p1')] }),
    );

    await pullLocationStock(db, 'delta');

    const [, params] = vi.mocked(fetchLocationStock).mock.calls[0]!;
    expect(params.updated_since).toBeUndefined();
    expect(replaceAllStock).toHaveBeenCalledTimes(1);
    expect(upsertStockRows).not.toHaveBeenCalled();
  });

  it('full omits updated_since and calls replaceAllStock even when a cursor exists', async () => {
    vi.mocked(getSyncMetadata).mockResolvedValue('2026-06-10T09:30:00Z');
    vi.mocked(fetchLocationStock).mockResolvedValueOnce(
      makePage({ stock: [stockRow('p1')] }),
    );

    await pullLocationStock(db, 'full');

    const [, params] = vi.mocked(fetchLocationStock).mock.calls[0]!;
    expect(params.updated_since).toBeUndefined();
    expect(replaceAllStock).toHaveBeenCalledWith(db, [stockRow('p1')]);
    expect(upsertStockRows).not.toHaveBeenCalled();
  });

  it('delta with a cursor calls upsertStockRows (never replaceAllStock)', async () => {
    vi.mocked(getSyncMetadata).mockResolvedValue('2026-06-10T09:30:00Z');
    vi.mocked(fetchLocationStock).mockResolvedValueOnce(
      makePage({ stock: [stockRow('p1'), stockRow('p2')] }),
    );

    await pullLocationStock(db, 'delta');

    expect(upsertStockRows).toHaveBeenCalledWith(db, [
      stockRow('p1'),
      stockRow('p2'),
    ]);
    expect(replaceAllStock).not.toHaveBeenCalled();
  });
});

describe('pullLocationStock — incoming snapshot', () => {
  it('calls replaceIncoming on every pull with page-1 incoming (even when empty)', async () => {
    vi.mocked(fetchLocationStock).mockResolvedValueOnce(
      makePage({ stock: [stockRow('p1')], incoming: [incomingRow('p1')] }),
    );
    await pullLocationStock(db, 'full');
    expect(replaceIncoming).toHaveBeenCalledWith(db, [incomingRow('p1')]);

    vi.mocked(fetchLocationStock).mockResolvedValueOnce(
      makePage({ stock: [stockRow('p1')], incoming: [] }),
    );
    await pullLocationStock(db, 'full');
    expect(replaceIncoming).toHaveBeenLastCalledWith(db, []);
    expect(replaceIncoming).toHaveBeenCalledTimes(2);
  });
});

describe('pullLocationStock — gates', () => {
  it('Menu tenant → {count: 0} with NO API call', async () => {
    useProductStore.setState({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['POS', 'Menu'],
      } as never,
    });

    const result = await pullLocationStock(db, 'delta');

    expect(result.count).toBe(0);
    expect(fetchLocationStock).not.toHaveBeenCalled();
  });

  it('no claimed terminal → {count: 0} with NO API call', async () => {
    terminalState.terminal = null;

    const result = await pullLocationStock(db, 'full');

    expect(result.count).toBe(0);
    expect(fetchLocationStock).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('explicit opts.terminalId overrides an empty store (boot/claim ordering)', async () => {
    // Load-bearing: claim paths call seedOfflineHashChain BEFORE
    // set({ terminal }) — without the override the boot full-pull would
    // silently no-op. Pin that the explicit id wins over a null store.
    terminalState.terminal = null;
    vi.mocked(fetchLocationStock).mockResolvedValueOnce(
      makePage({ stock: [stockRow('p-1')] }),
    );

    const result = await pullLocationStock(db, 'full', { terminalId: 'term-override' });

    expect(result.count).toBe(1);
    expect(fetchLocationStock).toHaveBeenCalledWith(
      'term-override',
      expect.objectContaining({ page: '1' }),
      expect.anything(),
    );
  });
});

describe('pullLocationStock — error classification (mirrors pullProductsCore)', () => {
  it('FetchTimeoutError propagates verbatim; no cursor write, no DB write', async () => {
    vi.mocked(fetchLocationStock).mockRejectedValueOnce(
      new FetchTimeoutError('https://x/pos/stock-levels', 10_000, 'GET'),
    );

    await expect(pullLocationStock(db, 'full')).rejects.toBeInstanceOf(
      FetchTimeoutError,
    );

    expect(replaceAllStock).not.toHaveBeenCalled();
    expect(upsertStockRows).not.toHaveBeenCalled();
    expect(replaceIncoming).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('5xx → PullLocationStockError(http_5xx); no cursor write, no DB write', async () => {
    vi.mocked(fetchLocationStock).mockRejectedValueOnce(
      new ApiRequestError(503, 'Service Unavailable', 'UNAVAILABLE'),
    );

    const err = await pullLocationStock(db, 'full').catch((e: unknown) => e);

    expect(err).toBeInstanceOf(PullLocationStockError);
    expect((err as PullLocationStockError).kind).toBe('http_5xx');
    expect((err as PullLocationStockError).status).toBe(503);
    expect(replaceAllStock).not.toHaveBeenCalled();
    expect(replaceIncoming).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });

  it('4xx → PullLocationStockError(network)', async () => {
    vi.mocked(fetchLocationStock).mockRejectedValueOnce(
      new ApiRequestError(422, 'Unprocessable', 'VALIDATION'),
    );

    const err = await pullLocationStock(db, 'full').catch((e: unknown) => e);

    expect(err).toBeInstanceOf(PullLocationStockError);
    expect((err as PullLocationStockError).kind).toBe('network');
  });

  it('mid-loop failure on page 2 of 3: NOTHING written, cursor NOT advanced', async () => {
    vi.mocked(getSyncMetadata).mockResolvedValue('2026-06-10T09:30:00Z');
    vi.mocked(fetchLocationStock)
      .mockResolvedValueOnce(
        makePage({ stock: [stockRow('p1')], incoming: [incomingRow('p1')], last: 3 }),
      )
      .mockRejectedValueOnce(new ApiRequestError(500, 'boom', 'ERR'));

    await expect(pullLocationStock(db, 'delta')).rejects.toBeInstanceOf(
      PullLocationStockError,
    );

    expect(upsertStockRows).not.toHaveBeenCalled();
    expect(replaceAllStock).not.toHaveBeenCalled();
    expect(replaceIncoming).not.toHaveBeenCalled();
    expect(setSyncMetadata).not.toHaveBeenCalled();
  });
});

describe('pullLocationStock — pagination', () => {
  it('pages until last_page; ALL stock in ONE repo call; incoming + as_of from page 1; cursor once at the end', async () => {
    vi.mocked(getSyncMetadata).mockResolvedValue('2026-06-10T09:30:00Z');
    vi.mocked(fetchLocationStock)
      .mockResolvedValueOnce(
        makePage({
          stock: [stockRow('p1')],
          incoming: [incomingRow('p1')],
          asOf: '2026-06-11T12:00:00Z',
          current: 1,
          last: 3,
        }),
      )
      .mockResolvedValueOnce(
        makePage({
          stock: [stockRow('p2')],
          incoming: [],
          asOf: '2026-06-11T12:00:05Z',
          current: 2,
          last: 3,
        }),
      )
      .mockResolvedValueOnce(
        makePage({
          stock: [stockRow('p3')],
          incoming: [],
          asOf: '2026-06-11T12:00:09Z',
          current: 3,
          last: 3,
        }),
      );

    const result = await pullLocationStock(db, 'delta');

    expect(result.count).toBe(3);
    expect(fetchLocationStock).toHaveBeenCalledTimes(3);
    expect(vi.mocked(fetchLocationStock).mock.calls.map(([, p]) => p.page)).toEqual([
      '1',
      '2',
      '3',
    ]);
    // Every page carries the SAME cursor (delta param is loop-invariant).
    for (const [, p] of vi.mocked(fetchLocationStock).mock.calls) {
      expect(p.updated_since).toBe('2026-06-10T09:30:00Z');
    }

    // One accumulated repository write with ALL pages' rows.
    expect(upsertStockRows).toHaveBeenCalledTimes(1);
    expect(upsertStockRows).toHaveBeenCalledWith(db, [
      stockRow('p1'),
      stockRow('p2'),
      stockRow('p3'),
    ]);

    // Single replaceIncoming sourced from page 1 (complete on page 1).
    expect(replaceIncoming).toHaveBeenCalledTimes(1);
    expect(replaceIncoming).toHaveBeenCalledWith(db, [incomingRow('p1')]);

    // Cursor written ONCE, from PAGE 1's as_of.
    const cursorWrites = vi
      .mocked(setSyncMetadata)
      .mock.calls.filter(([, key]) => key === STOCK_CURSOR_KEY);
    expect(cursorWrites).toHaveLength(1);
    expect(cursorWrites[0]![2]).toBe('2026-06-11T12:00:00Z');
  });
});

describe('pullProducts — catalog-tombstone cascade pin (Task 8 review)', () => {
  it('deleted_ids from /products cascade into locationStockRepository.deleteForProducts', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      data: [],
      deleted_ids: ['p-deleted'],
    });

    await pullProducts(db);

    expect(deleteForProducts).toHaveBeenCalledWith(db, ['p-deleted']);
  });
});
