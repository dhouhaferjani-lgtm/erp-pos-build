import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { runMigrationsUpTo, runMigrationVersion } from './helpers/migrationTestHelpers';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import {
  getV4RefundAuthoringAckState,
  setV4RefundAuthoringAckFailure,
} from '@/lib/db/repositories/terminalStateRepository';

/**
 * Lane C wave-2 fix-wave finding 9 (fiscal C-6) — pinned exact migration
 * version for `terminal_state.v4_refund_authoring_ack_error`, against REAL
 * SQLite. Mirrors `migrations.v67.test.ts` (final-review MINOR M-4: v65 and
 * v67 had dedicated migration tests, v66 did not).
 *
 * What only a real-SQLite migration test can catch here:
 *   1. the column actually lands on `terminal_state` (a mis-typed table name
 *      in an `ALTER TABLE` would be swallowed by the migration's own
 *      `isDuplicateColumnError` catch only if the error matched — it would
 *      not, so this pins the happy path AND the catch's narrowness);
 *   2. re-running v66 on an already-migrated DB no-ops instead of throwing
 *      "duplicate column name" — device migrations re-run on every app
 *      start, so a non-idempotent one bricks the app permanently;
 *   3. the failure marker survives a round-trip through the repository
 *      pair that wraps this column, including the pre-v66 fail-closed read.
 */

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  pk: number;
  dflt_value: string | null;
}

async function columnsOf(adapter: SqliteTestAdapter, table: string): Promise<Map<string, ColumnInfo>> {
  const columns = await adapter.select<ColumnInfo[]>(`PRAGMA table_info(${table})`);
  return new Map(columns.map((c) => [c.name, c]));
}

async function insertTerminalState(adapter: SqliteTestAdapter, terminalId: string): Promise<void> {
  await adapter.execute(
    `INSERT INTO terminal_state (terminal_id, terminal_code, location_code, genesis_seed, last_hash, hash_sequence)
     VALUES ($1, 'T01', 'MAIN', $2, $3, 0)`,
    [terminalId, '0'.repeat(64), 'a'.repeat(64)],
  );
}

describe('Migration v66 — terminal_state.v4_refund_authoring_ack_error', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('adds the nullable TEXT column to terminal_state', async () => {
    await runMigrationsUpTo(adapter, 65);
    expect((await columnsOf(adapter, 'terminal_state')).has('v4_refund_authoring_ack_error')).toBe(false);

    await runMigrationVersion(adapter, 66);

    const byName = await columnsOf(adapter, 'terminal_state');
    // Nullable with no default: "no failure recorded" must be indistinguishable
    // from "this device has never attempted the ACK".
    expect(byName.get('v4_refund_authoring_ack_error')).toMatchObject({
      type: 'TEXT',
      notnull: 0,
      dflt_value: null,
    });
  });

  it('a row written BEFORE this version reads back a null marker, not an error', async () => {
    await runMigrationsUpTo(adapter, 65);
    await insertTerminalState(adapter, 'term-legacy');

    await runMigrationVersion(adapter, 66);

    const state = await getV4RefundAuthoringAckState(adapter.asDatabase(), 'term-legacy');
    expect(state.lastError).toBeNull();
    expect(state.acknowledgedAt).toBeNull();
  });

  it('is idempotent — re-running v66 does not error and preserves the recorded failure', async () => {
    await runMigrationsUpTo(adapter, 66);
    await insertTerminalState(adapter, 'term-1');
    await setV4RefundAuthoringAckFailure(adapter.asDatabase(), 'term-1', 'HTTP 404 no such route');

    // Device migrations re-run on every app start; a throw here bricks the app.
    await runMigrationVersion(adapter, 66);

    const state = await getV4RefundAuthoringAckState(adapter.asDatabase(), 'term-1');
    expect(state.lastError).toBe('HTTP 404 no such route');
  });

  it('a SUCCESS clears the marker and stamps the acknowledgement exactly once', async () => {
    await runMigrationsUpTo(adapter, 66);
    await insertTerminalState(adapter, 'term-1');

    await setV4RefundAuthoringAckFailure(adapter.asDatabase(), 'term-1', 'connection refused');
    await setV4RefundAuthoringAckFailure(adapter.asDatabase(), 'term-1', null);

    const first = await getV4RefundAuthoringAckState(adapter.asDatabase(), 'term-1');
    expect(first.lastError).toBeNull();
    expect(first.acknowledgedAt).not.toBeNull();

    // The device re-ACKs on EVERY successful pull while `enabled` stays true;
    // the rollout audit trail must record when the terminal FIRST
    // acknowledged, so the COALESCE must never move the timestamp.
    await setV4RefundAuthoringAckFailure(adapter.asDatabase(), 'term-1', null);
    const second = await getV4RefundAuthoringAckState(adapter.asDatabase(), 'term-1');
    expect(second.acknowledgedAt).toBe(first.acknowledgedAt);
  });

  it('a FAILURE leaves an existing acknowledgement timestamp alone', async () => {
    await runMigrationsUpTo(adapter, 66);
    await insertTerminalState(adapter, 'term-1');

    await setV4RefundAuthoringAckFailure(adapter.asDatabase(), 'term-1', null);
    const acknowledged = await getV4RefundAuthoringAckState(adapter.asDatabase(), 'term-1');
    expect(acknowledged.acknowledgedAt).not.toBeNull();

    await setV4RefundAuthoringAckFailure(adapter.asDatabase(), 'term-1', 'later transient failure');

    const state = await getV4RefundAuthoringAckState(adapter.asDatabase(), 'term-1');
    expect(state.acknowledgedAt).toBe(acknowledged.acknowledgedAt);
    expect(state.lastError).toBe('later transient failure');
  });

  it('reads fail-closed on a pre-v66 schema — the column simply is not there', async () => {
    await runMigrationsUpTo(adapter, 65);
    await insertTerminalState(adapter, 'term-1');

    const state = await getV4RefundAuthoringAckState(adapter.asDatabase(), 'term-1');
    expect(state).toEqual({ acknowledgedAt: null, lastError: null });
  });
});
