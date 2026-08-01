import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { runMigrationsUpTo, runMigrationVersion } from './helpers/migrationTestHelpers';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';

/**
 * v3-refund-chain-integration spec §4.4/§7.2/§9.1/§15 — pinned exact
 * migration version. One device migration bundles all three schema
 * areas: the `refund_intents` durable-intent table, `offline_receipts.
 * receipt_kind`, and `terminal_state`'s two v4-capability columns.
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

describe('Migration v65 — refund_intents + offline_receipts.receipt_kind + terminal_state v4 capability columns', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(() => {
    adapter = new SqliteTestAdapter();
  });

  afterEach(() => {
    adapter.close();
  });

  it('creates refund_intents with every column the state machine needs', async () => {
    await runMigrationsUpTo(adapter, 64);
    await runMigrationVersion(adapter, 65);

    const byName = await columnsOf(adapter, 'refund_intents');

    expect(byName.get('id')).toMatchObject({ type: 'TEXT', pk: 1 });
    expect(byName.get('terminal_id')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('operator_id')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('original_local_receipt_id')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('original_fiscal_event_id')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('line_snapshot_json')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('line_snapshot_fingerprint')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('approval_source_event_id')).toMatchObject({ type: 'TEXT', notnull: 1 });
    expect(byName.get('override_source_event_id')).toMatchObject({ type: 'TEXT', notnull: 1 });
    // Populated only AFTER engine.append() returns (§4.3) -- nullable.
    expect(byName.get('refund_fiscal_event_id')).toMatchObject({ type: 'TEXT', notnull: 0 });
    expect(byName.get('state')).toMatchObject({ notnull: 1, dflt_value: "'drafted'" });
    expect(byName.get('payout_confirmed_at')).toMatchObject({ notnull: 0 });
    expect(byName.get('payout_disputed_at')).toMatchObject({ notnull: 0 });
    expect(byName.get('printed_at')).toMatchObject({ notnull: 0 });
    expect(byName.get('created_at')?.dflt_value).toContain("datetime('now')");
    expect(byName.get('updated_at')?.dflt_value).toContain("datetime('now')");
  });

  it('rejects a state value outside the six-member enum (CHECK constraint)', async () => {
    await runMigrationsUpTo(adapter, 65);

    await expect(
      adapter.execute(
        `INSERT INTO refund_intents (
           id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
           line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
           override_source_event_id, state
         ) VALUES ('i1', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a1', 'o1', 'bogus_state')`,
      ),
    ).rejects.toBeDefined();
  });

  it('accepts every one of the six declared state values', async () => {
    await runMigrationsUpTo(adapter, 65);

    const states = [
      'drafted',
      'approval_authored',
      'refund_event_appended',
      'synced',
      'dead_lettered_local',
      'abandoned',
    ];
    for (const [index, state] of states.entries()) {
      await adapter.execute(
        `INSERT INTO refund_intents (
           id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
           line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
           override_source_event_id, state
         ) VALUES ($1, 't1', 'op1', $2, 'f1', '{}', $3, 'a1', 'o1', $4)`,
        [`i${String(index)}`, `r${String(index)}`, `fp${String(index)}`, state],
      );
    }
    const rows = await adapter.select<Array<{ n: number }>>(
      'SELECT COUNT(*) as n FROM refund_intents',
    );
    expect(rows[0]?.n).toBe(6);
  });

  it('active-intent partial unique index: two ACTIVE-state rows for the same original+fingerprint collide', async () => {
    await runMigrationsUpTo(adapter, 65);

    await adapter.execute(
      `INSERT INTO refund_intents (
         id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
         line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
         override_source_event_id, state
       ) VALUES ('i1', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a1', 'o1', 'drafted')`,
    );

    await expect(
      adapter.execute(
        `INSERT INTO refund_intents (
           id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
           line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
           override_source_event_id, state
         ) VALUES ('i2', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a2', 'o2', 'approval_authored')`,
      ),
    ).rejects.toBeDefined();
  });

  it('active-intent partial unique index: a SYNCED row does NOT block a new intent for the same original+fingerprint (Revision 4 fold-item-7 fix)', async () => {
    await runMigrationsUpTo(adapter, 65);

    await adapter.execute(
      `INSERT INTO refund_intents (
         id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
         line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
         override_source_event_id, state
       ) VALUES ('i1', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a1', 'o1', 'synced')`,
    );

    // Must NOT throw -- a successfully-synced partial refund must never
    // permanently block a subsequent legitimate partial refund of a
    // different, valid quantity against the same original+line selection.
    await expect(
      adapter.execute(
        `INSERT INTO refund_intents (
           id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
           line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
           override_source_event_id, state
         ) VALUES ('i2', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a2', 'o2', 'drafted')`,
      ),
    ).resolves.toBeDefined();
  });

  it('active-intent partial unique index: dead_lettered_local and abandoned rows also do not block a new intent', async () => {
    await runMigrationsUpTo(adapter, 65);

    await adapter.execute(
      `INSERT INTO refund_intents (
         id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
         line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
         override_source_event_id, state
       ) VALUES ('i1', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a1', 'o1', 'dead_lettered_local')`,
    );
    await adapter.execute(
      `INSERT INTO refund_intents (
         id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
         line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
         override_source_event_id, state
       ) VALUES ('i2', 't1', 'op1', 'r2', 'f1', '{}', 'fp2', 'a2', 'o2', 'abandoned')`,
    );

    await expect(
      adapter.execute(
        `INSERT INTO refund_intents (
           id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
           line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
           override_source_event_id, state
         ) VALUES ('i3', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a3', 'o3', 'drafted')`,
      ),
    ).resolves.toBeDefined();
  });

  it('adds offline_receipts.receipt_kind, defaulting every pre-existing row to sale', async () => {
    await runMigrationsUpTo(adapter, 64);
    // A pre-migration row -- inserted before v65 adds the column.
    await adapter.execute(
      `INSERT INTO offline_receipts (
         id, idempotency_key, receipt_number, terminal_id, terminal_code, operator_id,
         operator_name, lines, subtotal, tax_amount, discount_amount, total, currency,
         fiscal_hash, previous_hash, hash_sequence, payment_method_id, payment_repository_id,
         status, payments_json
       ) VALUES (
         'r1', 'idem-1', 'R-0001', 't1', 'T01', 'op1', 'Alice', '[]', '10.00', '2.00', '0',
         '12.00', 'EUR', ${"'" + 'a'.repeat(64) + "'"}, ${"'" + 'b'.repeat(64) + "'"}, 1,
         'pm1', 'pr1', 'pending', '[]'
       )`,
    );

    await runMigrationVersion(adapter, 65);

    const byName = await columnsOf(adapter, 'offline_receipts');
    expect(byName.get('receipt_kind')).toMatchObject({ type: 'TEXT', notnull: 1, dflt_value: "'sale'" });

    const rows = await adapter.select<Array<{ receipt_kind: string }>>(
      "SELECT receipt_kind FROM offline_receipts WHERE id = 'r1'",
    );
    expect(rows[0]?.receipt_kind).toBe('sale');
  });

  it('rejects an offline_receipts.receipt_kind value outside sale|refund (CHECK constraint)', async () => {
    await runMigrationsUpTo(adapter, 65);

    await expect(
      adapter.execute(
        `INSERT INTO offline_receipts (
           id, idempotency_key, receipt_number, terminal_id, terminal_code, operator_id,
           operator_name, lines, subtotal, tax_amount, discount_amount, total, currency,
           fiscal_hash, previous_hash, hash_sequence, payment_method_id, payment_repository_id,
           status, payments_json, receipt_kind
         ) VALUES (
           'r2', 'idem-2', 'R-0002', 't1', 'T01', 'op1', 'Alice', '[]', '10.00', '2.00', '0',
           '12.00', 'EUR', ${"'" + 'a'.repeat(64) + "'"}, ${"'" + 'b'.repeat(64) + "'"}, 1,
           'pm1', 'pr1', 'pending', '[]', 'bogus_kind'
         )`,
      ),
    ).rejects.toBeDefined();
  });

  it('adds terminal_state.v4_refund_authoring_enabled (default false) and v4_refund_authoring_acknowledged_at (nullable)', async () => {
    await runMigrationsUpTo(adapter, 65);

    const byName = await columnsOf(adapter, 'terminal_state');
    expect(byName.get('v4_refund_authoring_enabled')).toMatchObject({
      type: 'INTEGER',
      notnull: 1,
      dflt_value: '0',
    });
    expect(byName.get('v4_refund_authoring_acknowledged_at')).toMatchObject({ notnull: 0 });
  });

  it('is idempotent -- re-running v65 does not error and preserves rows', async () => {
    await runMigrationsUpTo(adapter, 65);
    await adapter.execute(
      `INSERT INTO refund_intents (
         id, terminal_id, operator_id, original_local_receipt_id, original_fiscal_event_id,
         line_snapshot_json, line_snapshot_fingerprint, approval_source_event_id,
         override_source_event_id, state
       ) VALUES ('i1', 't1', 'op1', 'r1', 'f1', '{}', 'fp1', 'a1', 'o1', 'drafted')`,
    );

    await runMigrationVersion(adapter, 65);

    const rows = await adapter.select<Array<{ n: number }>>(
      'SELECT COUNT(*) as n FROM refund_intents',
    );
    expect(rows[0]?.n).toBe(1);
  });
});
