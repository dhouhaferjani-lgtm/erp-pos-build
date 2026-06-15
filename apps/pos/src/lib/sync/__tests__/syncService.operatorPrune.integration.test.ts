/**
 * FU-1 — end-to-end proof that a confirmed-full `/pos/auth/pin-data` pull
 * actually DELETES a stale operator from the real `operator_pins` table.
 *
 * The unit test in `syncService.test.ts` mocks the operator repository, so it
 * only proves `pullOperatorPins` calls `pruneOperatorsExcept` with the right
 * args. This test exercises the real repository against a real SQLite engine,
 * proving the suspended-then-omitted manager's local PIN row is genuinely gone
 * — the actual security outcome FU-1 promises.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPostRaw: vi.fn(),
  ApiRequestError: class ApiRequestError extends Error {},
}));

import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { getAllOperators, upsertOperators } from '@/lib/db/repositories/operatorPinRepository';
import { pullOperatorPins } from '../syncService';
import { apiGet } from '@/lib/api';

function serverOperator(id: string, roles: string[], approvalScopes: string[] = []) {
  return {
    id,
    name: `Op ${id}`,
    email: `${id}@example.com`,
    pin_hash: '$2a$10$abcdefghijklmnopqrstuv',
    roles,
    permissions: ['pos.operate_terminal'],
    approval_scopes: approvalScopes,
    can_discount: false,
    max_discount_percent: null,
  };
}

describe('pullOperatorPins — FU-1 end-to-end prune (real SQLite)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
    vi.clearAllMocks();
  });

  afterEach(() => {
    adapter.close();
  });

  it('deletes a suspended-then-omitted operator from operator_pins', async () => {
    const db = adapter.asDatabase();
    // Two operators cached locally — a cashier and a manager who can approve
    // offline overrides.
    await upsertOperators(db, [
      serverOperator('op-keep', ['cashier']),
      serverOperator('op-suspended', ['manager'], ['close_shift_variance']),
    ]);

    // The manager is suspended server-side, so F-3 omits them from the next
    // authoritative pull.
    vi.mocked(apiGet).mockResolvedValue([serverOperator('op-keep', ['cashier'])]);

    const count = await pullOperatorPins(db, 'term-1');

    expect(count).toBe(1);
    const remaining = (await getAllOperators(db)).map((op) => op.id);
    expect(remaining).toEqual(['op-keep']);
  });

  it('does NOT delete cached operators when the pull returns empty', async () => {
    const db = adapter.asDatabase();
    await upsertOperators(db, [
      serverOperator('op-a', ['cashier']),
      serverOperator('op-b', ['manager']),
    ]);

    vi.mocked(apiGet).mockResolvedValue([]);

    await pullOperatorPins(db, 'term-1');

    const remaining = (await getAllOperators(db)).map((op) => op.id).sort();
    expect(remaining).toEqual(['op-a', 'op-b']);
  });
});
