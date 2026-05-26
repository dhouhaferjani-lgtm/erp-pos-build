import type Database from '@tauri-apps/plugin-sql';
import Big from 'big.js';
import { queryOne, execute } from '@/lib/db';
import { bcadd, bcsub } from '@/lib/decimal';
import type { ZChainState } from '@/lib/offline/types';

// Max currency scale used for cumulative monetary arithmetic. TND needs 3;
// everyone else rounds trailing zeros at the display layer. The three-decimal
// cap is a deliberate constraint — supporting currencies with >3 decimals
// (crypto, some historical minor units) would require a per-currency scale
// flowing into every writer. Revisit only if an onboarded country demands it.
const CUMULATIVE_SCALE = 3;
const ZERO = '0.000';

/**
 * Defensive coercion for grand-totals fields coming over the wire.
 *
 * The Laravel side casts monetary columns as `decimal:3`, which serializes as
 * a decimal string ("100.250"). Our `ZChainStateResponse` types them as string
 * accordingly. If the server ever sends a number (silent JSON cast regression,
 * unversioned deployment, middleware that strips type hints), a bare assignment
 * would write "100.25" for JS number 100.25 — losing the trailing zero that
 * makes the hash chain reproducible.
 *
 * This normalizes whatever came in to a CUMULATIVE_SCALE-padded decimal string,
 * so a latent server bug cannot silently corrupt local cumulative state.
 */
function coerceCumulative(value: unknown): string {
  if (typeof value === 'string') {
    // Already a string — trust it (might be "100.250" or "100.25"; Big.js
    // normalizes both at downstream arithmetic time).
    return value;
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    return new Big(value).toFixed(CUMULATIVE_SCALE);
  }
  return ZERO;
}

export interface TerminalHashState {
  terminal_id: string;
  terminal_code: string;
  location_code: string;
  genesis_seed: string;
  last_hash: string;
  hash_sequence: number;
  manager_pin_throttle_until: string | null;
  manager_pin_failed_attempts: number;
  /**
   * Fiscal hash schema version (2 = legacy `computeFiscalHash`, 3 = v3 canonical
   * payload + SHA-256). Codex review B1 (2026-04-30): the offline receipt creator
   * branches on this value, and the sync layer stamps every payload with the
   * version it sealed under so the server can hard-reject mismatched versions.
   */
  fiscal_schema_version: 2 | 3;
  fiscal_event_genesis_seed?: string;
  z_chain_genesis_seed?: string;
  training_fiscal_event_genesis_seed?: string;
  training_z_chain_genesis_seed?: string;
}

/**
 * Thrown when a write would move `hash_sequence` backwards.
 * The fiscal chain is append-only — any regressive write is a bug or a sync
 * race that must NEVER be silently persisted.
 */
export class FiscalRegressionError extends Error {
  constructor(
    readonly terminalId: string,
    readonly op: string,
    readonly before: number,
    readonly after: number,
  ) {
    super(
      `[fiscal] Regressive ${op} for terminal ${terminalId}: ` +
        `local hash_sequence=${before}, incoming=${after}. Rejecting write.`,
    );
    this.name = 'FiscalRegressionError';
  }
}

export class ChainGenesisSeedConflictError extends Error {
  constructor(
    readonly terminalId: string,
    readonly existingSeed: string,
    readonly incomingSeed: string,
  ) {
    super(
      `[fiscal] Conflicting fiscal_event_genesis_seed for terminal ${terminalId}: ` +
        `existing=${existingSeed}, incoming=${incomingSeed}. Rejecting write.`,
    );
    this.name = 'ChainGenesisSeedConflictError';
  }
}

function logFiscal(entry: Record<string, unknown>): void {
  // Stable single-line structured log. Grep with `rg '\[fiscal\]'` during post-mortems.
  // Also surfaces in Tauri devtools so manual repro sessions capture the trace.
  console.info('[fiscal]', entry);
}

export async function getTerminalState(
  db: Database,
  terminalId: string,
): Promise<TerminalHashState | null> {
  // Project `fiscal_schema_version` so the receipt-creation path can branch on
  // it without a second query. The migration default is 2, so older rows that
  // pre-date the column ALTER will read as v2 — safe legacy behaviour.
  const row = await queryOne<Omit<TerminalHashState, 'fiscal_schema_version'> & { fiscal_schema_version: number }>(
    db,
    `SELECT terminal_id, terminal_code, location_code, genesis_seed, last_hash, hash_sequence,
            manager_pin_throttle_until, manager_pin_failed_attempts, fiscal_schema_version
     FROM terminal_state WHERE terminal_id = $1`,
    [terminalId],
  );
  if (row === null) return null;
  // Coerce the integer column to the typed union (2 | 3). Any other value is a
  // server-side bug — fail loudly so a stray "1" or "4" cannot silently route
  // to the v2 legacy path.
  if (row.fiscal_schema_version !== 2 && row.fiscal_schema_version !== 3) {
    throw new Error(
      `[fiscal] Unexpected fiscal_schema_version=${row.fiscal_schema_version} for terminal ${terminalId}. Expected 2 or 3.`,
    );
  }
  return { ...row, fiscal_schema_version: row.fiscal_schema_version };
}

