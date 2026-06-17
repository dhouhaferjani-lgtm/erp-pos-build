/**
 * Migration v54 — `terminal_state.shift_number_seed` (offline-first shifts
 * Phase 6.1).
 *
 * On a freshly-installed device, `local_shifts` is empty so `nextShiftNumber`
 * would restart the per-terminal counter at 1 — colliding with shift numbers
 * the server already projected from a prior install. The device caches the
 * server's current `MAX(pos_shifts.shift_number)` (carried on the terminal
 * payload) into this column so numbering continues monotonically.
 *
 * The seed is consulted by `nextShiftNumber` (MAX of the local rows and the
 * seed) and written by `setShiftNumberSeed` with a monotone guard so a stale
 * server read can never rewind it below a number the device has already used.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { runMigrationsUpTo } from './helpers/migrationTestHelpers';
import {
  getShiftNumberSeed,
  setShiftNumberSeed,
  upsertTerminalState,
  type TerminalHashState,
} from '@/lib/db/repositories/terminalStateRepository';
import {
  insertLocalShift,
  nextShiftNumber,
  type LocalShiftInput,
} from '@/lib/db/repositories/localShiftRepository';

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  dflt_value: string | null;
}

const TERMINAL_ID = 'term-1';

async function seedTerminalStateRow(adapter: SqliteTestAdapter, terminalId: string): Promise<void> {
  await adapter.execute(
    `INSERT INTO terminal_state (terminal_id, terminal_code, genesis_seed, last_hash)
     VALUES ($1, 'T01', 'seed', 'hash')`,
    [terminalId],
  );
}

function makeOpenShift(overrides: Partial<LocalShiftInput> = {}): LocalShiftInput {
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

describe('migration v54 — terminal_state.shift_number_seed', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 54);
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds the shift_number_seed column defaulting to 0', async () => {
    const columns = await adapter.select<ColumnInfo[]>("PRAGMA table_info('terminal_state')");
    const col = columns.find((c) => c.name === 'shift_number_seed');
    expect(col, 'shift_number_seed column exists').toBeDefined();
    expect(col?.notnull).toBe(1);
    expect(col?.dflt_value).toBe('0');
  });

  it('is re-runnable (duplicate-column guard)', async () => {
    const v54 = migrations.find((m) => m.version === 54);
    expect(v54?.run).toBeDefined();
    // beforeEach already applied v54; running its hook again must be a no-op.
    await expect(v54!.run!(adapter.asDatabase())).resolves.toBeUndefined();
  });

  describe('getShiftNumberSeed', () => {
    it('returns 0 when no terminal_state row exists', async () => {
      const db = adapter.asDatabase();
      expect(await getShiftNumberSeed(db, 'unknown-terminal')).toBe(0);
    });

    it('returns 0 for a row that has never been seeded', async () => {
      const db = adapter.asDatabase();
      await seedTerminalStateRow(adapter, TERMINAL_ID);
      expect(await getShiftNumberSeed(db, TERMINAL_ID)).toBe(0);
    });
  });

  describe('setShiftNumberSeed', () => {
    it('persists a seed and reads it back', async () => {
      const db = adapter.asDatabase();
      await seedTerminalStateRow(adapter, TERMINAL_ID);
      await setShiftNumberSeed(db, TERMINAL_ID, 42);
      expect(await getShiftNumberSeed(db, TERMINAL_ID)).toBe(42);
    });

    it('is monotone — never rewinds below an existing seed', async () => {
      const db = adapter.asDatabase();
      await seedTerminalStateRow(adapter, TERMINAL_ID);
      await setShiftNumberSeed(db, TERMINAL_ID, 42);
      // A stale server read reports a lower MAX — must NOT lower the seed.
      await setShiftNumberSeed(db, TERMINAL_ID, 10);
      expect(await getShiftNumberSeed(db, TERMINAL_ID)).toBe(42);
    });
  });

  describe('upsertTerminalState writes the seed atomically (Codex r1 HIGH)', () => {
    function hashState(seed?: number): TerminalHashState {
      return {
        terminal_id: TERMINAL_ID,
        terminal_code: 'T01',
        location_code: 'MAIN',
        genesis_seed: 'seed',
        last_hash: 'hash',
        hash_sequence: 0,
        manager_pin_throttle_until: null,
        manager_pin_failed_attempts: 0,
        fiscal_schema_version: 3,
        shift_number_seed: seed,
      };
    }

    it('persists the seed in the same INSERT as the terminal_state row', async () => {
      const db = adapter.asDatabase();
      // No separate setShiftNumberSeed call — a crash after this single write
      // can never leave a fresh row with seed 0.
      await upsertTerminalState(db, hashState(9));
      expect(await getShiftNumberSeed(db, TERMINAL_ID)).toBe(9);
    });

    it('is monotone on conflict — a lower seed never rewinds it', async () => {
      const db = adapter.asDatabase();
      await upsertTerminalState(db, hashState(9));
      await upsertTerminalState(db, hashState(3));
      expect(await getShiftNumberSeed(db, TERMINAL_ID)).toBe(9);
    });

    it('defaults to 0 when no seed is supplied', async () => {
      const db = adapter.asDatabase();
      await upsertTerminalState(db, hashState(undefined));
      expect(await getShiftNumberSeed(db, TERMINAL_ID)).toBe(0);
    });
  });

  describe('nextShiftNumber honours the seed', () => {
    it('continues from the seed when local_shifts is empty', async () => {
      const db = adapter.asDatabase();
      // Fresh device: no local shifts, but the server had reached 5.
      expect(await nextShiftNumber(db, TERMINAL_ID, 5)).toBe(6);
    });

    it('uses the local MAX when it exceeds the seed', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', shift_number: 7 }));
      expect(await nextShiftNumber(db, TERMINAL_ID, 5)).toBe(8);
    });

    it('defaults to the local-only MAX when no seed is passed', async () => {
      const db = adapter.asDatabase();
      await insertLocalShift(db, makeOpenShift({ id: 's1', shift_number: 3 }));
      expect(await nextShiftNumber(db, TERMINAL_ID)).toBe(4);
    });
  });
});
