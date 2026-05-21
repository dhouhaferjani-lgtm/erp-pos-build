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
  ConcurrentChainAdvanceError,
  FiscalEventEngine,
  FiscalEventPayloadValidationError,
  SALE_RECEIPT_PAYLOAD_KEYS,
  ServerAuthoredEventTypeError,
  type FiscalEventAppendRequest,
} from '../FiscalEventEngine';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import {
  FiscalEventPayloadRegistry,
  FiscalEventTypeNotImplementedError,
} from '../FiscalEventPayloadRegistry';
import { HashChainIntegrityProvider } from '../HashChainIntegrityProvider';
import {
  ACCOUNT_PAYMENT_PAYLOAD_KEYS,
  goldenAccountPaymentPayload,
} from '../payloads/AccountPaymentPayload';
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

/**
 * A canonical-spec-correct SALE_RECEIPT payload — 27-key Candidate C-v3
 * shape per synthesis v5 §3 (Task 27B Pass 2A.TS). Every monetary /
 * quantity field is a bcformat decimal string at the relevant scale;
 * `currency_scale` is an integer in the {0, 2, 3} allowlist; nested
 * `seller` / `buyer` / `line_items` / `payments` / `vat_breakdown` /
 * `original_receipt_reference` / `vouchers_redeemed` blocks honor the
 * full TS interface in `FiscalEventEngine.ts`.
 *
 * Default shape: SALE invoice, TND (scale=3), one line at 20.00% VAT,
 * one cash payment, one partition row, no buyer/refund-ref/vouchers.
 */
const SR_TERMINAL_UUID = '11111111-1111-1111-1111-111111111111';
const SR_CASHIER_UUID = '22222222-2222-2222-2222-222222222222';
const SR_SHIFT_UUID = '33333333-3333-3333-3333-333333333333';
const SR_RECEIPT_UUID = '44444444-4444-4444-4444-444444444444';

function validSaleReceiptPayload(): Record<string, unknown> {
  return {
    business_date: '2026-05-16',
    buyer: null,
    cashier_id: SR_CASHIER_UUID,
    cashier_name: 'Alice',
    consumption_mode: null,
    currency_code: 'TND',
    currency_scale: 3,
    event_time_device: '2026-05-16T10:00:00.000Z',
    invoice_type_code: 'SALE',
    line_items: [
      {
        gtin: null,
        line_discount_amount: '0.000',
        line_discount_reason: null,
        line_subtotal: '10.000',
        line_vat: '2.000',
        name: 'Espresso',
        non_collected_subtype: null,
        product_id: 'p-1',
        quantity: '1.000',
        sku: 'A',
        tax_category_code: '',
        unit_price: '10.000',
        vat_rate: '20.00',
      },
    ],
    lottery_code: null,
    notes: null,
    original_receipt_reference: null,
    payments: [
      {
        amount: '12.000',
        foreign_currency_amount: null,
        foreign_currency_code: null,
        instrument_serial: null,
        instrument_type: null,
        method_code: 'cash',
      },
    ],
    receipt_uuid: SR_RECEIPT_UUID,
    seller: {
      address: {
        city: 'Tunis',
        country_code: 'TN',
        postal_code: '1000',
        street: '1 Rue de la Liberte',
      },
      name: 'Cafe Tunis',
      tax_jurisdiction_country_code: 'TN',
      tax_number: '1234567AM000',
    },
    shift_id: SR_SHIFT_UUID,
    subtotal: '10.000',
    table_id: null,
    terminal_id: SR_TERMINAL_UUID,
    total: '12.000',
    training_flag: false,
    transaction_discount_amount: '0.000',
    transaction_discount_reason: null,
    vat_breakdown: [
      {
        gross_amount: '12.000',
        net_amount: '10.000',
        rate: '20.00',
        tax_category_code: '',
        vat_amount: '2.000',
      },
    ],
    vat_total: '2.000',
    vouchers_redeemed: [],
  };
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
    payload: validSaleReceiptPayload(),
    ...overrides,
  };
}

