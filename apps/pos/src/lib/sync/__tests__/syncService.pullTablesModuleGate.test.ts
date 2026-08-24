/**
 * Session B lane Q-13 — device half of the `module:Tables` backend gate.
 *
 * `/pos/floors` is now behind `module:Tables` on the server
 * (`apps/api/app/Modules/POS/routes_tables.php`). `pullTables` used to hit it
 * unconditionally on every sync cycle, so a retail/parapharmacy device would
 * write a `pull/tables` **error** row into the sync log once per tick, forever.
 *
 * The guard short-circuits when the companyConfig is KNOWN not to carry
 * `Tables`, logs the skip as a deliberate no-op (never `'error'`), leaves the
 * cached SQLite layout untouched, and returns true (nothing failed). An unknown
 * (null) config still falls through to the network call — fail-open on unknown.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db/repositories/tableRepository', () => ({
  upsertFloors: vi.fn(),
  upsertTables: vi.fn(),
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

import { pullTables } from '@/lib/sync/syncService';
import { useProductStore } from '@/stores/productStore';
import { apiGet } from '@/lib/api';
import { logSyncOperation } from '@/lib/db/repositories/syncLogRepository';
import { upsertFloors, upsertTables } from '@/lib/db/repositories/tableRepository';

describe('pullTables — Tables-module gating (Session B Q-13)', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
    useProductStore.setState({ companyConfig: null });
  });

  it('skips the /pos/floors request for a tenant without the Tables module', async () => {
    useProductStore.setState({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['POS', 'Sales', 'Inventory'],
      } as never,
    });

    const tablesPulled = await pullTables(db);

    expect(apiGet).not.toHaveBeenCalled();
    expect(tablesPulled).toBe(true);

    // The cached layout must be left untouched by a deliberate skip.
    expect(upsertFloors).not.toHaveBeenCalled();
    expect(upsertTables).not.toHaveBeenCalled();

    // Exactly one sync-log row, and it must NOT be an 'error'.
    expect(logSyncOperation).toHaveBeenCalledTimes(1);
    const call = vi.mocked(logSyncOperation).mock.calls[0];
    expect(call).toBeDefined();
    if (call === undefined) { throw new Error('unreachable'); }
    expect(call[1]).toBe('pull');
    expect(call[2]).toBe('tables');
    expect(call[4]).not.toBe('error');
    expect(String(call[5])).toContain('Tables');
  });

  it('hits /pos/floors for a tenant that has the Tables module', async () => {
    useProductStore.setState({
      companyConfig: {
        company_id: 'company-1',
        all_enabled_modules: ['POS', 'Menu', 'Tables'],
      } as never,
    });

    vi.mocked(apiGet).mockResolvedValue([]);

    const tablesPulled = await pullTables(db);

    expect(apiGet).toHaveBeenCalledWith('/pos/floors');
    expect(tablesPulled).toBe(true);
  });

  it('falls through to the network call when the config has not been fetched yet (fail-open)', async () => {
    useProductStore.setState({ companyConfig: null });

    vi.mocked(apiGet).mockResolvedValue([]);

    const tablesPulled = await pullTables(db);

    expect(apiGet).toHaveBeenCalledWith('/pos/floors');
    expect(tablesPulled).toBe(true);
  });
});
