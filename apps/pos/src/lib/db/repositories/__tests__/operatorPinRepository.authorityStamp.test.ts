/**
 * B-13 gate r2 (R2-1) — the discount-permission writers must not touch
 * `synced_at`. They carry no authority: they refresh discount fields from
 * `/pos/discount-permissions` and never re-read `roles` or `permissions`.
 * They already have `discount_permissions_fetched_at` for their own
 * bookkeeping.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

const mocks = vi.hoisted(() => ({ execute: vi.fn(), queryAll: vi.fn(), queryOne: vi.fn() }));
vi.mock('@/lib/db', () => ({
  execute: mocks.execute,
  queryAll: mocks.queryAll,
  queryOne: mocks.queryOne,
}));

import {
  invalidateTerminalDiscountPermissions,
  updateOperatorDiscountPermissions,
  upsertOperators,
} from '../operatorPinRepository';

const DB = {} as never;

function lastSql(): string {
  const calls = mocks.execute.mock.calls;
  const call = calls[calls.length - 1];
  if (!call) throw new Error('execute was never called');
  return String(call[1]);
}

describe('operator_pins authority stamp (R2-1)', () => {
  beforeEach(() => {
    mocks.execute.mockReset();
    mocks.execute.mockResolvedValue(undefined);
  });

  it('updateOperatorDiscountPermissions does NOT bump synced_at', async () => {
    await updateOperatorDiscountPermissions(DB, 'op-1', {
      can_discount: true,
      max_discount_percent: 10,
      user_can_discount: true,
      user_max_discount_percent: 10,
      can_apply_line_discounts: true,
      can_apply_transaction_discounts: true,
      fetched_at: new Date().toISOString(),
      terminal_code: 'POS01',
      status: 'fresh',
    });
    expect(lastSql()).not.toMatch(/synced_at\s*=/);
    // Its own bookkeeping stamp is still written.
    expect(lastSql()).toMatch(/discount_permissions_fetched_at\s*=/);
  });

  it('invalidateTerminalDiscountPermissions does NOT bump synced_at', async () => {
    await invalidateTerminalDiscountPermissions(DB, 'POS01');
    expect(lastSql()).not.toMatch(/synced_at\s*=/);
  });

  it('the ROSTER pull still stamps synced_at', async () => {
    await upsertOperators(DB, [{
      id: 'op-1',
      name: 'Jane',
      email: 'jane@example.com',
      pin_hash: 'hash',
      roles: ['manager'],
      permissions: ['pos.view_reports'],
      can_discount: true,
      max_discount_percent: 10,
    }]);
    expect(lastSql()).toMatch(/synced_at/);
  });
});
