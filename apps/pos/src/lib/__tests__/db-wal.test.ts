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
  params?: unknown[];
}

// Shared mutable state so we can both record calls AND simulate the
// `_migrations` bookkeeping (versions are remembered across boots so the
// "second boot" test path proves the rerunnable hook runs even when the
// one-shot v32 migration has already been applied).
const executeCalls: ExecuteCall[] = [];
const appliedMigrationVersions: number[] = [];

// Mock the plugin BEFORE importing db.ts so the import binds to the stub.
vi.mock('@tauri-apps/plugin-sql', () => {
  const stub = {
    execute: vi.fn(async (sql: string, params?: unknown[]) => {
      executeCalls.push({ sql, params });
      // Record applied migrations so the next `SELECT version FROM _migrations`
      // returns them — mirroring the real `runMigrations` bookkeeping.
      const insertMatch = /INSERT\s+INTO\s+_migrations\s*\(\s*version\s*,\s*name\s*\)\s+VALUES\s*\(\s*\$1\s*,\s*\$2\s*\)/i.exec(
        sql,
      );
      if (insertMatch && params && typeof params[0] === 'number') {
        appliedMigrationVersions.push(params[0]);
      }
      return { rowsAffected: 0 };
    }),
    select: vi.fn(async (sql: string) => {
      executeCalls.push({ sql });
      if (/FROM\s+_migrations/i.test(sql)) {
        return appliedMigrationVersions.map((v) => ({ version: v }));
      }
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
    appliedMigrationVersions.length = 0;
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

  it('runs stuck-receipt recovery AFTER migrations on every getDatabase call (Codex r1 P2)', async () => {
    // Codex round-1 P2 closure: migration v32 only runs once per database
    // (one-shot via _migrations). The recovery must also fire on subsequent
    // launches so any FUTURE row that hits the lock signature post-WAL
    // self-heals on the next boot.
    const { getDatabase, closeDatabase } = await import('@/lib/db');

    await getDatabase('test-company-recovery-1');
    const firstWalIdx = executeCalls.findIndex((c) =>
      /PRAGMA\s+journal_mode\s*=\s*WAL/i.test(c.sql),
    );
    const firstRecoveryIdx = executeCalls.findIndex((c) =>
      /UPDATE\s+offline_receipts[\s\S]*sync_error\s+LIKE\s+'%database is locked%'/i.test(
        c.sql,
      ),
    );
    const firstMigrationsTableIdx = executeCalls.findIndex((c) =>
      /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+_migrations/i.test(c.sql),
    );

    expect(firstWalIdx).toBeGreaterThanOrEqual(0);
    expect(firstMigrationsTableIdx).toBeGreaterThanOrEqual(0);
    expect(firstRecoveryIdx).toBeGreaterThanOrEqual(0);
    // Recovery must run AFTER the migrations runner so v32's one-shot has
    // already executed when this row of the table existed; the runtime
    // hook is the rerunnable safety net for new-arrival rows.
    expect(firstRecoveryIdx).toBeGreaterThan(firstMigrationsTableIdx);

    // Force a second `getDatabase` call against a different company so the
    // singleton swaps and the full initialization path re-runs.
    await closeDatabase();
    const recoveryCallsBeforeSecondBoot = executeCalls.filter((c) =>
      /UPDATE\s+offline_receipts[\s\S]*sync_error\s+LIKE\s+'%database is locked%'/i.test(
        c.sql,
      ),
    ).length;
    await getDatabase('test-company-recovery-2');
    const recoveryCallsAfterSecondBoot = executeCalls.filter((c) =>
      /UPDATE\s+offline_receipts[\s\S]*sync_error\s+LIKE\s+'%database is locked%'/i.test(
        c.sql,
      ),
    ).length;

    expect(recoveryCallsAfterSecondBoot).toBe(recoveryCallsBeforeSecondBoot + 1);
  });
});
