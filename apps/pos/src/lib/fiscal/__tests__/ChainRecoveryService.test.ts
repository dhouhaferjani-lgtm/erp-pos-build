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
  MissingOffendingReferenceError,
  type ChainBreakAndRestartRequest,
} from '../ChainRecoveryService';
import {
  FiscalEventEngine,
  FiscalEventPayloadValidationError,
} from '../FiscalEventEngine';
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

async function selectChainStatus(adapter: SqliteTestAdapter): Promise<string> {
  const rows = await adapter.select<Array<{ fiscal_chain_status: string }>>(
    `SELECT fiscal_chain_status FROM terminal_state WHERE terminal_id = $1`,
    [TERMINAL_ID],
  );
  const row = rows[0];
  if (!row) throw new Error('terminal_state missing');
  return row.fiscal_chain_status;
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
    // Round-2: offending_reference is required + non-empty (Opus F3 /
    // Codex T25-P3 closure). Pass a structured shape the verifier could
    // produce — `{kind: 'sequence_gap', expected, found}`.
    offending_reference: {
      kind: 'sequence_gap',
      expected: 5,
      found: 7,
    },
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
    await runMigrationsUpTo(adapter, 38);
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
        offending_reference: {
          kind: 'hash_mismatch',
          at_sequence: 8,
          observed_previous_hash: 'c'.repeat(64),
          event_uuid: 'event-uuid-bad',
        },
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
    // The caller's structured shape lands on the immutable chain verbatim —
    // no synthetic {kind: 'unknown_offender'} placeholder (round-2 Opus F3 /
    // Codex T25-P3 closure).
    const offending = payload.offending_record_reference as Record<string, unknown>;
    expect(offending.kind).toBe('hash_mismatch');
    expect(offending.at_sequence).toBe(8);
    expect(offending.event_uuid).toBe('event-uuid-bad');
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
  // Required offending_reference (round-2 Opus F3 / Codex T25-P3 closure).
  //
  // The service refuses to manufacture forensic context. The caller MUST
  // supply a structured non-empty object identifying what triggered the
  // break — the CHAIN_BREAK_DETECTED row is immutable, so a synthetic
  // {kind: 'unknown_offender'} placeholder would propagate to every
  // forensic consumer with no way to distinguish "we don't know" from
  // "the offender is the terminal itself".
  // -------------------------------------------------------------------

  it('throws MissingOffendingReferenceError when offending_reference is absent', async () => {
    const req = defaultRequest();
    // The service-level required field cannot be `undefined` per the
    // interface, but a JS caller could omit it. Strip explicitly to
    // exercise the runtime guard.
    delete (req as { offending_reference?: unknown }).offending_reference;

    await expect(service.recordBreakAndRestart(adapter, req)).rejects.toThrow(
      MissingOffendingReferenceError,
    );

    // No CHAIN_BREAK_DETECTED row written (the guard runs before BEGIN).
    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);
    expect(await selectChainStatus(adapter)).toBe('healthy');
  });

  it('throws MissingOffendingReferenceError when offending_reference is an empty object', async () => {
    const req = defaultRequest({ offending_reference: {} });

    await expect(service.recordBreakAndRestart(adapter, req)).rejects.toThrow(
      MissingOffendingReferenceError,
    );

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);
    expect(await selectChainStatus(adapter)).toBe('healthy');
  });

  it('throws MissingOffendingReferenceError when offending_reference is an array', async () => {
    const req = defaultRequest({
      offending_reference: ['bad'] as unknown as Record<string, unknown>,
    });

    await expect(service.recordBreakAndRestart(adapter, req)).rejects.toThrow(
      MissingOffendingReferenceError,
    );

    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);
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

  // -------------------------------------------------------------------
  // Round-2 — Degraded-mode flag (Codex T25-P1).
  //
  // Spec §9: "On a local chain break, the terminal continues operating
  // in a recorded degraded mode." v38 migration added
  // `terminal_state.fiscal_chain_status` with allowed values
  // 'healthy' | 'degraded'. The recovery service flips it inside the
  // same transaction as the two appends.
  // -------------------------------------------------------------------

  it('terminal_state.fiscal_chain_status is healthy before recovery', async () => {
    expect(await selectChainStatus(adapter)).toBe('healthy');
  });

  it('recordBreakAndRestart flips terminal_state.fiscal_chain_status to degraded', async () => {
    await service.recordBreakAndRestart(adapter, defaultRequest());
    expect(await selectChainStatus(adapter)).toBe('degraded');
  });

  // -------------------------------------------------------------------
  // Round-2 — Transactional atomicity (Codex T25-B1).
  //
  // Round-1 issued two `engine.append()` calls back-to-back with no
  // transaction wrapper. SQLite auto-commits each as its own implicit
  // transaction, so a failure on the second append would leave the
  // first append (CHAIN_BREAK_DETECTED) permanently on the chain with
  // no matching CHAIN_RESTART — a half-recovery state that the
  // verifier could not unwind without manual intervention.
  //
  // Round-2 wraps BEGIN / COMMIT around both appends + the degraded-
  // flag update. The test below injects a failure on the second append
  // by sabotaging the FiscalEventEngine partway through, and asserts
  // BOTH the first append's row AND the degraded flag are rolled back.
  // -------------------------------------------------------------------

  it('rolls back BOTH appends + the degraded flag when the second append fails', async () => {
    // Build an engine whose `append` succeeds the first time then throws
    // on the second call. This mimics e.g. a SQLITE_BUSY on the
    // CHAIN_RESTART insert or a downstream validation failure that
    // surfaces between the two appends.
    let calls = 0;
    const sabotagedEngine = new FiscalEventEngine(
      adapter,
      encoder,
      integrityProvider,
      registry,
    );
    const realAppend = sabotagedEngine.append.bind(sabotagedEngine);
    sabotagedEngine.append = async (tx, request) => {
      calls += 1;
      if (calls === 2) {
        // Second call: throw AFTER the first append's row has been
        // inserted (and the chain head advanced). If the round-2
        // transaction wrapper holds, the BEGIN / ROLLBACK pair will
        // undo the first INSERT + the chain-head update.
        throw new Error('injected failure on second append');
      }
      return realAppend(tx, request);
    };

    const sabotagedService = new ChainRecoveryService(sabotagedEngine);

    await expect(
      sabotagedService.recordBreakAndRestart(adapter, defaultRequest()),
    ).rejects.toThrow('injected failure on second append');

    // The first append's row MUST be rolled back. If round-1's behavior
    // persisted, this would be 1 (the BREAK row would still be there).
    const rows = await selectAllEvents(adapter);
    expect(rows).toHaveLength(0);

    // Chain head MUST be unchanged from the seed.
    const head = await selectChainHead(adapter);
    expect(head.fiscal_event_sequence).toBe(0);
    expect(head.fiscal_event_last_hash).toBe('');

    // Degraded flag MUST be rolled back too — atomic with the appends.
    expect(await selectChainStatus(adapter)).toBe('healthy');
  });

  // -------------------------------------------------------------------
  // Round-2 — Cross-language drift gate (Codex T25-P2).
  //
  // FiscalEventEngine.validateRequestPayload now mirrors the PHP
  // FiscalPayloadConstraintValidator for CHAIN_BREAK_DETECTED +
  // CHAIN_RESTART: 64-char lowercase hex on hash fields, non-empty
  // assoc on the four non-empty-object fields. TS callers can no
  // longer author payloads that PHP would reject at sync time.
  //
  // These tests round-trip the validation through the recovery service
  // (the natural caller) — the engine raises before any chain mutation,
  // so the service surfaces the same error.
  // -------------------------------------------------------------------

  it('rejects a non-hex last_good_hash before any chain mutation', async () => {
    const req = defaultRequest({ last_good_hash: 'not-hex' });

    await expect(service.recordBreakAndRestart(adapter, req)).rejects.toThrow(
      FiscalEventPayloadValidationError,
    );

    // No row written; the validator runs in step 0 of engine.append,
    // which runs INSIDE the recovery service's BEGIN — the catch
    // path rolls back cleanly.
    expect(await selectAllEvents(adapter)).toHaveLength(0);
    expect(await selectChainStatus(adapter)).toBe('healthy');
  });

  it('rejects a non-64-char last_good_hash', async () => {
    const req = defaultRequest({ last_good_hash: 'a'.repeat(63) });

    await expect(service.recordBreakAndRestart(adapter, req)).rejects.toThrow(
      FiscalEventPayloadValidationError,
    );
  });

  it('rejects an uppercase-hex last_good_hash (PHP-side regex is lowercase-only)', async () => {
    const req = defaultRequest({ last_good_hash: 'A'.repeat(64) });

    await expect(service.recordBreakAndRestart(adapter, req)).rejects.toThrow(
      FiscalEventPayloadValidationError,
    );
  });

  it('rejects a CHAIN_RESTART with a non-hex new_genesis_reference', async () => {
    // The recovery service computes new_genesis_reference = breakResult.current_hash
    // so we hit this branch via a direct engine.append call on a hand-built
    // payload — the test asserts the validator's CHAIN_RESTART branch fires.
    const engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
    await expect(
      engine.append(adapter, {
        event_type: 'CHAIN_RESTART',
        tenant_id: TENANT_ID,
        company_id: COMPANY_ID,
        terminal_id: TERMINAL_ID,
        operator_id: OPERATOR_ID,
        event_time_device: '2026-05-16T10:00:00Z',
        business_date: '2026-05-16',
        payload: {
          new_genesis_reference: 'not-hex',
          last_good_anchor: { hash: 'a'.repeat(64) },
          operator_authorization_evidence: { user_id: 'u' },
          provenance_link: { chain_break_event_id: 'x' },
        },
      }),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });

  it('rejects a CHAIN_RESTART with an empty operator_authorization_evidence', async () => {
    const engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
    await expect(
      engine.append(adapter, {
        event_type: 'CHAIN_RESTART',
        tenant_id: TENANT_ID,
        company_id: COMPANY_ID,
        terminal_id: TERMINAL_ID,
        operator_id: OPERATOR_ID,
        event_time_device: '2026-05-16T10:00:00Z',
        business_date: '2026-05-16',
        payload: {
          new_genesis_reference: 'a'.repeat(64),
          last_good_anchor: { sequence_number: 1 },
          operator_authorization_evidence: {},
          provenance_link: { chain_break_event_id: 'x' },
        },
      }),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });

  it('rejects a CHAIN_BREAK_DETECTED with a non-object offending_record_reference', async () => {
    const engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
    await expect(
      engine.append(adapter, {
        event_type: 'CHAIN_BREAK_DETECTED',
        tenant_id: TENANT_ID,
        company_id: COMPANY_ID,
        terminal_id: TERMINAL_ID,
        operator_id: OPERATOR_ID,
        event_time_device: '2026-05-16T10:00:00Z',
        business_date: '2026-05-16',
        payload: {
          reason: 'gap',
          last_good_sequence: 1,
          last_good_hash: 'a'.repeat(64),
          offending_record_reference: 'not-an-object',
        },
      }),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });

  it('rejects a CHAIN_BREAK_DETECTED whose offending_record_reference.observed_previous_hash is not 64-char hex', async () => {
    const engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
    await expect(
      engine.append(adapter, {
        event_type: 'CHAIN_BREAK_DETECTED',
        tenant_id: TENANT_ID,
        company_id: COMPANY_ID,
        terminal_id: TERMINAL_ID,
        operator_id: OPERATOR_ID,
        event_time_device: '2026-05-16T10:00:00Z',
        business_date: '2026-05-16',
        payload: {
          reason: 'hash_mismatch',
          last_good_sequence: 1,
          last_good_hash: 'a'.repeat(64),
          offending_record_reference: {
            kind: 'hash_mismatch',
            observed_previous_hash: 'short',
          },
        },
      }),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });

  it('rejects a CHAIN_RESTART whose last_good_anchor.hash (when present) is not 64-char hex', async () => {
    const engine = new FiscalEventEngine(adapter, encoder, integrityProvider, registry);
    await expect(
      engine.append(adapter, {
        event_type: 'CHAIN_RESTART',
        tenant_id: TENANT_ID,
        company_id: COMPANY_ID,
        terminal_id: TERMINAL_ID,
        operator_id: OPERATOR_ID,
        event_time_device: '2026-05-16T10:00:00Z',
        business_date: '2026-05-16',
        payload: {
          new_genesis_reference: 'a'.repeat(64),
          last_good_anchor: { hash: 'too-short' },
          operator_authorization_evidence: { user_id: 'u' },
          provenance_link: { chain_break_event_id: 'x' },
        },
      }),
    ).rejects.toThrow(FiscalEventPayloadValidationError);
  });
});
