/**
 * Task 25 — `ChainRecoveryService` (device TS) — spec v7 §9.
 *
 * On a local chain break the device records TWO ordinary fiscal events
 * (NOT a special schema): `CHAIN_BREAK_DETECTED` then `CHAIN_RESTART`.
 * The terminal continues in `degraded` mode (recorded in `terminal_state`);
 * the broken segment is **never deleted** — it stays in `fiscal_events`
 * for the verifier + JET export to surface forensically.
 *
 * Tests exercise the real v37 migrations via the `SqliteTestAdapter` so
 * we hit the actual CHECK constraints, triggers, and partial UNIQUE
 * indexes — not a mock surface.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { migrations } from '@/lib/db/migrations';
import {
  ChainRecoveryService,
  type ChainBreakAndRestartRequest,
} from '../ChainRecoveryService';
import { FiscalEventEngine } from '../FiscalEventEngine';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';
import { FiscalEventPayloadRegistry } from '../FiscalEventPayloadRegistry';
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

function validSaleReceiptPayload(): Record<string, unknown> {
  return {
    currency: 'TND',
    currency_scale: 3,
    lines: [{ sku: 'A', qty: 1, unit_price: '10.000', line_total: '10.000' }],
    subtotal: '10.000',
    discount_total: '0.000',
    tax_total: '0.000',
    total: '10.000',
    vat_breakdown: [],
    payment_lines: [{ payment_method_id: 'pm-cash', amount: '10.000' }],
    voucher_redemptions: [],
  };
}

interface FiscalEventRowDb {
  id: string;
  event_type: string;
  sequence_number: number;
  previous_hash: string;
  current_hash: string;
  canonical_bytes: string;
  sync_status: string;
  signature_status: string;
}

async function selectAllEvents(adapter: SqliteTestAdapter): Promise<FiscalEventRowDb[]> {
  return adapter.select<FiscalEventRowDb[]>(
    `SELECT id, event_type, sequence_number, previous_hash, current_hash,
            canonical_bytes, sync_status, signature_status
       FROM fiscal_events
      ORDER BY sequence_number ASC`,
  );
}

async function selectChainHead(adapter: SqliteTestAdapter): Promise<{
  fiscal_event_last_hash: string;
  fiscal_event_sequence: number;
}> {
  const rows = await adapter.select<
    Array<{ fiscal_event_last_hash: string; fiscal_event_sequence: number }>
  >(
    `SELECT fiscal_event_last_hash, fiscal_event_sequence
       FROM terminal_state WHERE terminal_id = $1`,
    [TERMINAL_ID],
  );
  const row = rows[0];
  if (!row) throw new Error('terminal_state missing');
  return row;
}

function defaultRequest(
  overrides: Partial<ChainBreakAndRestartRequest> = {},
): ChainBreakAndRestartRequest {
  return {
    tenant_id: TENANT_ID,
    company_id: COMPANY_ID,
    terminal_id: TERMINAL_ID,
    operator_id: OPERATOR_ID,
    event_time_device: '2026-05-16T10:00:00Z',
    business_date: '2026-05-16',
    reason: 'gap',
    last_good_sequence: 4,
    last_good_hash: 'a'.repeat(64),
    offending_reference: 'r-9',
    operator_authorization_evidence: {
      user_id: '11111111-1111-1111-1111-111111111111',
      role: 'manager',
      reason_code: 'CHAIN_BREAK',
    },
    ...overrides,
  };
}

d('ChainRecoveryService.recordBreakAndRestart', () => {
  let adapter: SqliteTestAdapter;
  let service: ChainRecoveryService;
  const encoder = new FiscalEventCanonicalEncoder();
  const integrityProvider = new HashChainIntegrityProvider();
  const registry = new FiscalEventPayloadRegistry();

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runMigrationsUpTo(adapter, 37);
    await seedTerminalState(adapter);
    const engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
    service = new ChainRecoveryService(engine);
  });

  afterEach(() => {
    adapter.close();
  });

  // -------------------------------------------------------------------
  // Plan §1904 — emission order
  // -------------------------------------------------------------------

  it('on a local chain break emits CHAIN_BREAK_DETECTED then CHAIN_RESTART as ordinary fiscal_events', async () => {
    await service.recordBreakAndRestart(adapter, defaultRequest());

    const rows = await selectAllEvents(adapter);
    expect(rows.map((r) => r.event_type)).toEqual(['CHAIN_BREAK_DETECTED', 'CHAIN_RESTART']);
    expect(rows[0]?.sequence_number).toBe(1);
    expect(rows[1]?.sequence_number).toBe(2);
  });

  it('both events are ordinary fiscal_events rows (same schema as SALE_RECEIPT)', async () => {
    await service.recordBreakAndRestart(adapter, defaultRequest());

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(2);
    for (const row of rows) {
      expect(row.sync_status).toBe('pending');
      expect(row.signature_status).toBe('not_required');
      expect(row.current_hash).toMatch(/^[0-9a-f]{64}$/);
      expect(row.previous_hash).toMatch(/^[0-9a-f]{64}$/);
      expect(row.canonical_bytes.length).toBeGreaterThan(0);
    }
  });

  it('CHAIN_RESTART links its previous_hash to CHAIN_BREAK_DETECTED.current_hash (one chain, not two)', async () => {
    await service.recordBreakAndRestart(adapter, defaultRequest());

    const rows = await selectAllEvents(adapter);
    expect(rows[1]?.previous_hash).toBe(rows[0]?.current_hash);
  });

  // -------------------------------------------------------------------
  // Plan §1910 — broken segment is never deleted
  // -------------------------------------------------------------------

  it('the broken segment is never deleted — prior fiscal_events rows still present after recovery', async () => {
    // Prime the chain with a SALE_RECEIPT to establish prior events
    // (representing the "broken segment" before recovery).
    const engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
    await engine.append(adapter, {
      event_type: 'SALE_RECEIPT',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      terminal_id: TERMINAL_ID,
      operator_id: OPERATOR_ID,
      event_time_device: '2026-05-16T09:00:00Z',
      business_date: '2026-05-16',
      payload: validSaleReceiptPayload(),
    });
    await engine.append(adapter, {
      event_type: 'SALE_RECEIPT',
      tenant_id: TENANT_ID,
      company_id: COMPANY_ID,
      terminal_id: TERMINAL_ID,
      operator_id: OPERATOR_ID,
      event_time_device: '2026-05-16T09:30:00Z',
      business_date: '2026-05-16',
      payload: validSaleReceiptPayload(),
    });

    const beforeRecovery = await selectAllEvents(adapter);
    expect(beforeRecovery).toHaveLength(2);
    const priorIds = beforeRecovery.map((r) => r.id);

    // Trigger recovery — appends CHAIN_BREAK_DETECTED + CHAIN_RESTART.
    await service.recordBreakAndRestart(adapter, defaultRequest());

    const afterRecovery = await selectAllEvents(adapter);
    expect(afterRecovery).toHaveLength(4); // 2 prior + 2 recovery
    expect(afterRecovery.map((r) => r.event_type)).toEqual([
      'SALE_RECEIPT',
      'SALE_RECEIPT',
      'CHAIN_BREAK_DETECTED',
      'CHAIN_RESTART',
    ]);

    // Every prior id is still present — the broken segment is preserved.
    const afterIds = afterRecovery.map((r) => r.id);
    for (const priorId of priorIds) {
      expect(afterIds).toContain(priorId);
    }
  });

  // -------------------------------------------------------------------
  // Payload shape — mirrors the PHP ChainBreakDetectedPayload /
  // ChainRestartPayload DTOs (cross-language drift gate; Task 14
  // standing pattern)
  // -------------------------------------------------------------------

  it('CHAIN_BREAK_DETECTED canonical_bytes carry the spec §9 payload fields', async () => {
    await service.recordBreakAndRestart(
      adapter,
      defaultRequest({
        reason: 'sequence_gap_detected_at_sync',
        last_good_sequence: 7,
        last_good_hash: 'b'.repeat(64),
        offending_reference: 'event-uuid-bad',
      }),
    );

    const rows = await selectAllEvents(adapter);
    const breakRow = rows[0];
    expect(breakRow).toBeDefined();
    if (!breakRow) return;

    const canonical = JSON.parse(breakRow.canonical_bytes) as Record<string, unknown>;
    expect(canonical.event_type).toBe('CHAIN_BREAK_DETECTED');
    const payload = canonical.payload as Record<string, unknown>;
    expect(payload.reason).toBe('sequence_gap_detected_at_sync');
    expect(payload.last_good_sequence).toBe(7);
    expect(payload.last_good_hash).toBe('b'.repeat(64));
    const offending = payload.offending_record_reference as Record<string, unknown>;
    expect(offending.offending_reference).toBe('event-uuid-bad');
  });

  it('CHAIN_RESTART canonical_bytes carry the spec §9 payload fields with provenance to the break event', async () => {
    await service.recordBreakAndRestart(adapter, defaultRequest());

    const rows = await selectAllEvents(adapter);
    const breakRow = rows[0];
    const restartRow = rows[1];
    expect(breakRow).toBeDefined();
    expect(restartRow).toBeDefined();
    if (!breakRow || !restartRow) return;

    const canonical = JSON.parse(restartRow.canonical_bytes) as Record<string, unknown>;
    expect(canonical.event_type).toBe('CHAIN_RESTART');
    const payload = canonical.payload as Record<string, unknown>;

    // The four spec-named fields must be present.
    expect(payload.new_genesis_reference).toMatch(/^[0-9a-f]{64}$/);
    const anchor = payload.last_good_anchor as Record<string, unknown>;
    expect(anchor.sequence_number).toBe(4);
    expect(anchor.hash).toBe('a'.repeat(64));

    const evidence = payload.operator_authorization_evidence as Record<string, unknown>;
    expect(evidence.user_id).toBe('11111111-1111-1111-1111-111111111111');
    expect(evidence.role).toBe('manager');

    const provenance = payload.provenance_link as Record<string, unknown>;
    expect(provenance.chain_break_event_id).toBe(breakRow.id);
  });

  // -------------------------------------------------------------------
  // Optional offending reference (the offending row may be unknown when
  // the break is detected by a structural check like a missing row).
  // -------------------------------------------------------------------

  it('omits offending_reference when not supplied', async () => {
    const req = defaultRequest();
    delete req.offending_reference;
    await service.recordBreakAndRestart(adapter, req);

    const rows = await selectAllEvents(adapter);
    const breakRow = rows[0];
    expect(breakRow).toBeDefined();
    if (!breakRow) return;

    const canonical = JSON.parse(breakRow.canonical_bytes) as Record<string, unknown>;
    const payload = canonical.payload as Record<string, unknown>;
    const offending = payload.offending_record_reference as Record<string, unknown>;
    // The container is still emitted (DTO requires non-empty) — it carries
    // any structural context that IS known. When the caller has zero
    // identifying context, we record that explicitly rather than failing
    // the recovery emission.
    expect(offending).toBeDefined();
    expect(Object.keys(offending).length).toBeGreaterThan(0);
  });

  // -------------------------------------------------------------------
  // Chain-head invariants — the chain advances atomically.
  // -------------------------------------------------------------------

  it('advances the terminal chain head past both recovery events', async () => {
    await service.recordBreakAndRestart(adapter, defaultRequest());

    const head = await selectChainHead(adapter);
    const rows = await selectAllEvents(adapter);
    const restartRow = rows[1];
    expect(restartRow).toBeDefined();
    if (!restartRow) return;
    expect(head.fiscal_event_sequence).toBe(2);
    expect(head.fiscal_event_last_hash).toBe(restartRow.current_hash);
  });
});
