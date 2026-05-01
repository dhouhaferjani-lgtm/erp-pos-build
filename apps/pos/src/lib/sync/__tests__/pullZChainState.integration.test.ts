/**
 * Integration tests for pullZChainState null/zero defense.
 *
 * Regression guard: an online-only tenant whose Z-reports are generated
 * server-side (no offline sync-up) returns `grand_totals: null` from
 * GET /api/v1/pos/terminals/{id}/z-chain-state. Previously, pullZChainState
 * would silently clobber locally-accumulated cumulative_* counters with
 * zero defaults — a TND-precision problem waiting to happen.
 *
 * These tests pin the defensive behavior:
 *   - null or all-zero server grand_totals, non-zero local → preserve local.
 *   - any counter moves backwards → throw FiscalRegressionError.
 *   - all counters move forward → apply.
 *
 * Uses the real node:sqlite adapter so the preservation logic is exercised
 * against the actual TEXT-typed schema rather than a mock.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import {
  upsertTerminalState,
  upsertZChainState,
  getZChainState,
  FiscalRegressionError,
} from '@/lib/db/repositories/terminalStateRepository';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
  cleanupOldSyncLogs: vi.fn().mockResolvedValue(undefined),
}));

import { apiGet } from '@/lib/api';
import { pullZChainState } from '../syncService';

const TERMINAL_ID = '11111111-1111-1111-1111-111111111111';

const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function runAllMigrations(adapter: SqliteTestAdapter): Promise<void> {
  for (const m of migrations) {
    if (m.run) {
      await m.run(adapter);
    } else if (m.sql) {
      await adapter.execute(m.sql);
    }
  }
}

async function seedTerminalWithCumulative(
  adapter: SqliteTestAdapter,
  cumulative: {
    cumulative_sales: string;
    cumulative_tax: string;
    cumulative_refunds: string;
    perpetual_grand_total: string;
    receipt_count_lifetime: number;
  },
  z_hash_sequence = 5,
  z_number = 5,
  z_last_hash = 'hash-prev',
): Promise<void> {
  await upsertTerminalState(adapter.asDatabase(), {
    terminal_id: TERMINAL_ID,
    terminal_code: 'T001',
    location_code: 'MAIN',
    genesis_seed: 'seed',
    last_hash: 'GENESIS',
    hash_sequence: 0,
    manager_pin_throttle_until: null,
    manager_pin_failed_attempts: 0,
    fiscal_schema_version: 2,
  });
  await upsertZChainState(adapter.asDatabase(), TERMINAL_ID, {
    z_last_hash,
    z_hash_sequence,
    z_number,
    grand_totals: cumulative,
  });
}

d('pullZChainState null/zero defense (integration)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    vi.clearAllMocks();
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('preserves local cumulative_sales when server returns null grand_totals', async () => {
    await seedTerminalWithCumulative(adapter, {
      cumulative_sales: '500.000',
      cumulative_tax: '100.000',
      cumulative_refunds: '20.000',
      perpetual_grand_total: '480.000',
      receipt_count_lifetime: 50,
    });

    vi.mocked(apiGet).mockResolvedValue({
      z_last_hash: 'hash-prev',
      z_hash_sequence: 5,
      z_number: 5,
      grand_totals: null,
    });

    const ok = await pullZChainState(adapter.asDatabase(), TERMINAL_ID);

    expect(ok).toBe(true);
    const state = await getZChainState(adapter.asDatabase(), TERMINAL_ID);
    expect(state?.cumulative_sales).toBe('500.000');
    expect(state?.cumulative_tax).toBe('100.000');
    expect(state?.cumulative_refunds).toBe('20.000');
    expect(state?.perpetual_grand_total).toBe('480.000');
    expect(state?.receipt_count_lifetime).toBe(50);
  });

  it('preserves local cumulative_sales when server returns all-zero grand_totals and local is non-zero', async () => {
    await seedTerminalWithCumulative(adapter, {
      cumulative_sales: '500.000',
      cumulative_tax: '100.000',
      cumulative_refunds: '20.000',
      perpetual_grand_total: '480.000',
      receipt_count_lifetime: 50,
    });

    vi.mocked(apiGet).mockResolvedValue({
      z_last_hash: 'hash-prev',
      z_hash_sequence: 5,
      z_number: 5,
      grand_totals: {
        cumulative_sales: '0.000',
        cumulative_tax: '0.000',
        cumulative_refunds: '0.000',
        perpetual_grand_total: '0.000',
        receipt_count_lifetime: 0,
      },
    });

    const ok = await pullZChainState(adapter.asDatabase(), TERMINAL_ID);

    expect(ok).toBe(true);
    const state = await getZChainState(adapter.asDatabase(), TERMINAL_ID);
    expect(state?.cumulative_sales).toBe('500.000');
    expect(state?.receipt_count_lifetime).toBe(50);
  });

  it('applies forward-moving grand_totals when server is ahead of local', async () => {
    await seedTerminalWithCumulative(adapter, {
      cumulative_sales: '500.000',
      cumulative_tax: '100.000',
      cumulative_refunds: '20.000',
      perpetual_grand_total: '480.000',
      receipt_count_lifetime: 50,
    });

    vi.mocked(apiGet).mockResolvedValue({
      z_last_hash: 'hash-new',
      z_hash_sequence: 6,
      z_number: 6,
      grand_totals: {
        cumulative_sales: '750.000',
        cumulative_tax: '150.000',
        cumulative_refunds: '30.000',
        perpetual_grand_total: '720.000',
        receipt_count_lifetime: 75,
      },
    });

    const ok = await pullZChainState(adapter.asDatabase(), TERMINAL_ID);

    expect(ok).toBe(true);
    const state = await getZChainState(adapter.asDatabase(), TERMINAL_ID);
    expect(state?.cumulative_sales).toBe('750.000');
    expect(state?.cumulative_tax).toBe('150.000');
    expect(state?.cumulative_refunds).toBe('30.000');
    expect(state?.perpetual_grand_total).toBe('720.000');
    expect(state?.receipt_count_lifetime).toBe(75);
  });

  it('preserves local state when any cumulative counter would move backwards', async () => {
    await seedTerminalWithCumulative(adapter, {
      cumulative_sales: '500.000',
      cumulative_tax: '100.000',
      cumulative_refunds: '20.000',
      perpetual_grand_total: '480.000',
      receipt_count_lifetime: 50,
    });

    // cumulative_sales rewinds from 500 -> 400 — must never be accepted.
    vi.mocked(apiGet).mockResolvedValue({
      z_last_hash: 'hash-rewind',
      z_hash_sequence: 6,
      z_number: 6,
      grand_totals: {
        cumulative_sales: '400.000',
        cumulative_tax: '150.000',
        cumulative_refunds: '30.000',
        perpetual_grand_total: '370.000',
        receipt_count_lifetime: 75,
      },
    });

    // pullZChainState must catch the regression internally and NOT propagate —
    // a single bad server reply should not tear down the sync scheduler.
    const ok = await pullZChainState(adapter.asDatabase(), TERMINAL_ID);

    expect(ok).toBe(true);
    const state = await getZChainState(adapter.asDatabase(), TERMINAL_ID);
    // Local state must be untouched.
    expect(state?.cumulative_sales).toBe('500.000');
    expect(state?.cumulative_tax).toBe('100.000');
    expect(state?.cumulative_refunds).toBe('20.000');
    expect(state?.perpetual_grand_total).toBe('480.000');
    expect(state?.receipt_count_lifetime).toBe(50);
  });
});

describe('FiscalRegressionError is exported from terminalStateRepository', () => {
  it('exists and is a proper Error subclass (used by pullZChainState guard)', () => {
    const err = new FiscalRegressionError('t-1', 'upsertZChainState', 500, 400);
    expect(err).toBeInstanceOf(Error);
    expect(err.name).toBe('FiscalRegressionError');
  });
});
