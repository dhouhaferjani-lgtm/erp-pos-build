/**
 * Migration v53 — `local_shifts` (offline-first shifts Phase 0).
 *
 * The device becomes the authority for the shift lifecycle: it mints a UUIDv7
 * shift id + a per-terminal monotone `shift_number` and authors SESSION_OPEN
 * locally, with `pos_shifts` becoming a server-side projection. This table is
 * the local source of truth for "the current open shift" — read with no
 * network.
 *
 * Phase 0 is self-contained (no server changes): the table + the
 * `nextShiftNumber` counter + the one-open-per-terminal invariant + the
 * one-time copy of the cached shift. Exercises the real migration against a
 * real SQLite engine, then round-trips through the repository.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { runMigrationsUpTo } from './helpers/migrationTestHelpers';
import {
  insertLocalShift,
  nextShiftNumber,
  getCurrentOpenShift,
  closeLocalShift,
  type LocalShiftInput,
} from '@/lib/db/repositories/localShiftRepository';

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  pk: number;
}

function makeOpenShift(overrides: Partial<LocalShiftInput> = {}): LocalShiftInput {
  return {
    id: '019700aa-bbbb-7ccc-8ddd-eeeeffff0001',
    terminal_id: 'term-1',
    session_id: '019700aa-bbbb-7ccc-8ddd-eeeeffff0001',
    shift_number: 1,
    opening_cash: '100.000',
    opened_at: '2026-06-14T08:00:00Z',
    cashier_id: 'cashier-1',
    cashier_name: 'Alice',
    ...overrides,
  };
}

describe('migration v53 — local_shifts', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 53);
  });

  afterEach(() => {
    adapter.close();
  });

  it('creates the local_shifts table with the expected columns', async () => {
    const columns = await adapter.select<ColumnInfo[]>("PRAGMA table_info('local_shifts')");
    const byName = new Map(columns.map((c) => [c.name, c]));

    expect(byName.get('id')?.pk).toBe(1);
    for (const required of [
      'terminal_id',
      'session_id',
      'shift_number',
      'status',
      'opening_cash',
      'opened_at',
      'cashier_id',
      'cashier_name',
    ]) {
      expect(byName.get(required)?.notnull, `${required} NOT NULL`).toBe(1);
    }
    // closed_at is nullable (set only at close)
    expect(byName.has('closed_at')).toBe(true);
    expect(byName.get('closed_at')?.notnull).toBe(0);
  });

  it('enforces one open shift per terminal via a partial unique index', async () => {
    const indexes = await adapter.select<Array<{ name: string }>>(
      "PRAGMA index_list('local_shifts')",
    );
    expect(indexes.map((i) => i.name)).toContain('idx_local_shifts_one_open_per_terminal');
  });

  it('is re-runnable (CREATE TABLE / INDEX IF NOT EXISTS)', async () => {
    const v53 = migrations.find((m) => m.version === 53);
    expect(v53).toBeDefined();
    await expect(adapter.execute(v53!.sql)).resolves.toBeDefined();
  });

  describe('nextShiftNumber', () => {
    it('starts at 1 when the terminal has no shifts', async () => {
      const db = adapter.asDatabase();
      expect(await nextShiftNumber(db, 'term-1')).toBe(1);
    });

    it('is monotone per terminal (MAX + 1)', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', shift_number: 1 }));
      await closeLocalShift(db, 's1', '2026-06-14T12:00:00Z');
      await insertLocalShift(db, makeOpenShift({ id: 's2', shift_number: 2 }));
      await closeLocalShift(db, 's2', '2026-06-14T18:00:00Z');

      expect(await nextShiftNumber(db, 'term-1')).toBe(3);
    });

    it('counts independently per terminal', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', terminal_id: 'term-1', shift_number: 7 }));
      // term-2 has never opened a shift → still 1
      expect(await nextShiftNumber(db, 'term-2')).toBe(1);
      // term-1's next is 8
      expect(await nextShiftNumber(db, 'term-1')).toBe(8);
    });
  });

  describe('one-open invariant', () => {
    it('rejects a second open shift for the same terminal', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', shift_number: 1 }));
      await expect(
        insertLocalShift(db, makeOpenShift({ id: 's2', shift_number: 2 })),
      ).rejects.toThrow();
    });

    it('allows a new open shift once the previous one is closed', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', shift_number: 1 }));
      await closeLocalShift(db, 's1', '2026-06-14T12:00:00Z');
      await expect(
        insertLocalShift(db, makeOpenShift({ id: 's2', shift_number: 2 })),
      ).resolves.toBeUndefined();

      const open = await getCurrentOpenShift(db, 'term-1');
      expect(open?.id).toBe('s2');
    });

    it('does not constrain open shifts across different terminals', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', terminal_id: 'term-1', shift_number: 1 }));
      await expect(
        insertLocalShift(db, makeOpenShift({ id: 's2', terminal_id: 'term-2', shift_number: 1 })),
      ).resolves.toBeUndefined();
    });
  });

  describe('getCurrentOpenShift', () => {
    it('returns the open shift for the terminal', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', shift_number: 4 }));
      const open = await getCurrentOpenShift(db, 'term-1');
      expect(open).toMatchObject({
        id: 's1',
        terminal_id: 'term-1',
        shift_number: 4,
        status: 'OPEN',
        opening_cash: '100.000',
        cashier_id: 'cashier-1',
        cashier_name: 'Alice',
      });
    });

    it('returns null when no shift is open for the terminal', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', shift_number: 1 }));
      await closeLocalShift(db, 's1', '2026-06-14T12:00:00Z');
      expect(await getCurrentOpenShift(db, 'term-1')).toBeNull();
    });
  });
});
