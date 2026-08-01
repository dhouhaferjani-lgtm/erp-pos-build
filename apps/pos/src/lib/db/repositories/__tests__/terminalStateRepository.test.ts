import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
}));

import {
  upsertTerminalState,
  setManagerPinThrottle,
  setManagerPinFailedAttempts,
  getTerminalState,
  getV4RefundAuthoringEnabled,
  setV4RefundAuthoringEnabled,
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

// v3-refund-chain-integration spec §9.1 — a dedicated, guard-independent
// setter/reader pair for the v4 refund-authoring capability flag, mirroring
// `setShiftNumberSeed`'s exact template so a new capability can never be
// silently swallowed by `upsertTerminalState`'s regression guard.
describe('terminalStateRepository — v4 refund-authoring capability flag (§9.1)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('setV4RefundAuthoringEnabled writes a plain UPDATE independent of upsertTerminalState', async () => {
    await setV4RefundAuthoringEnabled(db, 'terminal-1', true);

    expect(execute).toHaveBeenCalledOnce();
    // Guard-independence: it must NOT read current state first (no
    // regression check, no queryOne call) — a plain, unconditional UPDATE,
    // exactly like setShiftNumberSeed.
    expect(queryOne).not.toHaveBeenCalled();
    const [, sql, params] = vi.mocked(execute).mock.calls[0] as [unknown, string, unknown[]];
    expect(sql).toMatch(/UPDATE terminal_state SET v4_refund_authoring_enabled/);
    expect(params).toEqual([1, 'terminal-1']);
  });

  it('setV4RefundAuthoringEnabled(false) writes 0, not a falsy string', async () => {
    await setV4RefundAuthoringEnabled(db, 'terminal-1', false);

    const [, , params] = vi.mocked(execute).mock.calls[0] as [unknown, string, unknown[]];
    expect(params).toEqual([0, 'terminal-1']);
  });

  it('getV4RefundAuthoringEnabled reads true only when the column stores exactly 1', async () => {
    vi.mocked(queryOne).mockResolvedValue({ v4_refund_authoring_enabled: 1 });

    await expect(getV4RefundAuthoringEnabled(db, 'terminal-1')).resolves.toBe(true);
  });

  it('getV4RefundAuthoringEnabled defaults to false when no row exists', async () => {
    vi.mocked(queryOne).mockResolvedValue(null);

    await expect(getV4RefundAuthoringEnabled(db, 'terminal-1')).resolves.toBe(false);
  });

  it('getV4RefundAuthoringEnabled defaults to false on a pre-v65 schema (no such column)', async () => {
    vi.mocked(queryOne).mockRejectedValue(new Error('no such column: v4_refund_authoring_enabled'));

    await expect(getV4RefundAuthoringEnabled(db, 'terminal-1')).resolves.toBe(false);
  });

  it('getV4RefundAuthoringEnabled re-throws an unrelated error (fail loudly, not silently)', async () => {
    vi.mocked(queryOne).mockRejectedValue(new Error('disk I/O error'));

    await expect(getV4RefundAuthoringEnabled(db, 'terminal-1')).rejects.toThrow('disk I/O error');
  });
});