function accountPaymentRequest(
  overrides: Partial<FiscalEventAppendRequest> = {},
): FiscalEventAppendRequest {
  return {
    event_type: 'ACCOUNT_PAYMENT',
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    terminal_id: TERMINAL_ID,
    operator_id: OPERATOR_ID,
    event_time_device: '2026-05-21T10:15:30Z',
    business_date: '2026-05-21',
    payload: goldenAccountPaymentPayload(),
    source_event_class: 'account_payments',
    source_event_id: '44444444-4444-4444-8444-444444444444',
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

  // -------------------------------------------------------------------
  // Round-2 Codex BLOCKER regression — payload validation rejects
  // non-string monetary fields before any state mutation.
  // -------------------------------------------------------------------

  it('round-2 BLOCKER — rejects integer in any SALE_RECEIPT top-level monetary field', async () => {
    for (const field of ['subtotal', 'vat_total', 'total', 'transaction_discount_amount']) {
      const payload = validSaleReceiptPayload();
      payload[field] = 10; // INTEGER — would silently slip past the encoder.

      await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
        FiscalEventPayloadValidationError,
      );

      // State unchanged after each rejection.
      const rows = await adapter.select<Array<{ c: number }>>(
        `SELECT COUNT(*) AS c FROM fiscal_events`,
      );
      expect(rows[0]?.c).toBe(0);
      const head = await selectChainHead(adapter, TERMINAL_ID);
      expect(head.fiscal_event_sequence).toBe(0);
    }
  });

  it('round-2 BLOCKER — rejects missing currency_code / non-int currency_scale / non-array line_items', async () => {
    const cases: Array<Record<string, unknown>> = [
      // currency_code missing entirely → key-set check triggers.
      omitKey(validSaleReceiptPayload(), 'currency_code'),
      // currency_scale wrong types.
      { ...validSaleReceiptPayload(), currency_scale: '3' },
      { ...validSaleReceiptPayload(), currency_scale: 3.5 },
      // line_items not an array.
      { ...validSaleReceiptPayload(), line_items: 'not-an-array' },
    ];

    for (const payload of cases) {
      await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
        FiscalEventPayloadValidationError,
      );
    }
  });

  // -------------------------------------------------------------------
  // Round-2 P1-1 — envelope timestamp / business_date format
  // -------------------------------------------------------------------

  it('round-2 P1-1 — rejects event_time_device with millisecond precision', async () => {
    await expect(
      engine.append(adapter, saleReceiptRequest({ event_time_device: '2026-05-16T10:00:00.000Z' })),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });

  it('round-2 P1-1 — rejects event_time_device with non-UTC offset', async () => {
    await expect(
      engine.append(adapter, saleReceiptRequest({ event_time_device: '2026-05-16T10:00:00+02:00' })),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });

  it('round-2 P1-1 — rejects malformed business_date', async () => {
    await expect(
      engine.append(adapter, saleReceiptRequest({ business_date: '16/05/2026' })),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });

  // -------------------------------------------------------------------
  // Round-2 P2-1 — cross-terminal source-event idempotency scope
  // -------------------------------------------------------------------

  it('round-2 P2-1 — same source_event_id on a different terminal is NOT a silent cross-terminal idempotent match', async () => {
    // Pre-fix behavior (the BUG): `findBySource` queried the partial
    // UNIQUE columns only — so `'r-1'` on terminal A "matched" `'r-1'`
    // on terminal B, and terminal B's append silently returned
    // terminal A's row (cross-terminal pollution).
    //
    // Post-fix behavior: the engine's lookup is scoped to
    // `(tenant_id, terminal_id, ...)` so it does NOT return a
    // cross-terminal match. The DB-level partial UNIQUE on
    // `(source_event_class, source_event_id)` then surfaces the actual
    // cross-terminal collision as a typed `UNIQUE constraint failed` —
    // the caller / recovery flow sees a loud failure instead of silent
    // pollution. Loud failure beats silent data corruption.
    //
    // The production assumption is that `source_event_id` is GLOBALLY
    // UNIQUE (UUIDs or composite keys); cross-terminal collisions are
    // a developer-error case, not a production occurrence.
    await seedTerminalState(adapter, {
      terminal_id: 'terminal-2',
      fiscal_event_genesis_seed: ALT_GENESIS_SEED,
    });

    const a = await engine.append(
      adapter,
      saleReceiptRequest({
        source_event_class: 'OfflineReceipt',
        source_event_id: 'r-1',
      }),
    );
    expect(a.terminal_id).toBe(TERMINAL_ID);

    // Cross-terminal collision — must NOT silently return terminal A's
    // row. The DB-level partial UNIQUE catches the INSERT.
    await expect(
      engine.append(
        adapter,
        saleReceiptRequest({
          terminal_id: 'terminal-2',
          event_time_device: '2026-05-16T10:01:00Z',
          source_event_class: 'OfflineReceipt',
          source_event_id: 'r-1',
        }),
      ),
    ).rejects.toThrow(/UNIQUE constraint failed/);

    // Exactly ONE row exists — terminal A's. Terminal B's INSERT was
    // rejected without polluting the chain.
    const rows = await adapter.select<Array<{ c: number; terminal_id: string }>>(
      `SELECT COUNT(*) AS c, MIN(terminal_id) AS terminal_id FROM fiscal_events`,
    );
    expect(rows[0]?.c).toBe(1);
    expect(rows[0]?.terminal_id).toBe(TERMINAL_ID);
  });

  it('round-2 P2-1 — re-emitting the same source on the SAME terminal still returns the existing row', async () => {
    const a = await engine.append(
      adapter,
      saleReceiptRequest({
        source_event_class: 'OfflineReceipt',
        source_event_id: 'r-2',
      }),
    );
    const b = await engine.append(
      adapter,
      saleReceiptRequest({
        source_event_class: 'OfflineReceipt',
        source_event_id: 'r-2',
      }),
    );

    expect(b.id).toBe(a.id);
    const rows = await adapter.select<Array<{ c: number }>>(
      `SELECT COUNT(*) AS c FROM fiscal_events`,
    );
    expect(rows[0]?.c).toBe(1);
  });

  // -------------------------------------------------------------------
  // Round-2 P2-2 — parallel chain advance surfaces as a typed error
  // -------------------------------------------------------------------

  it('round-2 P2-2 — chain UNIQUE violation surfaces as ConcurrentChainAdvanceError', async () => {
    // Simulate a race: manually INSERT a row at sequence_number 1 (as if
    // another transaction won the race), then attempt `append()` —
    // which will compute sequence_number = 1 from the unadvanced chain
    // head and trip the v37 chain UNIQUE.
    await adapter.execute(
      `INSERT INTO fiscal_events (
         id, tenant_id, company_id, terminal_id, operator_id,
         event_type, event_version, signature_version,
         sequence_number, event_time_device, business_date,
         canonical_bytes, previous_hash, current_hash,
         signature_status, sync_status, created_at
       ) VALUES (
         'racer-1', $1, $2, $3, $4,
         'SALE_RECEIPT', 1, 'hash-chain-integrity-v1',
         1, '2026-05-16T10:00:00Z', '2026-05-16',
         '{}', $5, $6,
         'not_required', 'pending', '2026-05-16T10:00:00Z'
       )`,
      [TENANT_ID, COMPANY_ID, TERMINAL_ID, OPERATOR_ID, GENESIS_SEED, 'c'.repeat(64)],
    );

    // Now append() should compute sequence=1 and trip the UNIQUE.
    await expect(engine.append(adapter, saleReceiptRequest())).rejects.toThrow(
      ConcurrentChainAdvanceError,
    );
  });

  // -------------------------------------------------------------------
  // Round-2 P3 — genesis-seed sentinel hardening (also reject 'GENESIS',
  // non-hex, '0'*64, uppercase, wrong length).
  // -------------------------------------------------------------------

  it("round-2 P3 — rejects 'GENESIS' literal seed", async () => {
    await adapter.execute(
      `UPDATE terminal_state SET fiscal_event_genesis_seed = 'GENESIS' WHERE terminal_id = $1`,
      [TERMINAL_ID],
    );
    await expect(engine.append(adapter, saleReceiptRequest())).rejects.toThrow(
      ChainHeadNotInitializedError,
    );
  });

  it('round-2 P3 — rejects uppercase-hex seed', async () => {
    await adapter.execute(
      `UPDATE terminal_state SET fiscal_event_genesis_seed = $1 WHERE terminal_id = $2`,
      ['A'.repeat(64), TERMINAL_ID],
    );
    await expect(engine.append(adapter, saleReceiptRequest())).rejects.toThrow(
      ChainHeadNotInitializedError,
    );
  });

  it('round-2 P3 — rejects 63-char (wrong length) seed', async () => {
    await adapter.execute(
      `UPDATE terminal_state SET fiscal_event_genesis_seed = $1 WHERE terminal_id = $2`,
      ['a'.repeat(63), TERMINAL_ID],
    );
    await expect(engine.append(adapter, saleReceiptRequest())).rejects.toThrow(
      ChainHeadNotInitializedError,
    );
  });

  // -------------------------------------------------------------------
  // Task 26 round-2 — spec §11.0 server-authoring carve-out enforcement
  //
  // Per spec v7 §11.0 invariant #5, the device-side engine MUST reject
  // company-integrity event types — TERMINAL_REGISTRY_SNAPSHOT (implemented)
  // and COMPANY_DAY_CLOSURE_MANIFEST (reserved). They are server-authored
  // facts; allowing the device to author them would re-open the §1 device-
  // authority ambiguity that §11.0 carves out. The TS rejection mirrors
  // the PHP `InvalidServerAuthoredPayloadException` cross-language drift
  // gate (Task 14 standing pattern) — TS must reject what the spec
  // declares server-only.
  //
  // The rejection happens BEFORE any state mutation: no fiscal_events
  // row, no chain-head advance. Error type is the dedicated
  // `ServerAuthoredEventTypeError` so callers can distinguish "wrong
  // authoring layer" from "reserved but not yet implemented".
  // -------------------------------------------------------------------

  it('Task 26 §11.0 — rejects TERMINAL_REGISTRY_SNAPSHOT with ServerAuthoredEventTypeError', async () => {
    const trsRequest: FiscalEventAppendRequest = {
      event_type: 'TERMINAL_REGISTRY_SNAPSHOT',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      terminal_id: TERMINAL_ID,
      operator_id: OPERATOR_ID,
      event_time_device: '2026-05-16T10:00:00Z',
      business_date: '2026-05-16',
      payload: {
        terminals: [],
        snapshot_hash: 'a'.repeat(64),
        prior_snapshot_link: null,
      },
    };

    await expect(engine.append(adapter, trsRequest)).rejects.toBeInstanceOf(
      ServerAuthoredEventTypeError,
    );

    // No state mutation.
    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);
    const head = await selectChainHead(adapter, TERMINAL_ID);
    expect(head.fiscal_event_sequence).toBe(0);
  });

  it('Task 26 §11.0 — rejects COMPANY_DAY_CLOSURE_MANIFEST with ServerAuthoredEventTypeError (precedence over not-implemented)', async () => {
    // COMPANY_DAY_CLOSURE_MANIFEST is reserved + server-only. The §11.0
    // rejection MUST run before the registry's not-implemented check so
    // the boundary is locked even before the type lands. Once implemented
    // (later phase), the same §11.0 rejection still applies.
    const manifestRequest: FiscalEventAppendRequest = {
      event_type: 'COMPANY_DAY_CLOSURE_MANIFEST',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      terminal_id: TERMINAL_ID,
      operator_id: OPERATOR_ID,
      event_time_device: '2026-05-16T10:00:00Z',
      business_date: '2026-05-16',
      payload: {},
    };

    await expect(engine.append(adapter, manifestRequest)).rejects.toBeInstanceOf(
      ServerAuthoredEventTypeError,
    );

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);
  });

  it('Task 26 §11.0 — ServerAuthoredEventTypeError message cites spec §11.0 and the event type', async () => {
    const trsRequest: FiscalEventAppendRequest = {
      event_type: 'TERMINAL_REGISTRY_SNAPSHOT',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      terminal_id: TERMINAL_ID,
      operator_id: OPERATOR_ID,
      event_time_device: '2026-05-16T10:00:00Z',
      business_date: '2026-05-16',
      payload: {
        terminals: [],
        snapshot_hash: 'a'.repeat(64),
        prior_snapshot_link: null,
      },
    };

    let thrown: unknown;
    try {
      await engine.append(adapter, trsRequest);
    } catch (error) {
      thrown = error;
    }
    expect(thrown).toBeInstanceOf(ServerAuthoredEventTypeError);
    const message = (thrown as Error).message;
    expect(message).toMatch(/TERMINAL_REGISTRY_SNAPSHOT/);
    expect(message).toMatch(/§11\.0|11\.0/);
  });

  // -------------------------------------------------------------------
  // Task 27B Pass 2A.TS — 27-key SALE_RECEIPT canonical contract.
  //
  // Mirrors the PHP `FiscalPayloadConstraintValidator::validateSaleReceiptPayload`
  // STRUCTURAL conformance set (key set + types + regex + enums +
  // foreign-currency pairing + training-flag invariant + discount-reason
  // consistency). The PARTITION algorithm + invoice-total arithmetic
  // stay server-side per synthesis v5 §6.F — those are NOT covered here.
  //
  // Standing pattern: discriminated-union test matrix exhaustively in
  // round-1 (Task 20). Every failure mode the validator raises gets a
  // dedicated test below; every cross-language drift gate (TS must
  // reject what PHP rejects) is asserted by symmetric error-prefix
  // matching.
  // -------------------------------------------------------------------

  it('Pass 2A.TS — happy path: 27-key SALE_RECEIPT payload validates and seals', async () => {
    const event = await engine.append(adapter, saleReceiptRequest());
    expect(event.sequence_number).toBe(1);
    expect(event.current_hash).toMatch(/^[0-9a-f]{64}$/);
  });

  it('Pass 2A.TS — rejects an extra top-level key (28th)', async () => {
    const payload = { ...validSaleReceiptPayload(), evil_extra: 'x' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_extra_field:evil_extra/,
    );
  });

  it('Pass 2A.TS — rejects a payload missing a required key', async () => {
    for (const required of SALE_RECEIPT_PAYLOAD_KEYS) {
      const payload = omitKey(validSaleReceiptPayload(), required);
      await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
        new RegExp(`payload_missing_required:.*\\b${required}\\b`),
      );
    }
  });

  it('Pass 2A.TS — rejects non-UUID cashier_id', async () => {
    const payload = { ...validSaleReceiptPayload(), cashier_id: 'not-a-uuid' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_field_invalid:cashier_id/,
    );
  });

  it('Pass 2A.TS — rejects ISO 8601 datetime missing ms', async () => {
    const payload = { ...validSaleReceiptPayload(), event_time_device: '2026-05-16T10:00:00Z' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_field_invalid:event_time_device/,
    );
  });

  it('Pass 2A.TS — rejects ISO 8601 datetime missing tz', async () => {
    const payload = { ...validSaleReceiptPayload(), event_time_device: '2026-05-16T10:00:00.000' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_field_invalid:event_time_device/,
    );
  });

  it('Pass 2A.TS — rejects YYYY-MM-DD failure on business_date', async () => {
    const payload = { ...validSaleReceiptPayload(), business_date: '16/05/2026' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_field_invalid:business_date/,
    );
  });

  it('Pass 2A.TS — rejects currency_scale not in {0, 2, 3} allowlist', async () => {
    for (const scale of [1, 4, 5, 6, 8]) {
      const payload = recomputeForScale(validSaleReceiptPayload(), scale);
      await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
        /payload_currency_scale_unsupported:value=/,
      );
    }
  });

  it('Pass 2A.TS — rejects invoice_type_code outside the enum', async () => {
    const payload = { ...validSaleReceiptPayload(), invoice_type_code: 'EXPORT' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_field_invalid:invoice_type_code/,
    );
  });

  it('Pass 2A.TS — rejects training_flag mismatch (SALE + true)', async () => {
    const payload = { ...validSaleReceiptPayload(), training_flag: true };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_training_flag_mismatch:invoice_type_code=SALE:training_flag=true/,
    );
  });

  it('Pass 2A.TS — rejects training_flag mismatch (TRAINING + false)', async () => {
    const payload = { ...validSaleReceiptPayload(), invoice_type_code: 'TRAINING', training_flag: false };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_training_flag_mismatch:invoice_type_code=TRAINING:training_flag=false/,
    );
  });

  it('Pass 2A.TS — rejects negative money in any top-level field', async () => {
    for (const field of ['subtotal', 'vat_total', 'total', 'transaction_discount_amount']) {
      const payload = { ...validSaleReceiptPayload(), [field]: '-5.000' };
      await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
        new RegExp(`payload_money_scale_mismatch:field=${field}`),
      );
    }
  });

  it('Pass 2A.TS — rejects wrong-scale money (3 decimals at scale=2)', async () => {
    // Build an EUR (scale=2) payload but leave subtotal at "10.000".
    const payload = recomputeForScale(validSaleReceiptPayload(), 2);
    (payload as Record<string, unknown>)['subtotal'] = '10.000';
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_money_scale_mismatch:field=subtotal/,
    );
  });

  it('Pass 2A.TS R2 — rejects missing fractional digits when currency_scale is non-zero', async () => {
    const payload = recomputeForScale(validSaleReceiptPayload(), 2);
    (payload as Record<string, unknown>)['subtotal'] = '10';
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_money_scale_mismatch:field=subtotal/,
    );
  });

  it('Pass 2A.TS — rejects discount-reason mismatch (amount=0.000 + reason set)', async () => {
    const payload = { ...validSaleReceiptPayload(), transaction_discount_reason: 'manager override' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_discount_reason_mismatch:amount=0\.000:reason_present=true/,
    );
  });

  it('Pass 2A.TS — rejects discount-reason mismatch (amount=5.000 + reason null)', async () => {
    // discount=5, subtotal=10, vat_total=2 → total = 10+2-5 = 7.
    const payload = {
      ...validSaleReceiptPayload(),
      transaction_discount_amount: '5.000',
      transaction_discount_reason: null,
      total: '7.000',
    };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_discount_reason_mismatch:amount=5\.000:reason_present=false/,
    );
  });

  it('Pass 2A.TS — accepts buyer block when present (with address + tax number)', async () => {
    const payload = {
      ...validSaleReceiptPayload(),
      buyer: {
        address: {
          city: 'Paris',
          country_code: 'FR',
          postal_code: '75001',
          street: '1 Rue de Rivoli',
        },
        codice_fiscale: null,
        contact_id: null,
        customer_id: null,
        name: 'Acme SA',
        tax_number: 'FR12345678901',
      },
    };
    const event = await engine.append(adapter, saleReceiptRequest({ payload }));
    expect(event.sequence_number).toBe(1);
  });

  it('Pass 2A.TS — rejects buyer block with missing keys', async () => {
    const payload = {
      ...validSaleReceiptPayload(),
      buyer: {
        // Missing all 6 required keys: codice_fiscale, contact_id, customer_id, name, tax_number, address.
        name: 'Acme SA',
      },
    };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_buyer_missing_keys:/,
    );
  });

  it('Pass 2A.TS — accepts foreign_currency_amount when paired with foreign_currency_code', async () => {
    // TND payment + EUR foreign-currency receipt — both fields present.
    const payload = {
      ...validSaleReceiptPayload(),
      payments: [
        {
          amount: '12.000',
          foreign_currency_amount: '4.00',
          foreign_currency_code: 'EUR',
          instrument_serial: null,
          instrument_type: null,
          method_code: 'cash',
        },
      ],
    };
    const event = await engine.append(adapter, saleReceiptRequest({ payload }));
    expect(event.sequence_number).toBe(1);
  });

  it('Pass 2A.TS — rejects half-paired foreign currency (amount without code)', async () => {
    const payload = {
      ...validSaleReceiptPayload(),
      payments: [
        {
          amount: '12.000',
          foreign_currency_amount: '4.00',
          foreign_currency_code: null,
          instrument_serial: null,
          instrument_type: null,
          method_code: 'cash',
        },
      ],
    };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_payment_foreign_currency_pair_invalid/,
    );
  });

  it('Pass 2A.TS — rejects REFUND without original_receipt_reference', async () => {
    const payload = { ...validSaleReceiptPayload(), invoice_type_code: 'REFUND' };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_invoice_type_invalid:original_receipt_reference required/,
    );
  });

  it('Pass 2A.TS — rejects SALE with original_receipt_reference set', async () => {
    const payload = {
      ...validSaleReceiptPayload(),
      original_receipt_reference: {
        fiscal_event_id: '55555555-5555-5555-5555-555555555555',
        original_business_date: '2026-05-15',
        original_receipt_uuid: '66666666-6666-6666-6666-666666666666',
        refund_reason: 'customer asked',
      },
    };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_invoice_type_invalid:original_receipt_reference present but invoice_type_code=/,
    );
  });

  it('Pass 2A.TS R3 — rejects sparse list containers before canonical sealing', async () => {
    for (const field of ['line_items', 'payments', 'vat_breakdown', 'vouchers_redeemed'] as const) {
      const payload = validSaleReceiptPayload();
      payload[field] = new Array(1);

      await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
        new RegExp(`payload_${field}_invalid:must be a dense JSON list`),
      );
    }
  });

  it('Pass 2A.TS — rejects empty line_items', async () => {
    const payload = { ...validSaleReceiptPayload(), line_items: [] };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_line_items_empty/,
    );
  });

  it('Pass 2A.TS — rejects empty payments', async () => {
    const payload = { ...validSaleReceiptPayload(), payments: [] };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_payments_empty/,
    );
  });

  it('Pass 2A.TS — rejects line_item with bad tax_category_code shape', async () => {
    const payload = validSaleReceiptPayload();
    const lines = [...(payload['line_items'] as Array<Record<string, unknown>>)];
    lines[0] = { ...lines[0], tax_category_code: 'lowercase!' };
    payload['line_items'] = lines;
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_line_item_tax_category_invalid/,
    );
  });

  it('Pass 2A.TS — rejects bad seller.tax_jurisdiction_country_code', async () => {
    const payload = validSaleReceiptPayload();
    payload['seller'] = {
      ...(payload['seller'] as Record<string, unknown>),
      tax_jurisdiction_country_code: 'TUN', // 3-char, not alpha-2
    };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_seller_tax_jurisdiction_invalid/,
    );
  });

  it('Pass 2A.TS — rejects tax_number with control byte (PHP-parity)', async () => {
    const payload = validSaleReceiptPayload();
    payload['seller'] = {
      ...(payload['seller'] as Record<string, unknown>),
      tax_number: 'abcdef',
    };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_tax_number_invalid:seller\.tax_number contains control byte/,
    );
  });

  it('Pass 2A.TS — rejects too-short tax_number (< 4 chars)', async () => {
    const payload = validSaleReceiptPayload();
    payload['seller'] = {
      ...(payload['seller'] as Record<string, unknown>),
      tax_number: 'abc',
    };
    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_tax_number_invalid/,
    );
  });

  it('Phase 1.5.2 — rejects FR seller tax numbers that only match the old universal pattern', async () => {
    const payload = validSaleReceiptPayload();
    payload['seller'] = {
      ...(payload['seller'] as Record<string, unknown>),
      tax_jurisdiction_country_code: 'FR',
      tax_number: 'FR12345678901',
      address: {
        ...((payload['seller'] as Record<string, unknown>)['address'] as Record<string, unknown>),
        country_code: 'FR',
      },
    };

    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_tax_number_format_mismatch:field=seller\.tax_number:country=FR:value=FR12345678901/,
    );
  });

  it('Phase 1.5.2 — accepts slash-separated TN seller tax numbers at the device boundary', async () => {
    const payload = validSaleReceiptPayload();
    payload['seller'] = {
      ...(payload['seller'] as Record<string, unknown>),
      tax_number: '1234567/A/M/000',
    };

    const event = await engine.append(adapter, saleReceiptRequest({ payload }));

    expect(event.event_type).toBe('SALE_RECEIPT');
  });

  it('Phase 1.5.2 — rejects invalid IT buyer codice fiscale', async () => {
    const payload = validSaleReceiptPayload();
    payload['buyer'] = {
      address: {
        city: 'Rome',
        country_code: 'IT',
        postal_code: '00100',
        street: 'Via Roma 1',
      },
      codice_fiscale: 'RSSMRA80A01H50',
      contact_id: null,
      customer_id: null,
      name: 'Mario Rossi',
      tax_number: null,
    };

    await expect(engine.append(adapter, saleReceiptRequest({ payload }))).rejects.toThrow(
      /payload_buyer_codice_fiscale_format_mismatch:field=buyer\.codice_fiscale:value=RSSMRA80A01H50/,
    );
  });

  it('Phase 1.5.2 — rejects ACCOUNT_PAYMENT seller tax numbers by country', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.seller = {
      ...payload.seller,
      tax_jurisdiction_country_code: 'SA',
      tax_number: '212345678901203',
      address: {
        ...payload.seller.address,
        country_code: 'SA',
      },
    };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_tax_number_format_mismatch:field=seller\.tax_number:country=SA:value=212345678901203/,
    );
  });

  // -------------------------------------------------------------------
  // Phase 2 Task 7 — ACCOUNT_PAYMENT device-authoring contract.
  //
  // Mirrors PHP `FiscalPayloadConstraintValidator::validateAccountPaymentPayload`
  // for structural conformance before canonical sealing. Arithmetic and
  // Treasury allocation semantics remain server-side; the device validator
  // rejects shape drift, stale-marker contradictions, forbidden aliases, and
  // zero non-training account payments at append time.
  // -------------------------------------------------------------------

  it('Phase 2.7 — happy path: ACCOUNT_PAYMENT payload validates and seals on the device chain', async () => {
    const event = await engine.append(adapter, accountPaymentRequest());

    expect(event.event_type).toBe('ACCOUNT_PAYMENT');
    expect(event.sequence_number).toBe(1);
    expect(event.source_event_class).toBe('account_payments');
    expect(event.current_hash).toMatch(/^[0-9a-f]{64}$/);
  });

  it('Phase 2.7 — rejects ACCOUNT_PAYMENT with an extra top-level key', async () => {
    const payload = { ...goldenAccountPaymentPayload(), dual_chain_shadow: true };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_extra_field:dual_chain_shadow/,
    );
  });

  it('Phase 2.7 — rejects non-training ACCOUNT_PAYMENT with zero amount', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.payment = { ...payload.payment, amount: '0.000' };
    payload.local_balance_snapshot = {
      ...payload.local_balance_snapshot,
      payment_amount: '0.000',
    };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_account_payment_amount_zero/,
    );
  });

  it('Phase 2.7 — accepts training ACCOUNT_PAYMENT with zero amount', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.training_flag = true;
    payload.payment = { ...payload.payment, amount: '0.000' };
    payload.local_balance_snapshot = {
      ...payload.local_balance_snapshot,
      payment_amount: '0.000',
    };

    const event = await engine.append(adapter, accountPaymentRequest({ payload }));

    expect(event.event_type).toBe('ACCOUNT_PAYMENT');
  });

  it('Phase 2.7 — rejects stale ACCOUNT_PAYMENT snapshot without a staleness reason', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.staleness = {
      ...payload.staleness,
      balance_snapshot_stale: true,
      staleness_reason: null,
    };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_account_payment_staleness_reason_required/,
    );
  });

  it('Phase 2.7 — rejects fresh ACCOUNT_PAYMENT snapshot with a staleness reason', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.staleness = {
      ...payload.staleness,
      staleness_reason: 'older_than_threshold',
    };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_account_payment_staleness_reason_mismatch/,
    );
  });

  it('Phase 2.7 — rejects reserved server_customer_alias_id on ACCOUNT_PAYMENT references', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.references = {
      external_reference: 'counter-payment-42',
      related_sale_receipt_event_id: null,
      server_customer_alias_id: '77777777-7777-4777-8777-777777777777' as never,
    };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_account_payment_server_customer_alias_forbidden/,
    );
  });

  it('Phase 2.7 — accepts paired foreign currency fields on ACCOUNT_PAYMENT', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.payment = {
      ...payload.payment,
      foreign_currency_amount: '30.00',
      foreign_currency_code: 'EUR',
    };

    const event = await engine.append(adapter, accountPaymentRequest({ payload }));

    expect(event.event_type).toBe('ACCOUNT_PAYMENT');
  });

  it('Phase 2.7 — rejects one-sided foreign currency fields on ACCOUNT_PAYMENT', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.payment = {
      ...payload.payment,
      foreign_currency_amount: '30.00',
      foreign_currency_code: null,
    };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_account_payment_foreign_currency_pair_invalid/,
    );
  });

  it('Phase 2.7 — rejects unknown foreign currency code on ACCOUNT_PAYMENT', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.payment = {
      ...payload.payment,
      foreign_currency_amount: '30.00',
      foreign_currency_code: 'XXX',
    };

    await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
      /payload_account_payment_foreign_currency_unknown/,
    );
  });

  it('Phase 2.7 — accepts nullable-object regime_extensions on ACCOUNT_PAYMENT', async () => {
    const payload = goldenAccountPaymentPayload();
    payload.regime_extensions = {
      nf525: {
        placeholder: true,
      },
    };

    const event = await engine.append(adapter, accountPaymentRequest({ payload }));

    expect(event.event_type).toBe('ACCOUNT_PAYMENT');
  });

  it('Phase 2.7 — rejects scalar or list regime_extensions on ACCOUNT_PAYMENT', async () => {
    for (const regimeExtensions of ['nf525', ['nf525']]) {
      const payload = goldenAccountPaymentPayload() as unknown as Record<string, unknown>;
      payload['regime_extensions'] = regimeExtensions;

      await expect(engine.append(adapter, accountPaymentRequest({ payload }))).rejects.toThrow(
        /payload_object_invalid:regime_extensions/,
      );
    }
  });

  // -------------------------------------------------------------------
  // Cross-language drift gate — SALE_RECEIPT_PAYLOAD_KEYS must byte-mirror
  // PHP FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT'].
  //
  // Synthesis v5 §3 + Task 14 standing pattern: the device-side TS key
  // list and the server-side PHP key list have to be kept identical;
  // any drift would mean TS accepts what PHP rejects (silent partial
  // failure at sync time). Test reads the PHP file at test time and
  // extracts the SALE_RECEIPT array via regex, then asserts sorted
  // equality with the TS const.
  // -------------------------------------------------------------------

  it('Pass 2A.TS — cross-language drift gate: SALE_RECEIPT_PAYLOAD_KEYS byte-mirrors PHP PAYLOAD_KEYS', () => {
    const phpKeys = readPhpSaleReceiptPayloadKeys();
    const tsKeys = [...SALE_RECEIPT_PAYLOAD_KEYS].sort();
    const sortedPhp = [...phpKeys].sort();
    expect(tsKeys).toEqual(sortedPhp);
    expect(tsKeys).toHaveLength(27);
  });

  it('Phase 2.7 — cross-language drift gate: ACCOUNT_PAYMENT_PAYLOAD_KEYS byte-mirrors PHP PAYLOAD_KEYS', () => {
    const phpKeys = readPhpAccountPaymentPayloadKeys();
    const tsKeys = [...ACCOUNT_PAYMENT_PAYLOAD_KEYS].sort();
    const sortedPhp = [...phpKeys].sort();
    expect(tsKeys).toEqual(sortedPhp);
    expect(tsKeys).toHaveLength(20);
  });
});

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Return a shallow clone with `key` removed. Used to test required-key rejections. */
function omitKey<T extends Record<string, unknown>>(bag: T, key: string): Record<string, unknown> {
  const clone: Record<string, unknown> = { ...bag };
  delete clone[key];
  return clone;
}

