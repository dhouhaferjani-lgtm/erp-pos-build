import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { migrations } from '@/lib/db/migrations';
import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';

const nodeSqliteAvailable = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function runMigrationsUpTo(adapter: SqliteTestAdapter, maxVersion: number): Promise<void> {
  for (const migration of migrations) {
    if (migration.version > maxVersion) continue;
    if (migration.run) {
      await migration.run(adapter);
    } else if (migration.sql) {
      await adapter.execute(migration.sql);
    }
  }
}

interface ColumnInfo {
  name: string;
  type: string;
  notnull: number;
  dflt_value: string | null;
  pk: number;
}

interface IndexInfo {
  name: string;
  unique: number;
  origin: string;
  partial: number;
}

interface IndexColumnInfo {
  name: string;
  seqno: number;
  cid: number;
}

/**
 * Insert a fiscal_event with sensible defaults. Returns the row's `id` for
 * follow-up UPDATE/DELETE assertions.
 */
async function insertFiscalEvent(
  adapter: SqliteTestAdapter,
  overrides: {
    id?: string;
    terminal_id?: string;
    sequence_number?: number;
    previous_hash?: string;
    current_hash?: string;
    source_event_class?: string | null;
    source_event_id?: string | null;
  } = {},
): Promise<string> {
  const id = overrides.id ?? `evt-${Math.random().toString(36).slice(2, 10)}`;
  const terminal_id = overrides.terminal_id ?? 'terminal-1';
  const sequence_number = overrides.sequence_number ?? 1;
  // 64-char lowercase hex placeholders.
  const previous_hash = overrides.previous_hash ?? 'a'.repeat(64);
  const current_hash = overrides.current_hash ?? 'b'.repeat(64);
  const source_event_class = overrides.source_event_class ?? null;
  const source_event_id = overrides.source_event_id ?? null;

  await adapter.execute(
    `INSERT INTO fiscal_events (
       id, tenant_id, company_id, terminal_id, operator_id,
       event_type, event_version, signature_version,
       sequence_number, event_time_device, business_date,
       canonical_bytes, previous_hash, current_hash,
       source_event_class, source_event_id,
       created_at
     ) VALUES (
       $1, 'tenant-1', 'company-1', $2, 'op-1',
       'SALE_RECEIPT', 1, 'hash-chain-integrity-v1',
       $3, '2026-05-16T10:00:00Z', '2026-05-16',
       '{"x":1}', $4, $5,
       $6, $7,
       '2026-05-16T10:00:00Z'
     )`,
    [
      id,
      terminal_id,
      sequence_number,
      previous_hash,
      current_hash,
      source_event_class,
      source_event_id,
    ],
  );

  return id;
}

