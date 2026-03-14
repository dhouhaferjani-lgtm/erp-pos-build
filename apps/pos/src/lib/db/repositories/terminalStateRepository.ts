import type Database from '@tauri-apps/plugin-sql';
import { queryOne, execute } from '@/lib/db';
import type { ZChainState } from '@/lib/offline/types';

export interface TerminalHashState {
  terminal_id: string;
  terminal_code: string;
  genesis_seed: string;
  last_hash: string;
  hash_sequence: number;
}

export async function getTerminalState(
  db: Database,
  terminalId: string,
): Promise<TerminalHashState | null> {
  return queryOne<TerminalHashState>(
    db,
    'SELECT * FROM terminal_state WHERE terminal_id = $1',
    [terminalId]
  );
}

export async function upsertTerminalState(
  db: Database,
  state: TerminalHashState,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO terminal_state (terminal_id, terminal_code, genesis_seed, last_hash, hash_sequence, updated_at)
     VALUES ($1, $2, $3, $4, $5, datetime('now'))
     ON CONFLICT(terminal_id) DO UPDATE SET
       terminal_code = excluded.terminal_code,
       genesis_seed = excluded.genesis_seed,
       last_hash = excluded.last_hash,
       hash_sequence = excluded.hash_sequence,
       updated_at = datetime('now')`,
    [state.terminal_id, state.terminal_code, state.genesis_seed, state.last_hash, state.hash_sequence]
  );
}

export async function advanceHashChain(
  db: Database,
  terminalId: string,
  newHash: string,
  newSequence: number,
): Promise<void> {
  await execute(
    db,
    "UPDATE terminal_state SET last_hash = $1, hash_sequence = $2, updated_at = datetime('now') WHERE terminal_id = $3",
    [newHash, newSequence, terminalId]
  );
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
      [terminalId]
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
    [newHash, newSequence, newZNumber, terminalId]
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
      cumulative_sales: number;
      cumulative_tax: number;
      cumulative_refunds: number;
      perpetual_grand_total: number;
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
      gt?.cumulative_sales ?? 0,
      gt?.cumulative_tax ?? 0,
      gt?.cumulative_refunds ?? 0,
      gt?.perpetual_grand_total ?? 0,
      gt?.receipt_count_lifetime ?? 0,
      terminalId,
    ]
  );
  if (result.rowsAffected === 0) {
    throw new Error('Z-chain state recovery failed: terminal_state row does not exist for terminal ' + terminalId + '. Run pullTerminalState() first.');
  }
}

export async function updateGrandTotals(
  db: Database,
  terminalId: string,
  addSales: number,
  addTax: number,
  addRefunds: number,
  addReceiptCount: number,
): Promise<void> {
  await execute(
    db,
    `UPDATE terminal_state
     SET cumulative_sales = cumulative_sales + $1,
         cumulative_tax = cumulative_tax + $2,
         cumulative_refunds = cumulative_refunds + $3,
         perpetual_grand_total = perpetual_grand_total + ($1 - $3),
         receipt_count_lifetime = receipt_count_lifetime + $4,
         updated_at = datetime('now')
     WHERE terminal_id = $5`,
    [addSales, addTax, addRefunds, addReceiptCount, terminalId]
  );
}
