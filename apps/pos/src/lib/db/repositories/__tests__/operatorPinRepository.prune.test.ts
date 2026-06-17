/**
 * FU-1 (HIGH) — authoritative operator-PIN cache pruning.
 *
 * Before F-3, a manager mirrored into `operator_pins` BEFORE being suspended
 * kept a valid local PIN + cached `approval_scopes` forever: the device only
 * ever upserted, never deleted, so a later `/pos/auth/pin-data` pull that
 * omitted the now-suspended manager left the stale row in place. The offline
 * approval path verifies client-side bcrypt against this local store and never
 * calls the server `PinVerifier`, so the suspended manager could still approve
 * offline overrides.
 *
 * `pruneOperatorsExcept` makes a confirmed-full pull authoritative: any local
 * operator NOT present in the pull is removed. There is deliberately no
 * active-operator carve-out — the server returns every active member with a
 * `pos_pin`, so a legitimate active operator is always in `keepIds`; a carve-out
 * would only ever shield a just-suspended operator (Codex review, FU-1 HIGH).
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  getAllOperators,
  pruneOperatorsExcept,
  upsertOperators,
} from '../operatorPinRepository';

function makeOperator(id: string) {
  return {
    id,
    name: `Operator ${id}`,
    email: `${id}@example.com`,
    pin_hash: '$2a$10$abcdefghijklmnopqrstuv',
    roles: ['cashier'],
    permissions: ['pos.operate_terminal'],
    company_ids: ['company-1'],
    terminal_ids: ['terminal-1'],
    approval_scopes: ['close_shift_variance'],
    approval_scope_permissions_fetched_at: '2026-06-14T08:00:00Z',
    can_discount: false,
    max_discount_percent: null,
  };
}

describe('operatorPinRepository — pruneOperatorsExcept (FU-1)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  async function ids(): Promise<string[]> {
    const ops = await getAllOperators(adapter.asDatabase());
    return ops.map((op) => op.id).sort();
  }

  it('deletes operators whose id is not in keepIds', async () => {
    const db = adapter.asDatabase();
    await upsertOperators(db, [makeOperator('op-keep'), makeOperator('op-stale')]);

    const deleted = await pruneOperatorsExcept(db, ['op-keep']);

    expect(deleted).toBe(1);
    expect(await ids()).toEqual(['op-keep']);
  });

  it('keeps every operator present in keepIds (no deletions)', async () => {
    const db = adapter.asDatabase();
    await upsertOperators(db, [makeOperator('op-a'), makeOperator('op-b')]);

    const deleted = await pruneOperatorsExcept(db, ['op-a', 'op-b']);

    expect(deleted).toBe(0);
    expect(await ids()).toEqual(['op-a', 'op-b']);
  });

  // Proves the positional `$1, $2, …` placeholder binding for keepIds.length > 1
  // while still deleting the operators outside the keep-set.
  it('keeps multiple operators and deletes the rest', async () => {
    const db = adapter.asDatabase();
    await upsertOperators(db, [
      makeOperator('op-a'),
      makeOperator('op-b'),
      makeOperator('op-stale-1'),
      makeOperator('op-stale-2'),
    ]);

    const deleted = await pruneOperatorsExcept(db, ['op-a', 'op-b']);

    expect(deleted).toBe(2);
    expect(await ids()).toEqual(['op-a', 'op-b']);
  });

  // Defensive: an empty keep-list must NEVER mass-wipe the cache (that would
  // break ALL offline approvals). The sole caller gates on a non-empty pull,
  // so this only guards against a programming error.
  it('treats an empty keepIds as a no-op and deletes nothing', async () => {
    const db = adapter.asDatabase();
    await upsertOperators(db, [makeOperator('op-a'), makeOperator('op-b')]);

    const deleted = await pruneOperatorsExcept(db, []);

    expect(deleted).toBe(0);
    expect(await ids()).toEqual(['op-a', 'op-b']);
  });
});
