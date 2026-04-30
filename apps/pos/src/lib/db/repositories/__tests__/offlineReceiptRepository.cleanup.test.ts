/**
 * Real-SQLite integration test for `cleanupSyncedReceipts` retention semantics
 * (Phase H Block 2.5a — full-fiscal-year local receipt retention).
 *
 * BEFORE Block 2.5: `cleanupSyncedReceipts` deleted any synced receipt whose
 * `synced_at` was older than 30 days. That was a storage policy.
 *
 * AFTER Block 2.5: receipts are retained for the full current fiscal year. For
 * Phase 1 the fiscal year is the calendar year — deletion only happens for
 * synced receipts whose `created_at` (fiscal posting timestamp) is BEFORE
 * January 1 of the current calendar year.
 *
 * The fixture seeds two synced receipts with `created_at` set to (a) 60 days
 * ago — definitely within the current fiscal year — and (b) Dec 31 of the
 * previous year — definitely outside it. Asserts the previous-year row is
 * deleted and the 60-day-old row is retained.
 */

import { describe, it, expect, beforeEach, afterEach, beforeAll, afterAll, vi } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { migrations } from '@/lib/db/migrations';
import {
  cleanupSyncedReceipts,
  type OfflineReceipt,
} from '../offlineReceiptRepository';

// Skip if Node's built-in node:sqlite isn't available (Node < 22.5).
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

function baseReceipt(): Omit<OfflineReceipt, 'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'> {
  return {
    id: 'placeholder',
    idempotency_key: 'placeholder',
    receipt_number: 'MAIN-T001-0001',
    terminal_id: 't-1',
    terminal_code: 'T001',
    operator_id: 'op-1',
    operator_name: 'Cashier',
    lines: '[]',
    subtotal: '10.00',
    tax_amount: '0.00',
    discount_amount: '0.00',
    total: '10.00',
    currency: 'TND',
    fiscal_hash: 'h',
    previous_hash: 'p',
    hash_sequence: 1,
    transaction_discount_amount: null,
    transaction_discount_reason: null,
    tendered_amount: '10.00',
    change_due: '0.00',
    payment_method_id: 'pm-1',
    payment_repository_id: 'repo-1',
    status: 'synced',
    payments_json: '[]',
    consumption_mode: null,
    table_id: null,
  };
}

/**
 * Insert a synced receipt with explicit `created_at` and `synced_at` (overriding
 * the column defaults). `cleanupSyncedReceipts` keys retention off `created_at`
 * (the fiscal posting timestamp), not `synced_at` (network-upload timestamp).
 */
async function insertSyncedReceipt(
  adapter: SqliteTestAdapter,
  id: string,
  createdAtIso: string,
): Promise<void> {
  const r = baseReceipt();
  await adapter.execute(
    `INSERT INTO offline_receipts (
      id, idempotency_key, receipt_number, terminal_id, terminal_code,
      operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
      total, currency, fiscal_hash, previous_hash, hash_sequence,
      transaction_discount_amount, transaction_discount_reason,
      tendered_amount, change_due, payment_method_id, payment_repository_id,
      status, payments_json, consumption_mode, table_id,
      created_at, synced_at, retry_count
    ) VALUES (
      $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16,
      $17, $18, $19, $20, $21, $22, $23, $24, $25, $26, $27, $28, $29
    )`,
    [
      id, `key-${id}`, r.receipt_number, r.terminal_id, r.terminal_code,
      r.operator_id, r.operator_name, r.lines, r.subtotal, r.tax_amount,
      r.discount_amount, r.total, r.currency, r.fiscal_hash, r.previous_hash,
      r.hash_sequence, r.transaction_discount_amount, r.transaction_discount_reason,
      r.tendered_amount, r.change_due, r.payment_method_id, r.payment_repository_id,
      'synced', r.payments_json, r.consumption_mode, r.table_id,
      createdAtIso, createdAtIso, 0,
    ],
  );
}

