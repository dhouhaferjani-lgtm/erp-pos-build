/**
 * Boot-contract anchor for the single-writer architecture (2026-06-12
 * design spec) — successor of the Bug 5 WAL call-order guard.
 *
 * The OLD contract (PRAGMA journal_mode=WAL through the plugin pool before
 * migrations) is GONE: the Rust writer's connect options now own
 * WAL/synchronous/busy_timeout, and migrations + stuck-receipt recovery run
 * on the writer through the write gate — the pooled plugin handle must
 * never see migration/transaction statements (a pooled JS BEGIN…COMMIT
 * splits across physical connections → self-deadlock + tx poisoning).
 *
 * Why a mock-based call-order test: `@tauri-apps/plugin-sql` and the Tauri
 * `invoke` bridge are not available in the Vitest Node harness. The actual
 * WAL/busy_timeout effect is verified by the Rust-side cargo test
 * (`db_writer::tests::open_connection_applies_wal_and_busy_timeout`) and
 * the live Tauri smoke.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

interface InvokeCall {
  cmd: string;
  sql?: string;
  params?: unknown[];
}

// Shared mutable state so we can both record calls AND simulate the
// `_migrations` bookkeeping (versions are remembered across boots so the
// "second boot" test path proves the rerunnable recovery hook fires again).
const poolExecuteCalls: string[] = [];
const writerCalls: InvokeCall[] = [];
const appliedMigrationVersions: number[] = [];

// Mock the Tauri invoke bridge (the writer path) BEFORE importing db.ts.
vi.mock('@tauri-apps/api/core', () => ({
  invoke: vi.fn(async (cmd: string, args?: { sql?: string; values?: unknown[] }) => {
    writerCalls.push({ cmd, sql: args?.sql, params: args?.values });
    if (cmd === 'writer_select') {
      if (args?.sql !== undefined && /FROM\s+_migrations/i.test(args.sql)) {
        return appliedMigrationVersions.map((v) => ({ version: v }));
      }
      return [];
    }
    if (cmd === 'writer_execute') {
      const insertMatch = /INSERT\s+INTO\s+_migrations/i.exec(args?.sql ?? '');
      if (insertMatch && args?.values && typeof args.values[0] === 'number') {
        appliedMigrationVersions.push(args.values[0]);
      }
      return { rowsAffected: 0, lastInsertId: 0 };
    }
    return undefined; // writer_open / writer_close
  }),
}));

// Mock the plugin pool (the read path).
vi.mock('@tauri-apps/plugin-sql', () => {
  const stub = {
    execute: vi.fn(async (sql: string) => {
      poolExecuteCalls.push(sql);
      return { rowsAffected: 0 };
    }),
    select: vi.fn(async () => []),
    close: vi.fn(async () => undefined),
  };
  return {
    default: {
      load: vi.fn(async () => stub),
    },
  };
});

const RECOVERY_RE = /UPDATE\s+offline_receipts[\s\S]*sync_error\s+LIKE\s+'%database is locked%'/i;

describe('db.ts — single-writer boot contract', () => {
  beforeEach(() => {
    poolExecuteCalls.length = 0;
    writerCalls.length = 0;
    appliedMigrationVersions.length = 0;
    // Force a fresh module load so the module-level singletons reset.
    vi.resetModules();
  });

  it('opens the Rust writer and issues NO pragma and NO migration through the pooled plugin', async () => {
    const { getDatabase } = await import('@/lib/db');
    await getDatabase('test-company-boot');

    // The writer was opened (its connect options own WAL/busy_timeout).
    expect(writerCalls.some((c) => c.cmd === 'writer_open')).toBe(true);

    // The pool never sees pragmas, migrations, or transaction statements.
    expect(poolExecuteCalls).toHaveLength(0);
  });

  it('runs migrations then stuck-receipt recovery on the WRITER, in order', async () => {
    const { getDatabase } = await import('@/lib/db');
    await getDatabase('test-company-order');

    const migrationsTableIdx = writerCalls.findIndex(
      (c) => c.cmd === 'writer_execute' && /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+_migrations/i.test(c.sql ?? ''),
    );
    const recoveryIdx = writerCalls.findIndex(
      (c) => c.cmd === 'writer_execute' && RECOVERY_RE.test(c.sql ?? ''),
    );
    const openIdx = writerCalls.findIndex((c) => c.cmd === 'writer_open');

    expect(openIdx).toBeGreaterThanOrEqual(0);
    expect(migrationsTableIdx).toBeGreaterThan(openIdx);
    // Recovery must run AFTER the migrations runner so v32's one-shot has
    // already executed; the runtime hook is the rerunnable safety net.
    expect(recoveryIdx).toBeGreaterThan(migrationsTableIdx);
  });

  it('re-runs stuck-receipt recovery on every boot (Codex r1 P2 — rerunnable hook)', async () => {
    const { getDatabase, closeDatabase } = await import('@/lib/db');

    await getDatabase('test-company-recovery-1');
    await closeDatabase();
    // First boot: migration v32's one-shot UPDATE + the boot hook both match.
    const before = writerCalls.filter((c) => RECOVERY_RE.test(c.sql ?? '')).length;
    expect(before).toBeGreaterThanOrEqual(1);

    // Second boot (migrations already applied in the shared mock bookkeeping):
    // exactly ONE more recovery run — the rerunnable hook.
    await getDatabase('test-company-recovery-2');
    const after = writerCalls.filter((c) => RECOVERY_RE.test(c.sql ?? '')).length;
    expect(after).toBe(before + 1);

    // Company switch closed the previous writer.
    expect(writerCalls.some((c) => c.cmd === 'writer_close')).toBe(true);
  });
});