d('Migration v37 — create_fiscal_events_and_chain_head', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 37);
  });

  afterEach(() => {
    adapter.close();
  });

  // ------------------------------------------------------------------
  // fiscal_events: column shape
  // ------------------------------------------------------------------

  it('creates fiscal_events with the spec §3.1 column set', async () => {
    const cols = await adapter.select<ColumnInfo[]>(`PRAGMA table_info(fiscal_events)`);
    const names = new Set(cols.map((c) => c.name));

    const required = [
      'id',
      'tenant_id',
      'company_id',
      'terminal_id',
      'operator_id',
      'event_type',
      'event_version',
      'signature_version',
      'sequence_number',
      'event_time_device',
      'business_date',
      'last_server_time_seen',
      'reference_event_id',
      'reference_document_id',
      'source_event_class',
      'source_event_id',
      'partner_id',
      'partner_identity_snapshot',
      'canonical_bytes',
      'previous_hash',
      'current_hash',
      'signature_status',
      'signature_algorithm',
      'signature_value',
      'signature_counter',
      'signature_provider',
      'signing_device_id',
      'certificate_id',
      'signed_payload_ref',
      'time_source_value',
      'time_format',
      'provider_transaction_id',
      'sync_status',
      'sync_error',
      'created_at',
      'synced_at',
    ];

    for (const c of required) {
      expect(names.has(c), `fiscal_events missing column ${c}`).toBe(true);
    }
  });

  it('enforces UNIQUE (tenant_id, terminal_id, sequence_number) chain invariant', async () => {
    await insertFiscalEvent(adapter, { id: 'e1', terminal_id: 't1', sequence_number: 1 });

    // Different terminal at same sequence — allowed.
    await expect(
      insertFiscalEvent(adapter, { id: 'e2', terminal_id: 't2', sequence_number: 1 }),
    ).resolves.toBe('e2');

    // Same (tenant, terminal, sequence) — rejected.
    await expect(
      insertFiscalEvent(adapter, { id: 'e3', terminal_id: 't1', sequence_number: 1 }),
    ).rejects.toThrow();
  });

  it('enforces source-event idempotency via partial unique index', async () => {
    await insertFiscalEvent(adapter, {
      id: 'e1',
      sequence_number: 1,
      source_event_class: 'Offline\\Receipt',
      source_event_id: 'src-uuid-1',
    });

    // Duplicate (class, id) — rejected.
    await expect(
      insertFiscalEvent(adapter, {
        id: 'e2',
        sequence_number: 2,
        source_event_class: 'Offline\\Receipt',
        source_event_id: 'src-uuid-1',
      }),
    ).rejects.toThrow();

    // Two events with NULL source — allowed (partial index excludes NULL).
    await insertFiscalEvent(adapter, { id: 'e3', sequence_number: 3 });
    await expect(
      insertFiscalEvent(adapter, { id: 'e4', sequence_number: 4 }),
    ).resolves.toBe('e4');
  });

  it('rejects malformed previous_hash / current_hash via CHECK constraint', async () => {
    await expect(
      insertFiscalEvent(adapter, {
        id: 'bad',
        sequence_number: 1,
        current_hash: 'NOT_HEX',
      }),
    ).rejects.toThrow();
  });

  // ------------------------------------------------------------------
  // fiscal_events: immutability triggers
  // ------------------------------------------------------------------

  it('blocks UPDATE of non-sync-lifecycle columns', async () => {
    await insertFiscalEvent(adapter, { id: 'e1', sequence_number: 1 });

    await expect(
      adapter.execute(`UPDATE fiscal_events SET current_hash = '${'c'.repeat(64)}' WHERE id = 'e1'`),
    ).rejects.toThrow();

    await expect(
      adapter.execute(`UPDATE fiscal_events SET sequence_number = 999 WHERE id = 'e1'`),
    ).rejects.toThrow();

    await expect(
      adapter.execute(`UPDATE fiscal_events SET canonical_bytes = '{"y":2}' WHERE id = 'e1'`),
    ).rejects.toThrow();
  });

  it('allows UPDATE of sync_status / sync_error / synced_at', async () => {
    await insertFiscalEvent(adapter, { id: 'e1', sequence_number: 1 });

    await expect(
      adapter.execute(`UPDATE fiscal_events SET sync_status = 'synced' WHERE id = 'e1'`),
    ).resolves.toBeTruthy();

    await expect(
      adapter.execute(`UPDATE fiscal_events SET sync_error = 'boom' WHERE id = 'e1'`),
    ).resolves.toBeTruthy();

    await expect(
      adapter.execute(
        `UPDATE fiscal_events SET synced_at = '2026-05-16T10:30:00Z' WHERE id = 'e1'`,
      ),
    ).resolves.toBeTruthy();

    const rows = await adapter.select<Array<{ sync_status: string; sync_error: string | null }>>(
      `SELECT sync_status, sync_error FROM fiscal_events WHERE id = 'e1'`,
    );
    expect(rows[0]?.sync_status).toBe('synced');
    expect(rows[0]?.sync_error).toBe('boom');
  });

  it('blocks any DELETE on fiscal_events', async () => {
    await insertFiscalEvent(adapter, { id: 'e1', sequence_number: 1 });

    await expect(adapter.execute(`DELETE FROM fiscal_events WHERE id = 'e1'`)).rejects.toThrow();
    await expect(adapter.execute(`DELETE FROM fiscal_events`)).rejects.toThrow();
  });

  // ------------------------------------------------------------------
  // terminal_state chain head
  // ------------------------------------------------------------------

  it('adds the fiscal-event chain head columns to terminal_state', async () => {
    const cols = await adapter.select<ColumnInfo[]>(`PRAGMA table_info(terminal_state)`);
    const byName = new Map(cols.map((c) => [c.name, c]));

    expect(byName.has('fiscal_event_genesis_seed')).toBe(true);
    expect(byName.has('fiscal_event_last_hash')).toBe(true);
    expect(byName.has('fiscal_event_sequence')).toBe(true);

    // Legacy mirror columns must remain.
    expect(byName.has('last_hash')).toBe(true);
    expect(byName.has('hash_sequence')).toBe(true);
    expect(byName.has('genesis_seed')).toBe(true);
  });

  it('inherits a NOT NULL DEFAULT chain head — existing rows backfill cleanly', async () => {
    await adapter.execute(
      `INSERT INTO terminal_state (terminal_id, terminal_code, genesis_seed, last_hash)
       VALUES ('t-new', 'T01', 'seed', 'GENESIS')`,
    );

    const rows = await adapter.select<
      Array<{
        fiscal_event_genesis_seed: string | null;
        fiscal_event_last_hash: string | null;
        fiscal_event_sequence: number;
      }>
    >(`SELECT fiscal_event_genesis_seed, fiscal_event_last_hash, fiscal_event_sequence
        FROM terminal_state WHERE terminal_id = 't-new'`);

    expect(rows[0]?.fiscal_event_genesis_seed).toBe('');
    expect(rows[0]?.fiscal_event_last_hash).toBe('');
    expect(rows[0]?.fiscal_event_sequence).toBe(0);
  });

  // ------------------------------------------------------------------
  // offline_receipts.canonical_bytes
  // ------------------------------------------------------------------

  it('adds offline_receipts.canonical_bytes as a NULLABLE TEXT mirror', async () => {
    const cols = await adapter.select<ColumnInfo[]>(`PRAGMA table_info(offline_receipts)`);
    const col = cols.find((c) => c.name === 'canonical_bytes');
    expect(col, 'offline_receipts.canonical_bytes missing').toBeDefined();
    // Nullable so projection-only callers don't break before fiscal-event wiring (Task 15).
    expect(col?.notnull).toBe(0);
  });

  // ------------------------------------------------------------------
  // Idempotency safeguard — re-running the migration is a no-op
  // ------------------------------------------------------------------

  it('is idempotent when applied a second time over an existing DB', async () => {
    const v37 = migrations.find((m) => m.version === 37);
    if (!v37) throw new Error('migration v37 not found');

    // Insert a row before second-run.
    await insertFiscalEvent(adapter, { id: 'e1', sequence_number: 1 });

    // Re-run.
    if (v37.run) {
      await v37.run(adapter);
    } else {
      await adapter.execute(v37.sql);
    }

    // Row + triggers still intact.
    await expect(adapter.execute(`DELETE FROM fiscal_events WHERE id = 'e1'`)).rejects.toThrow();
    const cols = await adapter.select<ColumnInfo[]>(`PRAGMA table_info(fiscal_events)`);
    expect(cols.length).toBeGreaterThan(30);
  });

  // ------------------------------------------------------------------
  // Index introspection (sanity)
  // ------------------------------------------------------------------

  it('creates the canonical chain index on (tenant_id, terminal_id, sequence_number)', async () => {
    const indexes = await adapter.select<IndexInfo[]>(`PRAGMA index_list(fiscal_events)`);

    // Find the unique index covering the chain triple.
    let found = false;
    for (const idx of indexes) {
      if (!idx.unique) continue;
      const idxCols = await adapter.select<IndexColumnInfo[]>(
        `PRAGMA index_info(${idx.name})`,
      );
      const colNames = idxCols.map((c) => c.name).join(',');
      if (colNames === 'tenant_id,terminal_id,sequence_number') {
        found = true;
        break;
      }
    }
    expect(found, 'chain UNIQUE (tenant_id,terminal_id,sequence_number) missing').toBe(true);
  });
});
