/**
 * Bug 5 anchor — verify db.ts enables SQLite WAL mode BEFORE running
 * migrations, so the first `getDatabase` call of every POS session sets
 * `journal_mode=WAL` on the database file.
 *
 * Why a mock-based call-order test:
 *   - `@tauri-apps/plugin-sql` is not available in the Vitest Node harness;
 *     it only loads under the Tauri runtime.
 *   - `node:sqlite` (used by `SqliteTestAdapter` for migration integration
 *     tests) is `:memory:`-only and cannot switch to WAL.
 *
 * The actual WAL behavior is verified manually in a Tauri build (PR body's
 * acceptance criteria — open devtools, run `PRAGMA journal_mode`). This
 * unit test proves we call the PRAGMA in the right place; manual smoke
 * proves the PRAGMA takes effect.
 *
 * NOTE: busy_timeout is intentionally NOT set in app code — SQLx 0.8.6
 * already defaults connections to a 5s busy_timeout (see
 * sqlx-sqlite/src/options/mod.rs:194-201). The amendments-v1 doc
 * (P1 finding) records this; Tauri plugin-sql 2.3.2 does not override
 * the default.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

interface ExecuteCall {
  sql: string;
}

const executeCalls: ExecuteCall[] = [];

// Mock the plugin BEFORE importing db.ts so the import binds to the stub.
vi.mock('@tauri-apps/plugin-sql', () => {
  const stub = {
    execute: vi.fn(async (sql: string) => {
      executeCalls.push({ sql });
      return { rowsAffected: 0 };
    }),
    select: vi.fn(async (sql: string) => {
      executeCalls.push({ sql });
      // _migrations bookkeeping query — return empty so every migration runs.
      if (/FROM\s+_migrations/i.test(sql)) return [];
      return [];
    }),
    close: vi.fn(async () => undefined),
  };
  return {
    default: {
      load: vi.fn(async () => stub),
    },
  };
});

describe('db.ts — WAL configuration call order (Bug 5 regression guard)', () => {
  beforeEach(async () => {
    executeCalls.length = 0;
    // Force a fresh module load so the module-level `db` singleton is reset.
    vi.resetModules();
  });

  it('runs PRAGMA journal_mode=WAL before any migration statement', async () => {
    const { getDatabase } = await import('@/lib/db');
    await getDatabase('test-company-wal');

    const walIdx = executeCalls.findIndex((c) =>
      /PRAGMA\s+journal_mode\s*=\s*WAL/i.test(c.sql),
    );
    const migrationsTableIdx = executeCalls.findIndex((c) =>
      /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+_migrations/i.test(c.sql),
    );

    expect(walIdx).toBeGreaterThanOrEqual(0);
    expect(migrationsTableIdx).toBeGreaterThanOrEqual(0);
    expect(walIdx).toBeLessThan(migrationsTableIdx);
  });

  it('does NOT set busy_timeout via PRAGMA (SQLx default 5s is authoritative)', async () => {
    const { getDatabase } = await import('@/lib/db');
    await getDatabase('test-company-no-busy-timeout');

    const busyTimeoutCall = executeCalls.find((c) =>
      /PRAGMA\s+busy_timeout/i.test(c.sql),
    );
    expect(busyTimeoutCall).toBeUndefined();
  });
});
