import type Database from '@tauri-apps/plugin-sql';
import { queryOne, execute } from '@/lib/db';

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
