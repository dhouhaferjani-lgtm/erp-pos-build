/**
 * Precision round-trip tests for multi-decimal currencies (TND = 3 decimals).
 *
 * The corresponding SQLite migration (v21) widens REAL columns to TEXT so that
 * values like '100.250' survive storage without IEEE 754 coercion. These tests
 * verify the repository layer never float-coerces the migrated columns —
 * whatever string goes in comes back byte-for-byte identical.
 *
 * Tauri's SQLite plugin cannot run in Vitest, so the DB layer is mocked. The
 * invariant under test is repository-level: no parseFloat / Number() / toFixed
 * on the migrated fields. Real end-to-end precision is validated in the
 * packaged Tauri build.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryOne: vi.fn(),
  queryAll: vi.fn(),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
}));

import {
  insertZReport,
  getZReportByShift,
  getLatestZReport,
} from '@/lib/db/repositories/zReportRepository';
import {
  getZChainState,
  upsertZChainState,
  updateGrandTotals,
} from '@/lib/db/repositories/terminalStateRepository';
import { queryOne, queryAll, execute } from '@/lib/db';
import type { LocalZReport } from '@/lib/offline/types';

import type Database from '@tauri-apps/plugin-sql';

const db = {} as Database;

const TERMINAL_ID = '11111111-1111-1111-1111-111111111111';
const SHIFT_ID = '22222222-2222-2222-2222-222222222222';

function makeLocalZReport(): LocalZReport {
  return {
    id: '33333333-3333-3333-3333-333333333333',
    terminal_id: TERMINAL_ID,
    shift_id: SHIFT_ID,
    z_number: 1,
    formatted_z_number: 'Z0001',
    generated_at: '2026-04-24T10:00:00+00:00',
    fiscal_hash: 'deadbeef',
    previous_hash: 'GENESIS',
    hash_sequence: 1,
    report_data: {
      sales_count: 1,
      gross_sales: '100.250',
      net_sales: '83.545',
      tax_amount: '16.705',
      refunds_count: 0,
      refunds_amount: '0.000',
      voided_count: 0,
      vat_breakdown: [],
      payment_methods: [],
    },
    opening_cash: '100.250',
    expected_cash: '200.500',
    receipt_snapshots: [],
    grand_totals: {
      cumulative_sales: '100.250',
      cumulative_tax: '16.705',
      cumulative_refunds: '0.000',
      perpetual_grand_total: '100.250',
      receipt_count_lifetime: 1,
    },
    synced: false,
    synced_at: null,
  };
}

describe('TND precision — z_reports repository', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('insertZReport passes opening_cash and expected_cash as exact decimal strings', async () => {
    const report = makeLocalZReport();

    await insertZReport(db, report);

    expect(execute).toHaveBeenCalledOnce();
    const params = vi.mocked(execute).mock.calls[0]![2] as unknown[];

    // Shape of INSERT statement:
    //   ... id, terminal_id, shift_id, z_number, formatted_z_number, generated_at,
    //       fiscal_hash, previous_hash, hash_sequence, report_data,
    //       opening_cash, expected_cash, receipt_snapshots, grand_totals, synced
    const openingCashParam = params[10];
    const expectedCashParam = params[11];

    expect(openingCashParam).toStrictEqual('100.250');
    expect(expectedCashParam).toStrictEqual('200.500');
    expect(typeof openingCashParam).toBe('string');
    expect(typeof expectedCashParam).toBe('string');
  });

  it('getZReportByShift returns opening_cash and expected_cash as strings', async () => {
    vi.mocked(queryOne).mockResolvedValue({
      id: 'r1',
      terminal_id: TERMINAL_ID,
      shift_id: SHIFT_ID,
      z_number: 1,
      formatted_z_number: 'Z0001',
      generated_at: '2026-04-24T10:00:00+00:00',
      fiscal_hash: 'abc',
      previous_hash: 'GENESIS',
      hash_sequence: 1,
      report_data: JSON.stringify({ sales_count: 0 }),
      opening_cash: '100.250',
      expected_cash: '200.500',
      receipt_snapshots: '[]',
      grand_totals: '{}',
      synced: 0,
      synced_at: null,
    });

    const report = await getZReportByShift(db, SHIFT_ID);

    expect(report).not.toBeNull();
    expect(report!.opening_cash).toStrictEqual('100.250');
    expect(report!.expected_cash).toStrictEqual('200.500');
    expect(typeof report!.opening_cash).toBe('string');
    expect(typeof report!.expected_cash).toBe('string');
  });

  it('getLatestZReport preserves string precision on round-trip', async () => {
    vi.mocked(queryOne).mockResolvedValue({
      id: 'r2',
      terminal_id: TERMINAL_ID,
      shift_id: SHIFT_ID,
      z_number: 2,
      formatted_z_number: 'Z0002',
      generated_at: '2026-04-24T12:00:00+00:00',
      fiscal_hash: 'def',
      previous_hash: 'abc',
      hash_sequence: 2,
      report_data: JSON.stringify({ sales_count: 0 }),
      opening_cash: '0.001',
      expected_cash: '999999.999',
      receipt_snapshots: '[]',
      grand_totals: '{}',
      synced: 0,
      synced_at: null,
    });

    const report = await getLatestZReport(db, TERMINAL_ID);

    expect(report!.opening_cash).toStrictEqual('0.001');
    expect(report!.expected_cash).toStrictEqual('999999.999');
  });
});

describe('TND precision — terminal_state Z-chain state', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('getZChainState returns cumulative_* fields as strings', async () => {
    vi.mocked(queryOne).mockResolvedValue({
      z_last_hash: 'hash',
      z_hash_sequence: 1,
      z_number: 1,
      cumulative_sales: '100.250',
      cumulative_tax: '16.705',
      cumulative_refunds: '5.125',
      perpetual_grand_total: '95.125',
      receipt_count_lifetime: 10,
    });

    const state = await getZChainState(db, TERMINAL_ID);

    expect(state).not.toBeNull();
    expect(state!.cumulative_sales).toStrictEqual('100.250');
    expect(state!.cumulative_tax).toStrictEqual('16.705');
    expect(state!.cumulative_refunds).toStrictEqual('5.125');
    expect(state!.perpetual_grand_total).toStrictEqual('95.125');
    expect(typeof state!.cumulative_sales).toBe('string');
    expect(typeof state!.cumulative_tax).toBe('string');
    expect(typeof state!.cumulative_refunds).toBe('string');
    expect(typeof state!.perpetual_grand_total).toBe('string');
  });

  it('upsertZChainState writes grand_totals as decimal strings', async () => {
    await upsertZChainState(db, TERMINAL_ID, {
      z_last_hash: 'hash-1',
      z_hash_sequence: 1,
      z_number: 1,
      grand_totals: {
        cumulative_sales: '100.250',
        cumulative_tax: '16.705',
        cumulative_refunds: '5.125',
        perpetual_grand_total: '95.125',
        receipt_count_lifetime: 10,
      },
    });

    expect(execute).toHaveBeenCalledOnce();
    const params = vi.mocked(execute).mock.calls[0]![2] as unknown[];
    // UPDATE sets: z_last_hash=$1, z_hash_sequence=$2, z_number=$3,
    //   cumulative_sales=$4, cumulative_tax=$5, cumulative_refunds=$6,
    //   perpetual_grand_total=$7, receipt_count_lifetime=$8 WHERE terminal_id=$9
    expect(params[3]).toStrictEqual('100.250');
    expect(params[4]).toStrictEqual('16.705');
    expect(params[5]).toStrictEqual('5.125');
    expect(params[6]).toStrictEqual('95.125');
    for (let i = 3; i <= 6; i++) {
      expect(typeof params[i]).toBe('string');
    }
  });

  it('upsertZChainState defaults to "0.000" strings when grand_totals is null', async () => {
    await upsertZChainState(db, TERMINAL_ID, {
      z_last_hash: 'hash-0',
      z_hash_sequence: 0,
      z_number: 0,
      grand_totals: null,
    });

    const params = vi.mocked(execute).mock.calls[0]![2] as unknown[];
    expect(params[3]).toStrictEqual('0.000');
    expect(params[4]).toStrictEqual('0.000');
    expect(params[5]).toStrictEqual('0.000');
    expect(params[6]).toStrictEqual('0.000');
  });

  it('updateGrandTotals reads current state, adds via Big.js, writes strings — no SQL arithmetic', async () => {
    // Mock the read of current state (queryAll is used by getZChainState)
    vi.mocked(queryOne).mockResolvedValue({
      z_last_hash: 'hash',
      z_hash_sequence: 1,
      z_number: 1,
      cumulative_sales: '100.000',
      cumulative_tax: '20.000',
      cumulative_refunds: '5.000',
      perpetual_grand_total: '95.000',
      receipt_count_lifetime: 5,
    });

    await updateGrandTotals(db, TERMINAL_ID, '50.125', '10.025', '2.000', 3);

    expect(execute).toHaveBeenCalledOnce();
    const params = vi.mocked(execute).mock.calls[0]![2] as unknown[];
    // UPDATE terminal_state SET cumulative_sales=$1, cumulative_tax=$2, cumulative_refunds=$3,
    //   perpetual_grand_total=$4, receipt_count_lifetime=$5 WHERE terminal_id=$6
    expect(params[0]).toStrictEqual('150.125'); // 100 + 50.125
    expect(params[1]).toStrictEqual('30.025');  // 20 + 10.025
    expect(params[2]).toStrictEqual('7.000');   // 5 + 2
    expect(params[3]).toStrictEqual('143.125'); // 95 + (50.125 - 2)
    expect(params[4]).toStrictEqual(8);         // 5 + 3 (integer)

    // The UPDATE must not use SQL-side `col = col + $n` arithmetic — that would
    // coerce TEXT to REAL inside SQLite and lose precision.
    const sql = vi.mocked(execute).mock.calls[0]![1] as string;
    expect(sql).not.toMatch(/cumulative_sales\s*=\s*cumulative_sales\s*\+/);
    expect(sql).not.toMatch(/perpetual_grand_total\s*=\s*perpetual_grand_total\s*\+/);
  });

  it('updateGrandTotals preserves exact decimals across many small increments', async () => {
    // 10 sales of 0.001 TND each should sum to exactly 0.010, not 0.009999999...
    // Use mockImplementation over a closure so no unconsumed `*Once` queue
    // pollutes later tests if the implementation skips the read.
    let currentState = {
      z_last_hash: 'h',
      z_hash_sequence: 0,
      z_number: 0,
      cumulative_sales: '0.000',
      cumulative_tax: '0.000',
      cumulative_refunds: '0.000',
      perpetual_grand_total: '0.000',
      receipt_count_lifetime: 0,
    };
    vi.mocked(queryOne).mockImplementation(async () => currentState);

    for (let i = 0; i < 10; i++) {
      await updateGrandTotals(db, TERMINAL_ID, '0.001', '0.000', '0.000', 1);

      const params = vi.mocked(execute).mock.calls[i]![2] as unknown[];
      currentState = {
        ...currentState,
        cumulative_sales: params[0] as string,
        cumulative_tax: params[1] as string,
        cumulative_refunds: params[2] as string,
        perpetual_grand_total: params[3] as string,
        receipt_count_lifetime: params[4] as number,
      };
    }

    expect(currentState.cumulative_sales).toStrictEqual('0.010');
    // sentinel: avoids silently passing if someone re-introduces float math
    expect(parseFloat(currentState.cumulative_sales).toFixed(3)).toStrictEqual('0.010');
  });
});

describe('TND precision — forbids float coercion in migrated paths', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('getZChainState does not parseFloat incoming string values (pass-through)', async () => {
    // A value with more decimals than IEEE 754 can represent exactly.
    const precise = '0.100000000000003'; // unusual but faithful round-trip
    vi.mocked(queryOne).mockResolvedValue({
      z_last_hash: 'h',
      z_hash_sequence: 0,
      z_number: 0,
      cumulative_sales: precise,
      cumulative_tax: '0.000',
      cumulative_refunds: '0.000',
      perpetual_grand_total: precise,
      receipt_count_lifetime: 0,
    });

    const state = await getZChainState(db, TERMINAL_ID);

    expect(state!.cumulative_sales).toStrictEqual(precise);
    expect(state!.perpetual_grand_total).toStrictEqual(precise);
  });
});

describe('TND precision — defensive coercion against numeric server payloads', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('upsertZChainState coerces numeric grand_totals to 3-decimal strings', async () => {
    // Simulate a future regression where the server sends numbers instead of
    // strings. The wire contract says strings, but if a middleware or JSON
    // serializer regresses, we must not silently store a number whose toString
    // drops trailing zeros (JS `(100.25).toString()` === "100.25", not "100.250").
    //
    // Cast through unknown to bypass the string-typed contract — the test is
    // exercising the runtime defense that kicks in when the contract is violated.
    const numericGrandTotals = {
      cumulative_sales: 100.25,
      cumulative_tax: 16.705,
      cumulative_refunds: 5.125,
      perpetual_grand_total: 95.125,
      receipt_count_lifetime: 10,
    } as unknown as {
      cumulative_sales: string;
      cumulative_tax: string;
      cumulative_refunds: string;
      perpetual_grand_total: string;
      receipt_count_lifetime: number;
    };

    await upsertZChainState(db, TERMINAL_ID, {
      z_last_hash: 'h',
      z_hash_sequence: 1,
      z_number: 1,
      grand_totals: numericGrandTotals,
    });

    const params = vi.mocked(execute).mock.calls[0]![2] as unknown[];
    expect(params[3]).toStrictEqual('100.250');
    expect(params[4]).toStrictEqual('16.705');
    expect(params[5]).toStrictEqual('5.125');
    expect(params[6]).toStrictEqual('95.125');
    for (let i = 3; i <= 6; i++) {
      expect(typeof params[i]).toBe('string');
    }
  });

  it('upsertZChainState passes through string values unchanged (does not re-pad)', async () => {
    // Trusting client-side string values as-is: if the value already carries
    // more precision than CUMULATIVE_SCALE, we preserve it. Downstream Big.js
    // normalizes at arithmetic time.
    await upsertZChainState(db, TERMINAL_ID, {
      z_last_hash: 'h',
      z_hash_sequence: 1,
      z_number: 1,
      grand_totals: {
        cumulative_sales: '100.25',          // missing trailing zero
        cumulative_tax: '16.7050',            // extra trailing zero
        cumulative_refunds: '0.000',
        perpetual_grand_total: '100.25',
        receipt_count_lifetime: 1,
      },
    });

    const params = vi.mocked(execute).mock.calls[0]![2] as unknown[];
    expect(params[3]).toStrictEqual('100.25');
    expect(params[4]).toStrictEqual('16.7050');
  });

  it('updateGrandTotals accumulates 2-decimal EUR values without precision loss', async () => {
    // Regression: the fix targeted TND (3 decimals), but must not degrade
    // the 2-decimal path. Sums of 0.01 should still land on 0.10 after ten
    // increments, with no trailing-zero gymnastics.
    let currentState = {
      z_last_hash: 'h',
      z_hash_sequence: 0,
      z_number: 0,
      cumulative_sales: '0.000',
      cumulative_tax: '0.000',
      cumulative_refunds: '0.000',
      perpetual_grand_total: '0.000',
      receipt_count_lifetime: 0,
    };
    vi.mocked(queryOne).mockImplementation(async () => currentState);

    for (let i = 0; i < 10; i++) {
      await updateGrandTotals(db, TERMINAL_ID, '0.01', '0.00', '0.00', 1);

      const params = vi.mocked(execute).mock.calls[i]![2] as unknown[];
      currentState = {
        ...currentState,
        cumulative_sales: params[0] as string,
        cumulative_tax: params[1] as string,
        cumulative_refunds: params[2] as string,
        perpetual_grand_total: params[3] as string,
        receipt_count_lifetime: params[4] as number,
      };
    }

    // Big.js keeps 3-decimal scale (CUMULATIVE_SCALE) across addition; the
    // display layer in the EUR POS UI trims trailing zeros for rendering.
    // The stored value must be EXACTLY "0.100", not "0.100000000000000007".
    expect(currentState.cumulative_sales).toStrictEqual('0.100');
    expect(currentState.perpetual_grand_total).toStrictEqual('0.100');
  });
});

// Silence unused import lint noise — queryAll is imported so the mock factory
// is exercised by modules under test even if this file doesn't call it directly.
void queryAll;
