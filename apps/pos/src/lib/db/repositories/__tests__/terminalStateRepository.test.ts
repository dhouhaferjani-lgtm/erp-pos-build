import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
}));

import {
  upsertTerminalState,
  advanceHashChain,
  setManagerPinThrottle,
  setManagerPinFailedAttempts,
  getTerminalState,
  FiscalRegressionError,
  type TerminalHashState,
} from '../terminalStateRepository';
import { queryOne, execute } from '@/lib/db';

const db = {} as import('@tauri-apps/plugin-sql').default;

const baseState: TerminalHashState = {
  terminal_id: 'terminal-1',
  terminal_code: 'T001',
  location_code: 'MAIN',
  genesis_seed: 'seed-abc',
  last_hash: 'hash-at-5',
  hash_sequence: 5,
  manager_pin_throttle_until: null,
  manager_pin_failed_attempts: 0,
  fiscal_schema_version: 2,
};

describe('terminalStateRepository — regression guards', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('allows upsert when local has no prior state (first bootstrap)', async () => {
    vi.mocked(queryOne).mockResolvedValue(null);

    await upsertTerminalState(db, baseState);

    expect(execute).toHaveBeenCalledOnce();
  });

  it('allows upsert when incoming hash_sequence equals or exceeds current', async () => {
    vi.mocked(queryOne).mockResolvedValue({ ...baseState, hash_sequence: 5 });

    await upsertTerminalState(db, { ...baseState, hash_sequence: 7 });
    await upsertTerminalState(db, { ...baseState, hash_sequence: 5 });

    expect(execute).toHaveBeenCalledTimes(2);
  });

  it('throws FiscalRegressionError when incoming hash_sequence is lower than current', async () => {
    vi.mocked(queryOne).mockResolvedValue({ ...baseState, hash_sequence: 12 });

    await expect(
      upsertTerminalState(db, { ...baseState, hash_sequence: 3 }),
    ).rejects.toBeInstanceOf(FiscalRegressionError);

    expect(execute).not.toHaveBeenCalled();
  });

  it('advanceHashChain refuses sequence that does not strictly increase', async () => {
    vi.mocked(queryOne).mockResolvedValue({ ...baseState, hash_sequence: 10 });

    await expect(
      advanceHashChain(db, 'terminal-1', 'new-hash', 10),
    ).rejects.toBeInstanceOf(FiscalRegressionError);

    await expect(
      advanceHashChain(db, 'terminal-1', 'new-hash', 9),
    ).rejects.toBeInstanceOf(FiscalRegressionError);

    expect(execute).not.toHaveBeenCalled();
  });

  it('advanceHashChain permits strictly greater sequence', async () => {
    vi.mocked(queryOne).mockResolvedValue({ ...baseState, hash_sequence: 10 });

    await advanceHashChain(db, 'terminal-1', 'new-hash', 11);

    expect(execute).toHaveBeenCalledOnce();
  });

  it('logs structured audit entry on every successful write', async () => {
    const spy = vi.spyOn(console, 'info').mockImplementation(() => {});
    vi.mocked(queryOne).mockResolvedValue({ ...baseState, hash_sequence: 5 });

    await upsertTerminalState(db, { ...baseState, hash_sequence: 7 });

    expect(spy).toHaveBeenCalledWith(
      '[fiscal]',
      expect.objectContaining({
        op: 'upsertTerminalState',
        terminal_id: 'terminal-1',
        before: 5,
        after: 7,
      }),
    );
    spy.mockRestore();
  });

  it('setManagerPinThrottle persists throttle_until on the terminal row', async () => {
    const until = '2026-04-25T10:00:00Z';
    vi.mocked(queryOne).mockResolvedValue({ ...baseState, manager_pin_throttle_until: until });

    await setManagerPinThrottle(db, 'terminal-1', until);

    expect(execute).toHaveBeenCalledOnce();

    const state = await getTerminalState(db, 'terminal-1');
    expect(state?.manager_pin_throttle_until).toBe(until);
  });

  it('setManagerPinFailedAttempts persists failed_attempts count on the terminal row', async () => {
    vi.mocked(queryOne).mockResolvedValue({ ...baseState, manager_pin_failed_attempts: 3 });

    await setManagerPinFailedAttempts(db, 'terminal-1', 3);

    expect(execute).toHaveBeenCalledOnce();

    const state = await getTerminalState(db, 'terminal-1');
    expect(state?.manager_pin_failed_attempts).toBe(3);
  });
});
