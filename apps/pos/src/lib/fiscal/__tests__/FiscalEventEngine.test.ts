/**
 * Task 15 — `FiscalEventEngine.append()` coverage.
 *
 * The engine is the single device chain authoring path (spec v7 §6.1). The
 * tests below run against the in-process `SqliteTestAdapter` (the same shim
 * the migration tests use) so we exercise the real v37 schema — CHECK
 * constraints, triggers, partial UNIQUE indexes and all — rather than a
 * mock.
 *
 * Each test sets up the chain head explicitly (`fiscal_event_genesis_seed`
 * set, sequence = 0). Tests that prove a caller's rollback leaves no trace
 * issue an explicit `BEGIN` / `ROLLBACK` around the engine call, since
 * `@tauri-apps/plugin-sql` (and therefore our adapter) has no first-class
 * `transaction()` helper.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { migrations } from '@/lib/db/migrations';
import {
  ChainHeadNotInitializedError,
  FiscalEventEngine,
  type FiscalEventAppendRequest,
} from '../FiscalEventEngine';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import {
  FiscalEventPayloadRegistry,
  FiscalEventTypeNotImplementedError,
} from '../FiscalEventPayloadRegistry';
import { HashChainIntegrityProvider } from '../HashChainIntegrityProvider';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';

const nodeSqliteAvailable = (() => {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

const TENANT_ID = 'tenant-1';
const COMPANY_ID = 'company-1';
const TERMINAL_ID = 'terminal-1';
const OPERATOR_ID = 'operator-1';
const GENESIS_SEED = 'a'.repeat(64);
const ALT_GENESIS_SEED = '1' + 'f'.repeat(63);

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

async function seedTerminalState(
  adapter: SqliteTestAdapter,
  opts: {
    terminal_id?: string;
    fiscal_event_genesis_seed?: string;
    fiscal_event_last_hash?: string;
    fiscal_event_sequence?: number;
  } = {},
): Promise<void> {
  const terminalId = opts.terminal_id ?? TERMINAL_ID;
  const genesisSeed = opts.fiscal_event_genesis_seed ?? GENESIS_SEED;
  const lastHash = opts.fiscal_event_last_hash ?? '';
  const sequence = opts.fiscal_event_sequence ?? 0;

  await adapter.execute(
    `INSERT INTO terminal_state (
       terminal_id, terminal_code, genesis_seed, last_hash,
       fiscal_event_genesis_seed, fiscal_event_last_hash, fiscal_event_sequence
     ) VALUES ($1, 'T01', 'legacy-seed', 'legacy-hash', $2, $3, $4)`,
    [terminalId, genesisSeed, lastHash, sequence],
  );
}

function saleReceiptRequest(
  overrides: Partial<FiscalEventAppendRequest> = {},
): FiscalEventAppendRequest {
  return {
    event_type: 'SALE_RECEIPT',
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    terminal_id: TERMINAL_ID,
    operator_id: OPERATOR_ID,
    event_time_device: '2026-05-16T10:00:00Z',
    business_date: '2026-05-16',
    payload: {
      lines: [{ sku: 'A', qty: 1, unit_price: '10.000', total: '10.000' }],
      total: '10.000',
    },
    ...overrides,
  };
}

interface FiscalEventRowDb {
  id: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  event_type: string;
  event_version: number;
  signature_version: string;
  sequence_number: number;
  event_time_device: string;
  business_date: string;
  reference_event_id: string | null;
  reference_document_id: string | null;
  source_event_class: string | null;
  source_event_id: string | null;
  canonical_bytes: string;
  previous_hash: string;
  current_hash: string;
  sync_status: string;
  signature_status: string;
  created_at: string;
}

async function selectAllEvents(adapter: SqliteTestAdapter): Promise<FiscalEventRowDb[]> {
  return adapter.select<FiscalEventRowDb[]>(
    `SELECT id, tenant_id, company_id, terminal_id, operator_id,
            event_type, event_version, signature_version,
            sequence_number, event_time_device, business_date,
            reference_event_id, reference_document_id,
            source_event_class, source_event_id,
            canonical_bytes, previous_hash, current_hash,
            sync_status, signature_status, created_at
       FROM fiscal_events
      ORDER BY sequence_number ASC`,
  );
}

async function selectChainHead(
  adapter: SqliteTestAdapter,
  terminalId: string = TERMINAL_ID,
): Promise<{
  fiscal_event_last_hash: string;
  fiscal_event_sequence: number;
  fiscal_event_genesis_seed: string;
}> {
  const rows = await adapter.select<
    Array<{
      fiscal_event_last_hash: string;
      fiscal_event_sequence: number;
      fiscal_event_genesis_seed: string;
    }>
  >(
    `SELECT fiscal_event_last_hash, fiscal_event_sequence, fiscal_event_genesis_seed
       FROM terminal_state WHERE terminal_id = $1`,
    [terminalId],
  );
  if (!rows[0]) {
    throw new Error(`terminal_state row for ${terminalId} not found`);
  }
  return rows[0];
}

d('FiscalEventEngine.append', () => {
  let adapter: SqliteTestAdapter;
  let engine: FiscalEventEngine;
  const encoder = new FiscalEventCanonicalEncoder();
  const integrityProvider = new HashChainIntegrityProvider();
  const registry = new FiscalEventPayloadRegistry();

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 37);
    await seedTerminalState(adapter);
    engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
  });

  afterEach(() => {
    adapter.close();
  });

  // -------------------------------------------------------------------
  // Chain head — first event links to the genesis seed
  // -------------------------------------------------------------------

  it('first event links previous_hash to the genesis seed and sets sequence_number = 1', async () => {
    const event = await engine.append(adapter, saleReceiptRequest());

    expect(event.sequence_number).toBe(1);
    expect(event.previous_hash).toBe(GENESIS_SEED);
    expect(event.current_hash).toMatch(/^[0-9a-f]{64}$/);
    expect(event.event_version).toBe(1);
    expect(event.signature_version).toBe('hash-chain-integrity-v1');
    expect(event.sync_status).toBe('pending');
    expect(event.signature_status).toBe('not_required');

    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(1);
    expect(head.fiscal_event_last_hash).toBe(event.current_hash);
  });

  // -------------------------------------------------------------------
  // Chain head — second event links to the first
  // -------------------------------------------------------------------

  it('second event chains previous_hash to the first event current_hash; sequence increments', async () => {
    const first = await engine.append(adapter, saleReceiptRequest());
    const second = await engine.append(
      adapter,
      saleReceiptRequest({ event_time_device: '2026-05-16T10:01:00Z' }),
    );

    expect(second.sequence_number).toBe(2);
    expect(second.previous_hash).toBe(first.current_hash);
    expect(second.current_hash).not.toBe(first.current_hash);

    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(2);
    expect(head.fiscal_event_last_hash).toBe(second.current_hash);
  });

  // -------------------------------------------------------------------
  // Caller-controlled rollback
  // -------------------------------------------------------------------

  it('caller rollback leaves no fiscal_events row and the chain head unchanged', async () => {
    await adapter.execute('BEGIN');
    await engine.append(adapter, saleReceiptRequest());
    await adapter.execute('ROLLBACK');

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);

    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(0);
    expect(head.fiscal_event_last_hash).toBe('');
  });

  it('caller commit persists the row + chain-head advance atomically', async () => {
    await adapter.execute('BEGIN');
    const event = await engine.append(adapter, saleReceiptRequest());
    await adapter.execute('COMMIT');

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(1);
    expect(rows[0]?.id).toBe(event.id);

    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(1);
    expect(head.fiscal_event_last_hash).toBe(event.current_hash);
  });

  // -------------------------------------------------------------------
  // Device-side idempotency on (source_event_class, source_event_id)
  // -------------------------------------------------------------------

  it('device-side idempotency: re-emitting a source-backed event returns the existing row', async () => {
    const request = saleReceiptRequest({
      source_event_class: 'OfflineReceipt',
      source_event_id: '11111111-1111-1111-1111-111111111111',
    });

    const a = await engine.append(adapter, request);
    const b = await engine.append(adapter, request);

    expect(b.id).toBe(a.id);
    expect(b.sequence_number).toBe(a.sequence_number);
    expect(b.current_hash).toBe(a.current_hash);

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(1);

    // The chain head must NOT have advanced on the idempotent return.
    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(1);
    expect(head.fiscal_event_last_hash).toBe(a.current_hash);
  });

  it('two different source-backed events both append and advance the chain', async () => {
    const a = await engine.append(
      adapter,
      saleReceiptRequest({
        source_event_class: 'OfflineReceipt',
        source_event_id: '11111111-1111-1111-1111-111111111111',
      }),
    );
    const b = await engine.append(
      adapter,
      saleReceiptRequest({
        source_event_class: 'OfflineReceipt',
        source_event_id: '22222222-2222-2222-2222-222222222222',
        event_time_device: '2026-05-16T10:02:00Z',
      }),
    );

    expect(a.sequence_number).toBe(1);
    expect(b.sequence_number).toBe(2);
    expect(b.previous_hash).toBe(a.current_hash);
  });

  // -------------------------------------------------------------------
  // Reserved-type rejection
  // -------------------------------------------------------------------

  it('throws FiscalEventTypeNotImplementedError for a reserved event type', async () => {
    await expect(
      engine.append(adapter, saleReceiptRequest({ event_type: 'SALE_VOID' })),
    ).rejects.toBeInstanceOf(FiscalEventTypeNotImplementedError);

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);

    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(0);
  });

  // -------------------------------------------------------------------
  // Empty-seed sentinel rejection (Task 13 Codex P3 forward-looking input)
  // -------------------------------------------------------------------

  it('throws ChainHeadNotInitializedError when fiscal_event_genesis_seed is the empty-string sentinel', async () => {
    // Replace seeded terminal state with one whose seed is the v37 default `''`.
    await adapter.execute(`DELETE FROM terminal_state WHERE terminal_id = $1`, [TERMINAL_ID]);
    await seedTerminalState(adapter, { fiscal_event_genesis_seed: '' });

    await expect(engine.append(adapter, saleReceiptRequest())).rejects.toBeInstanceOf(
      ChainHeadNotInitializedError,
    );

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);

    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(0);
    expect(head.fiscal_event_last_hash).toBe('');
  });

  // -------------------------------------------------------------------
  // Hash chain integrity — the stored hash actually equals sha256(canonical_bytes)
  // -------------------------------------------------------------------

  it('current_hash equals sha256(canonical_bytes); canonical_bytes are persisted verbatim', async () => {
    const event = await engine.append(adapter, saleReceiptRequest());

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(1);
    const stored = rows[0];
    expect(stored).toBeDefined();
    if (!stored) throw new Error('row missing');

    // The stored canonical_bytes must verify against the stored current_hash
    // using only the encoder's hash primitive (the server-side contract).
    expect(integrityProvider.verify(stored.canonical_bytes, stored.current_hash)).toBe(true);

    // The engine return value matches what was stored.
    expect(stored.current_hash).toBe(event.current_hash);
    expect(stored.canonical_bytes).toBe(event.canonical_bytes);
    expect(stored.previous_hash).toBe(GENESIS_SEED);
  });

  it('canonical_bytes contain only the spec §4 fields (sorted keys)', async () => {
    const event = await engine.append(adapter, saleReceiptRequest());
    const parsed: unknown = JSON.parse(event.canonical_bytes);
    expect(isRecord(parsed)).toBe(true);
    if (!isRecord(parsed)) return;

    expect(Object.keys(parsed).sort()).toEqual([
      'business_date',
      'company_id',
      'event_time_device',
      'event_type',
      'event_version',
      'operator_id',
      'payload',
      'previous_hash',
      'reference_document_id',
      'reference_event_id',
      'sequence_number',
      'signature_version',
      'tenant_id',
      'terminal_id',
    ]);

    expect(parsed.sequence_number).toBe(1);
    expect(parsed.previous_hash).toBe(GENESIS_SEED);
    expect(parsed.event_type).toBe('SALE_RECEIPT');
    expect(parsed.event_version).toBe(1);
    expect(parsed.signature_version).toBe('hash-chain-integrity-v1');
    expect(parsed.reference_event_id).toBeNull();
    expect(parsed.reference_document_id).toBeNull();
  });

  // -------------------------------------------------------------------
  // Multiple-terminal chain isolation (sanity)
  // -------------------------------------------------------------------

  it('chains on different terminals are independent', async () => {
    await seedTerminalState(adapter, {
      terminal_id: 'terminal-2',
      fiscal_event_genesis_seed: ALT_GENESIS_SEED,
    });

    const a = await engine.append(adapter, saleReceiptRequest());
    const b = await engine.append(
      adapter,
      saleReceiptRequest({ terminal_id: 'terminal-2', event_time_device: '2026-05-16T10:01:00Z' }),
    );

    expect(a.sequence_number).toBe(1);
    expect(a.previous_hash).toBe(GENESIS_SEED);
    expect(b.sequence_number).toBe(1);
    expect(b.previous_hash).toBe(ALT_GENESIS_SEED);

    const head1 = await selectChainHead(adapter, TERMINAL_ID);
    const head2 = await selectChainHead(adapter, 'terminal-2');
    expect(head1.fiscal_event_sequence).toBe(1);
    expect(head2.fiscal_event_sequence).toBe(1);
  });
});

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