export async function setManagerPinThrottle(
  db: Database,
  terminalId: string,
  until: string | null,
): Promise<void> {
  await execute(
    db,
    `UPDATE terminal_state SET manager_pin_throttle_until = $1 WHERE terminal_id = $2`,
    [until, terminalId],
  );
}

export async function setManagerPinFailedAttempts(
  db: Database,
  terminalId: string,
  count: number,
): Promise<void> {
  await execute(
    db,
    `UPDATE terminal_state SET manager_pin_failed_attempts = $1 WHERE terminal_id = $2`,
    [count, terminalId],
  );
}

export async function upsertTerminalState(
  db: Database,
  state: TerminalHashState,
): Promise<void> {
  const current = await queryOne<{
    hash_sequence: number;
    fiscal_event_genesis_seed: string;
    z_chain_genesis_seed: string;
    training_fiscal_event_genesis_seed: string;
    training_z_chain_genesis_seed: string;
  }>(
    db,
    `SELECT hash_sequence, fiscal_event_genesis_seed, z_chain_genesis_seed,
            training_fiscal_event_genesis_seed, training_z_chain_genesis_seed
       FROM terminal_state
      WHERE terminal_id = $1`,
    [state.terminal_id],
  );
  const before = current?.hash_sequence ?? null;
  const incomingFiscalSeed = state.fiscal_event_genesis_seed ?? state.genesis_seed;
  const incomingZSeed = state.z_chain_genesis_seed ?? incomingFiscalSeed;
  const incomingTrainingFiscalSeed = state.training_fiscal_event_genesis_seed ?? incomingFiscalSeed;
  const incomingTrainingZSeed = state.training_z_chain_genesis_seed ?? incomingZSeed;

  if (before !== null && state.hash_sequence < before) {
    logFiscal({
      op: 'upsertTerminalState.reject',
      terminal_id: state.terminal_id,
      before,
      after: state.hash_sequence,
      reason: 'regressive_write',
    });
    throw new FiscalRegressionError(
      state.terminal_id,
      'upsertTerminalState',
      before,
      state.hash_sequence,
    );
  }

  const existingFiscalSeed = current?.fiscal_event_genesis_seed ?? '';
  if (
    current !== null &&
    existingFiscalSeed !== '' &&
    existingFiscalSeed !== incomingFiscalSeed
  ) {
    logFiscal({
      op: 'upsertTerminalState.reject',
      terminal_id: state.terminal_id,
      existing_seed: existingFiscalSeed,
      incoming_seed: incomingFiscalSeed,
      reason: 'fiscal_event_genesis_seed_conflict',
    });
    throw new ChainGenesisSeedConflictError(
      state.terminal_id,
      existingFiscalSeed,
      incomingFiscalSeed,
    );
  }

  await execute(
    db,
    `INSERT INTO terminal_state (
       terminal_id, terminal_code, location_code, genesis_seed, last_hash,
       hash_sequence, fiscal_schema_version, fiscal_event_genesis_seed,
       z_chain_genesis_seed, training_fiscal_event_genesis_seed,
       training_z_chain_genesis_seed, updated_at
     )
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, datetime('now'))
     ON CONFLICT(terminal_id) DO UPDATE SET
       terminal_code = excluded.terminal_code,
       location_code = excluded.location_code,
       genesis_seed = excluded.genesis_seed,
       last_hash = excluded.last_hash,
       hash_sequence = excluded.hash_sequence,
       fiscal_schema_version = excluded.fiscal_schema_version,
       fiscal_event_genesis_seed = CASE
         WHEN terminal_state.fiscal_event_genesis_seed = '' THEN excluded.fiscal_event_genesis_seed
         ELSE terminal_state.fiscal_event_genesis_seed
       END,
       z_chain_genesis_seed = CASE
         WHEN terminal_state.z_chain_genesis_seed = '' THEN excluded.z_chain_genesis_seed
         ELSE terminal_state.z_chain_genesis_seed
       END,
       training_fiscal_event_genesis_seed = CASE
         WHEN terminal_state.training_fiscal_event_genesis_seed = '' THEN excluded.training_fiscal_event_genesis_seed
         ELSE terminal_state.training_fiscal_event_genesis_seed
       END,
       training_z_chain_genesis_seed = CASE
         WHEN terminal_state.training_z_chain_genesis_seed = '' THEN excluded.training_z_chain_genesis_seed
         ELSE terminal_state.training_z_chain_genesis_seed
       END,
       updated_at = datetime('now')`,
    [
      state.terminal_id,
      state.terminal_code,
      state.location_code,
      state.genesis_seed,
      state.last_hash,
      state.hash_sequence,
      state.fiscal_schema_version,
      incomingFiscalSeed,
      incomingZSeed,
      incomingTrainingFiscalSeed,
      incomingTrainingZSeed,
    ],
  );

  logFiscal({
    op: 'upsertTerminalState',
    terminal_id: state.terminal_id,
    before,
    after: state.hash_sequence,
    last_hash: state.last_hash,
  });
}