/**
 * Rebuild a fresh payload at the requested currency scale, fixing every
 * money field to the same value with the new fractional precision so the
 * payload remains structurally consistent. Subtotal arithmetic is left
 * to the server-side validator (synthesis v5 §6.F).
 */
function recomputeForScale(payload: Record<string, unknown>, scale: number): Record<string, unknown> {
  const next = { ...payload };
  next['currency_scale'] = scale;
  next['currency_code'] = scale === 3 ? 'TND' : scale === 0 ? 'JPY' : 'EUR';
  const money = (whole: number): string => (scale === 0 ? String(whole) : `${whole}.${'0'.repeat(scale)}`);
  next['subtotal'] = money(10);
  next['vat_total'] = money(2);
  next['total'] = money(12);
  next['transaction_discount_amount'] = money(0);
  const oldLines = next['line_items'] as Array<Record<string, unknown>>;
  next['line_items'] = oldLines.map((line) => ({
    ...line,
    unit_price: money(10),
    line_subtotal: money(10),
    line_vat: money(2),
    line_discount_amount: money(0),
  }));
  const oldPayments = next['payments'] as Array<Record<string, unknown>>;
  next['payments'] = oldPayments.map((p) => ({ ...p, amount: money(12) }));
  const oldBreakdown = next['vat_breakdown'] as Array<Record<string, unknown>>;
  next['vat_breakdown'] = oldBreakdown.map((b) => ({
    ...b,
    net_amount: money(10),
    vat_amount: money(2),
    gross_amount: money(12),
  }));
  return next;
}

