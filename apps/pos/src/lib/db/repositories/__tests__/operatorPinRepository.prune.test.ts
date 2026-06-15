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
 * operator NOT present in the pull is removed — except the actively-logged-in
 * operator, which is never pruned (so a scope/response edge case can't lock the
 * current session out of its own terminal).
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

  it('never deletes the active operator even when it is omitted from keepIds', async () => {
    const db = adapter.asDatabase();
    await upsertOperators(db, [makeOperator('op-active'), makeOperator('op-stale')]);

    const deleted = await pruneOperatorsExcept(db, ['someone-else'], 'op-active');

    expect(deleted).toBe(1);
    expect(await ids()).toEqual(['op-active']);
  });

  it('with empty keepIds, removes all operators except the active one', async () => {
    const db = adapter.asDatabase();
    await upsertOperators(db, [
      makeOperator('op-active'),
      makeOperator('op-x'),
      makeOperator('op-y'),
    ]);

    const deleted = await pruneOperatorsExcept(db, [], 'op-active');

    expect(deleted).toBe(2);
    expect(await ids()).toEqual(['op-active']);
  });
});
