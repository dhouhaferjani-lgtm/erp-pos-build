import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { SqliteTestAdapter } from './helpers/sqliteTestAdapter';
import { applyAllMigrations } from './helpers/migrationTestHelpers';
import { insertOfflineReceipt, type OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

/**
 * B2 (Lane B, 2026-07-31) — real-SQLite round-trip for the v3 cash-rounding
 * mirror columns on `offline_receipts`.
 *
 * `offlineReceiptRepository.insert.test.ts`
 * (`src/lib/db/repositories/__tests__/`) mocks `@/lib/db`'s `execute`
 * entirely (`vi.fn().mockResolvedValue({ rowsAffected: 1 })`), so it never
 * actually exercises the 32-placeholder positional `INSERT` against a real
 * schema — a placeholder that silently shifted (e.g. `cash_rounding_
 * denomination` landing in `tolerance_shortfall`'s column, or either
 * landing in `canonical_bytes`) would pass every existing test and every
 * PHPStan/TS type check (all three columns are `string | null`) while
 * quarantining every rounded receipt server-side.
 *
 * This test inserts through a REAL `better-sqlite3` connection running the
 * REAL migrations (no mocks on the insert path — matches the write
 * manifest), reads the row back with a raw `SELECT *`, and asserts the
 * THREE rounding columns hold three DISTINCT, recognizable non-null values
 * in exactly the right places. Every other column is also given a unique,
 * recognizable value, so a placeholder shifted by even one position
 * corrupts an adjacent, independently-asserted column and fails loud here
 * — not just the three columns this task names.
 */

const RECEIPT_ID = 'b2-receipt-1';

function makeReceipt(): Omit<
  OfflineReceipt,
  'created_at' | 'synced_at' | 'sync_error' | 'retry_count' | 'server_receipt_id'
> {
  return {
    id: RECEIPT_ID,
    idempotency_key: 'b2-idem-1',
    receipt_number: 'MAIN-T900-2026-00000042',
    terminal_id: 'b2-terminal-1',
    terminal_code: 'T900',
    operator_id: 'b2-operator-1',
    operator_name: 'Rounding Tester',
    lines: '[{"name":"Widget"}]',
    subtotal: '9.997',
    tax_amount: '0.000',
    discount_amount: '0.001',
    total: '10.000',
    currency: 'TND',
    fiscal_hash: 'b2-fiscal-hash',
    previous_hash: 'b2-previous-hash',
    hash_sequence: 42,
    transaction_discount_amount: '0.002',
    transaction_discount_reason: 'b2-discount-reason',
    tendered_amount: '10.000',
    change_due: '0.000',
    payment_method_id: 'b2-payment-method',
    payment_repository_id: 'b2-payment-repository',
    status: 'pending',
    payments_json: '[{"payment_method_id":"b2-payment-method","amount":"10.000"}]',
    consumption_mode: 'b2-consumption-mode',
    table_id: 'b2-table-id',
    // The three rounding mirrors under test — three DISTINCT non-null
    // values, none equal to each other or to any other column's value
    // above, so a swapped or off-by-one placeholder is unmistakable.
    cash_rounding_adjustment: '0.003',
    cash_rounding_denomination: '0.050',
    tolerance_shortfall: '0.020',
    canonical_bytes: '{"envelope":"b2-canonical-bytes"}',
    fiscal_schema_version: 3,
    is_training: 0,
  };
}

interface RawRow {
  id: string;
  idempotency_key: string;
  receipt_number: string;
  terminal_id: string;
  terminal_code: string;
  operator_id: string;
  operator_name: string;
  lines: string;
  subtotal: string;
  tax_amount: string;
  discount_amount: string;
  total: string;
  currency: string;
  fiscal_hash: string;
  previous_hash: string;
  hash_sequence: number;
  transaction_discount_amount: string | null;
  transaction_discount_reason: string | null;
  tendered_amount: string | null;
  change_due: string | null;
  payment_method_id: string;
  payment_repository_id: string;
  status: string;
  payments_json: string;
  consumption_mode: string | null;
  table_id: string | null;
  cash_rounding_adjustment: string | null;
  cash_rounding_denomination: string | null;
  tolerance_shortfall: string | null;
  canonical_bytes: string | null;
  fiscal_schema_version: number;
  is_training: number;
}

describe('insertOfflineReceipt — real SQLite round-trip for v3 cash-rounding columns (B2)', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('lands three distinct non-null rounding values in the right columns, with every other column intact', async () => {
    await insertOfflineReceipt(adapter.asDatabase(), makeReceipt());

    const rows = await adapter.select<RawRow[]>(
      'SELECT * FROM offline_receipts WHERE id = $1',
      [RECEIPT_ID],
    );
    expect(rows).toHaveLength(1);
    const row = rows[0]!;

    // The three rounding mirrors, in the RIGHT columns.
    expect(row.cash_rounding_adjustment).toBe('0.003');
    expect(row.cash_rounding_denomination).toBe('0.050');
    expect(row.tolerance_shortfall).toBe('0.020');

    // Sanity: they really are three DISTINCT values, not one value that
    // happens to satisfy three separate `toBe` calls via coincidence.
    const roundingValues = [
      row.cash_rounding_adjustment,
      row.cash_rounding_denomination,
      row.tolerance_shortfall,
    ];
    expect(new Set(roundingValues).size).toBe(3);

    // Every OTHER column landed in ITS right place too — a placeholder
    // shifted by one position would corrupt one of these as well.
    expect(row.id).toBe(RECEIPT_ID);
    expect(row.idempotency_key).toBe('b2-idem-1');
    expect(row.receipt_number).toBe('MAIN-T900-2026-00000042');
    expect(row.terminal_id).toBe('b2-terminal-1');
    expect(row.terminal_code).toBe('T900');
    expect(row.operator_id).toBe('b2-operator-1');
    expect(row.operator_name).toBe('Rounding Tester');
    expect(row.lines).toBe('[{"name":"Widget"}]');
    expect(row.subtotal).toBe('9.997');
    expect(row.tax_amount).toBe('0.000');
    expect(row.discount_amount).toBe('0.001');
    expect(row.total).toBe('10.000');
    expect(row.currency).toBe('TND');
    expect(row.fiscal_hash).toBe('b2-fiscal-hash');
    expect(row.previous_hash).toBe('b2-previous-hash');
    expect(row.hash_sequence).toBe(42);
    expect(row.transaction_discount_amount).toBe('0.002');
    expect(row.transaction_discount_reason).toBe('b2-discount-reason');
    expect(row.tendered_amount).toBe('10.000');
    expect(row.change_due).toBe('0.000');
    expect(row.payment_method_id).toBe('b2-payment-method');
    expect(row.payment_repository_id).toBe('b2-payment-repository');
    expect(row.status).toBe('pending');
    expect(row.payments_json).toBe('[{"payment_method_id":"b2-payment-method","amount":"10.000"}]');
    expect(row.consumption_mode).toBe('b2-consumption-mode');
    expect(row.table_id).toBe('b2-table-id');
    expect(row.canonical_bytes).toBe('{"envelope":"b2-canonical-bytes"}');
    expect(row.fiscal_schema_version).toBe(3);
    expect(row.is_training).toBe(0);
  });

  it('persists canonical NULL (not the string "null") when no rounding applied', async () => {
    await insertOfflineReceipt(adapter.asDatabase(), {
      ...makeReceipt(),
      id: 'b2-receipt-2',
      idempotency_key: 'b2-idem-2',
      cash_rounding_adjustment: null,
      cash_rounding_denomination: null,
      tolerance_shortfall: null,
    });

    const rows = await adapter.select<RawRow[]>(
      'SELECT * FROM offline_receipts WHERE id = $1',
      ['b2-receipt-2'],
    );
    expect(rows).toHaveLength(1);
    const row = rows[0]!;

    expect(row.cash_rounding_adjustment).toBeNull();
    expect(row.cash_rounding_denomination).toBeNull();
    expect(row.tolerance_shortfall).toBeNull();
  });
});