// ─── Z-Chain State Methods ───────────────────────────────────────────────────

export async function getZChainState(
  db: Database,
  terminalId: string,
): Promise<ZChainState | null> {
  try {
    return await queryOne<ZChainState>(
      db,
      `SELECT z_last_hash, z_hash_sequence, z_number,
              cumulative_sales, cumulative_tax, cumulative_refunds,
              perpetual_grand_total, receipt_count_lifetime
       FROM terminal_state WHERE terminal_id = $1`,
      [terminalId],
    );
  } catch (error) {
    // Z-chain columns may not exist if migration 8 hasn't run yet
    const msg = error instanceof Error ? error.message : '';
    if (msg.includes('no such column')) {
      return null;
    }
    throw error;
  }
}

export async function advanceZChain(
  db: Database,
  terminalId: string,
  newHash: string,
  newSequence: number,
  newZNumber: number,
): Promise<void> {
  await execute(
    db,
    `UPDATE terminal_state
     SET z_last_hash = $1, z_hash_sequence = $2, z_number = $3, updated_at = datetime('now')
     WHERE terminal_id = $4`,
    [newHash, newSequence, newZNumber, terminalId],
  );
}

export async function upsertZChainState(
  db: Database,
  terminalId: string,
  state: {
    z_last_hash: string;
    z_hash_sequence: number;
    z_number: number;
    grand_totals: {
      cumulative_sales: string;
      cumulative_tax: string;
      cumulative_refunds: string;
      perpetual_grand_total: string;
      receipt_count_lifetime: number;
    } | null;
  },
): Promise<void> {
  const gt = state.grand_totals;
  const result = await execute(
    db,
    `UPDATE terminal_state
     SET z_last_hash = $1,
         z_hash_sequence = $2,
         z_number = $3,
         cumulative_sales = $4,
         cumulative_tax = $5,
         cumulative_refunds = $6,
         perpetual_grand_total = $7,
         receipt_count_lifetime = $8,
         updated_at = datetime('now')
     WHERE terminal_id = $9`,
    [
      state.z_last_hash,
      state.z_hash_sequence,
      state.z_number,
      coerceCumulative(gt?.cumulative_sales),
      coerceCumulative(gt?.cumulative_tax),
      coerceCumulative(gt?.cumulative_refunds),
      coerceCumulative(gt?.perpetual_grand_total),
      gt?.receipt_count_lifetime ?? 0,
      terminalId,
    ],
  );
  if (result.rowsAffected === 0) {
    throw new Error(
      'Z-chain state recovery failed: terminal_state row does not exist for terminal ' +
        terminalId +
        '. Run pullTerminalState() first.',
    );
  }
}

export async function updateGrandTotals(
  db: Database,
  terminalId: string,
  addSales: string,
  addTax: string,
  addRefunds: string,
  addReceiptCount: number,
): Promise<void> {
  // Arithmetic is done in TypeScript via Big.js — SQLite-side `col = col + $n`
  // would coerce TEXT to REAL and lose multi-decimal precision.
  const current = await getZChainState(db, terminalId);
  const salesBefore = current?.cumulative_sales ?? ZERO;
  const taxBefore = current?.cumulative_tax ?? ZERO;
  const refundsBefore = current?.cumulative_refunds ?? ZERO;
  const perpetualBefore = current?.perpetual_grand_total ?? ZERO;
  const countBefore = current?.receipt_count_lifetime ?? 0;

  const newSales = bcadd(salesBefore, addSales, CUMULATIVE_SCALE);
  const newTax = bcadd(taxBefore, addTax, CUMULATIVE_SCALE);
  const newRefunds = bcadd(refundsBefore, addRefunds, CUMULATIVE_SCALE);
  const netDelta = bcsub(addSales, addRefunds, CUMULATIVE_SCALE);
  const newPerpetual = bcadd(perpetualBefore, netDelta, CUMULATIVE_SCALE);
  const newCount = countBefore + addReceiptCount;

  await execute(
    db,
    `UPDATE terminal_state
     SET cumulative_sales = $1,
         cumulative_tax = $2,
         cumulative_refunds = $3,
         perpetual_grand_total = $4,
         receipt_count_lifetime = $5,
         updated_at = datetime('now')
     WHERE terminal_id = $6`,
    [newSales, newTax, newRefunds, newPerpetual, newCount, terminalId],
  );
}