/**
 * Cross-language drift gate helper — read the PHP validator file and
 * extract the SALE_RECEIPT PAYLOAD_KEYS list. Uses Node `fs` directly
 * (test-only; not bundled). Throws if the PHP file shape changes (which
 * is exactly the drift signal we want).
 */
function readPhpSaleReceiptPayloadKeys(): string[] {
  return readPhpPayloadKeys('SALE_RECEIPT');
}

function readPhpAccountPaymentPayloadKeys(): string[] {
  return readPhpPayloadKeys('ACCOUNT_PAYMENT');
}

function readPhpPayloadKeys(eventType: 'SALE_RECEIPT' | 'ACCOUNT_PAYMENT'): string[] {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const fs = require('node:fs') as typeof import('node:fs');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const path = require('node:path') as typeof import('node:path');
  // Walk up from this test file to the worktree root, then into apps/api.
  // __dirname at test-time = apps/pos/src/lib/fiscal/__tests__/
  const candidates = [
    path.resolve(__dirname, '../../../../../../apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
    path.resolve(__dirname, '../../../../../api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php'),
  ];
  const phpPath = candidates.find((p) => fs.existsSync(p));
  if (!phpPath) {
    throw new Error(
      `FiscalPayloadConstraintValidator.php not found at any candidate path: ${candidates.join(', ')}`,
    );
  }
  const src = fs.readFileSync(phpPath, 'utf8');
  // Match the event-type array literal up to its closing ],
  // tolerating whitespace + per-line comments + trailing commas.
  const match = src.match(new RegExp(`'${eventType}'\\s*=>\\s*\\[([\\s\\S]*?)\\]`));
  if (!match) {
    throw new Error(`Could not locate '${eventType}' => [...] in ${phpPath}`);
  }
  const body = match[1] ?? '';
  const keys = Array.from(body.matchAll(/'([a-z_][a-z0-9_]*)'/g)).map((m) => m[1] as string);
  if (keys.length === 0) {
    throw new Error(`No keys extracted from '${eventType}' array body in ${phpPath}`);
  }
  return keys;
}
