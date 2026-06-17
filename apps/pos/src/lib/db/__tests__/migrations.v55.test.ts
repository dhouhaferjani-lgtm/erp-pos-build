/**
 * Migration v55 — `(terminal_id, shift_number)` UNIQUE on `local_shifts`
 * (offline-first shifts Phase 6.1, Codex r1 MEDIUM).
 *
 * The server already enforces `pos_shifts (terminal_id, shift_number)` unique
 * (2026_06_14_110000_make_pos_shifts_terminal_shift_number_unique). The device
 * mints numbers monotonically in-tx and the seed only raises the floor, so a
 * duplicate is impossible on the happy path — but a DB-level UNIQUE backstop
 * makes any regressed caller / manual import / restore edge case fail loud
 * rather than silently double-number a register (which would break NF525
 * "sans rupture de séquence"). Replaces the plain lookup index from v53.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { runMigrationsUpTo } from './helpers/migrationTestHelpers';
import {
  insertLocalShift,
  closeLocalShift,
  type LocalShiftInput,
} from '@/lib/db/repositories/localShiftRepository';

const TERMINAL_ID = 'term-1';

function makeShift(overrides: Partial<LocalShiftInput> = {}): LocalShiftInput {
  return {
    id: '019700aa-bbbb-7ccc-8ddd-eeeeffff0001',
    terminal_id: TERMINAL_ID,
    session_id: '019700aa-bbbb-7ccc-8ddd-eeeeffff0001',
    shift_number: 1,
    opening_cash: '100.000',
    opened_at: '2026-06-14T08:00:00Z',
    cashier_id: 'cashier-1',
    cashier_name: 'Alice',
    ...overrides,
  };
}

describe('migration v55 — local_shifts (terminal_id, shift_number) UNIQUE', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 55);
  });

  afterEach(() => {
    adapter.close();
  });

  it('exposes the unique index and drops the old plain one', async () => {
    const indexes = await adapter.select<Array<{ name: string; unique: number }>>(
      "PRAGMA index_list('local_shifts')",
    );
    const byName = new Map(indexes.map((i) => [i.name, i]));
    expect(byName.get('idx_local_shifts_terminal_number_unique')?.unique).toBe(1);
    expect(byName.has('idx_local_shifts_terminal_number')).toBe(false);
  });

  it('rejects a duplicate shift_number for the same terminal (even once closed)', async () => {
    const db = adapter.asDatabase();
    await insertLocalShift(db, makeShift({ id: 's1', shift_number: 1 }));
    await closeLocalShift(db, 's1', '2026-06-14T12:00:00Z');
    // A regressed caller tries to reuse number 1 on the same terminal.
    await expect(
      insertLocalShift(db, makeShift({ id: 's2', shift_number: 1 })),
    ).rejects.toThrow();
  });

  it('allows the same shift_number across different terminals', async () => {
    const db = adapter.asDatabase();
    await insertLocalShift(db, makeShift({ id: 's1', terminal_id: 'term-1', shift_number: 1 }));
    await expect(
      insertLocalShift(db, makeShift({ id: 's2', terminal_id: 'term-2', shift_number: 1 })),
    ).resolves.toBeUndefined();
  });

  it('is re-runnable', async () => {
    const v55 = migrations.find((m) => m.version === 55);
    expect(v55).toBeDefined();
    await expect(adapter.execute(v55!.sql)).resolves.toBeDefined();
  });
});
