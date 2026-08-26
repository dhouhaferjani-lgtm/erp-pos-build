/**
 * B-13 gate r2 (R2-1) — the authority TTL must be dated from a stamp that ONLY
 * a roster refresh writes.
 *
 * Round 1 dated it from `operator_pins.synced_at`, which reads like an
 * authority stamp but is not: `updateOperatorDiscountPermissions` and
 * `invalidateTerminalDiscountPermissions` both bumped it, and the first fires
 * on EVERY offline-accepted PIN verify (via `resolveOnlineDiscountPermissions`).
 * A device that is online but whose roster pull is broken — pin-data 403 after
 * the device account loses `pos.operate_terminal`, or a persistently failing
 * sync, both of which `pullOperatorPins` swallows and returns 0 for — had its
 * authority clock reset by an endpoint that carries no authority. A demoted
 * manager's cached roles then stayed "fresh" forever: exactly the hazard R1-3
 * was ruled to close.
 *
 * The stamp is now `sync_metadata.operators_last_sync`, written at
 * `syncService.ts` only after a successful `upsertOperators`.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const mocks = vi.hoisted(() => ({ getSyncMetadata: vi.fn() }));
vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: mocks.getSyncMetadata,
}));

import {
  OPERATOR_ROSTER_SYNC_KEY,
  readOperatorAuthoritySyncedAt,
} from '../operatorAuthorityFreshness';

const DB = {} as never;

describe('readOperatorAuthoritySyncedAt', () => {
  beforeEach(() => {
    mocks.getSyncMetadata.mockReset();
  });

  it('reads the ROSTER-pull stamp, not any per-operator row', async () => {
    mocks.getSyncMetadata.mockResolvedValue('2026-08-26T10:00:00.000Z');
    await expect(readOperatorAuthoritySyncedAt(DB)).resolves.toBe('2026-08-26T10:00:00.000Z');
    expect(mocks.getSyncMetadata).toHaveBeenCalledWith(DB, OPERATOR_ROSTER_SYNC_KEY);
    expect(OPERATOR_ROSTER_SYNC_KEY).toBe('operators_last_sync');
  });

  it('returns null when the roster has never been pulled', async () => {
    mocks.getSyncMetadata.mockResolvedValue(null);
    await expect(readOperatorAuthoritySyncedAt(DB)).resolves.toBeNull();
  });

  it('fails CLOSED (null ⇒ stale) when the metadata read throws', async () => {
    mocks.getSyncMetadata.mockRejectedValue(new Error('db gone'));
    await expect(readOperatorAuthoritySyncedAt(DB)).resolves.toBeNull();
  });
});