d('cleanupSyncedReceipts — full-fiscal-year retention (Phase 1: calendar year)', () => {
  let adapter: SqliteTestAdapter;

  // Pin the JS clock to a mid-year date so that "60 days ago" always resolves
  // within the current calendar year, regardless of when CI runs. Without this
  // anchor the `Date.now() - 60 * 86_400_000` fixture would land in the
  // previous calendar year when tests run between Jan 1 and ~Mar 1, causing the
  // "60-day-old receipt is retained" test to fail.
  beforeAll(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-06-15T12:00:00.000Z'));
  });

  afterAll(() => {
    vi.useRealTimers();
  });

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await runAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('retains a receipt posted 60 days ago (within current fiscal year)', async () => {
    // 60 days ago. SQLite `datetime('now', '-60 days')` resolved at insert time
    // would be ideal, but we want a deterministic ISO string the boundary
    // comparison can chew on. Generate it in JS and pass it in directly.
    const sixtyDaysAgo = new Date(Date.now() - 60 * 86_400_000).toISOString();
    await insertSyncedReceipt(adapter, 'r-recent', sixtyDaysAgo);

    await cleanupSyncedReceipts(adapter.asDatabase());

    const rows = await adapter.select<{ id: string }[]>(
      'SELECT id FROM offline_receipts WHERE id = $1',
      ['r-recent'],
    );
    expect(rows).toHaveLength(1);
  });

  it('deletes a receipt posted on Dec 31 of the previous calendar year', async () => {
    const previousYear = new Date().getUTCFullYear() - 1;
    // 23:59:59 on Dec 31 of the previous year — strictly before the fiscal-year
    // boundary `datetime('now', 'start of year')` (midnight Jan 1 current year).
    const dec31 = `${previousYear}-12-31 23:59:59`;
    await insertSyncedReceipt(adapter, 'r-old', dec31);

    await cleanupSyncedReceipts(adapter.asDatabase());

    const rows = await adapter.select<{ id: string }[]>(
      'SELECT id FROM offline_receipts WHERE id = $1',
      ['r-old'],
    );
    expect(rows).toHaveLength(0);
  });

  it('keeps a recent receipt and deletes a previous-year receipt in the same call', async () => {
    const sixtyDaysAgo = new Date(Date.now() - 60 * 86_400_000).toISOString();
    const previousYear = new Date().getUTCFullYear() - 1;
    const dec31 = `${previousYear}-12-31 23:59:59`;

    await insertSyncedReceipt(adapter, 'r-recent', sixtyDaysAgo);
    await insertSyncedReceipt(adapter, 'r-old', dec31);

    await cleanupSyncedReceipts(adapter.asDatabase());

    const rows = await adapter.select<{ id: string }[]>(
      'SELECT id FROM offline_receipts ORDER BY id',
    );
    expect(rows.map((r) => r.id)).toEqual(['r-recent']);
  });

  it('does not delete pending receipts even if they are older than the fiscal-year boundary', async () => {
    const previousYear = new Date().getUTCFullYear() - 1;
    const dec31 = `${previousYear}-12-31 23:59:59`;

    // Insert a PENDING receipt with an old created_at — must not be deleted.
    const r = baseReceipt();
    await adapter.execute(
      `INSERT INTO offline_receipts (
        id, idempotency_key, receipt_number, terminal_id, terminal_code,
        operator_id, operator_name, lines, subtotal, tax_amount, discount_amount,
        total, currency, fiscal_hash, previous_hash, hash_sequence,
        transaction_discount_amount, transaction_discount_reason,
        tendered_amount, change_due, payment_method_id, payment_repository_id,
        status, payments_json, consumption_mode, table_id,
        created_at, synced_at, retry_count
      ) VALUES (
        $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16,
        $17, $18, $19, $20, $21, $22, $23, $24, $25, $26, $27, $28, $29
      )`,
      [
        'r-pending-old', 'key-r-pending-old', r.receipt_number, r.terminal_id, r.terminal_code,
        r.operator_id, r.operator_name, r.lines, r.subtotal, r.tax_amount,
        r.discount_amount, r.total, r.currency, r.fiscal_hash, r.previous_hash,
        r.hash_sequence, r.transaction_discount_amount, r.transaction_discount_reason,
        r.tendered_amount, r.change_due, r.payment_method_id, r.payment_repository_id,
        'pending', r.payments_json, r.consumption_mode, r.table_id,
        dec31, null, 0,
      ],
    );

    await cleanupSyncedReceipts(adapter.asDatabase());

    const rows = await adapter.select<{ id: string }[]>(
      'SELECT id FROM offline_receipts WHERE id = $1',
      ['r-pending-old'],
    );
    expect(rows).toHaveLength(1);
  });
});
