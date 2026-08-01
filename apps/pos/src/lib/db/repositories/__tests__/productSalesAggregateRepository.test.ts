import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import { aggregateProductSales } from '../productSalesAggregateRepository';

const nodeSqliteAvailable = (() => {
  try {
    return Boolean(require('node:sqlite').DatabaseSync);
  } catch {
    return false;
  }
})();

const d = nodeSqliteAvailable ? describe : describe.skip;

async function insertReceipt(
  adapter: SqliteTestAdapter,
  args: {
    id: string;
    idempotencyKey: string;
    linesJson: string;
    createdAtSql?: string;
    receiptKind?: 'sale' | 'refund';
  },
): Promise<void> {
  const createdAt = args.createdAtSql ?? "datetime('now')";
  const receiptKind = args.receiptKind ?? 'sale';
  await adapter.execute(
    `INSERT INTO offline_receipts (
       id, idempotency_key, receipt_number, terminal_id, terminal_code,
       operator_id, operator_name, lines, subtotal, tax_amount, total,
       currency, fiscal_hash, previous_hash, hash_sequence,
       payment_method_id, payment_repository_id, status, created_at, receipt_kind
     ) VALUES ($1, $2, 'R-1', 't', 'tc', 'op', 'Op', $3, '0', '0', '0', 'EUR', 'h', 'p', 1, 'pm', 'pr', 'synced', ${createdAt}, $4)`,
    [args.id, args.idempotencyKey, args.linesJson, receiptKind],
  );
}

d('aggregateProductSales', () => {
  let adapter: SqliteTestAdapter;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  it('returns an empty Map when there are no receipts', async () => {
    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.size).toBe(0);
  });

  it('sums the quantity field across the receipt-line shape receiptService writes (product_id at top level)', async () => {
    await insertReceipt(adapter, {
      id: 'r1',
      idempotencyKey: 'k1',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: 3, unit_price: '1.000', line_total: '3.000' },
        { product_id: 'p2', name: 'B', sku: 'B', quantity: 1, unit_price: '1.000', line_total: '1.000' },
      ]),
    });
    await insertReceipt(adapter, {
      id: 'r2',
      idempotencyKey: 'k2',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: 2, unit_price: '1.000', line_total: '2.000' },
      ]),
    });

    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.get('p1')).toBe(5);
    expect(counts.get('p2')).toBe(1);
  });

  it('aggregates composite_item_id under the same key when product_id is absent', async () => {
    await insertReceipt(adapter, {
      id: 'r3',
      idempotencyKey: 'k3',
      linesJson: JSON.stringify([
        { composite_item_id: 'c1', name: 'Combo', sku: 'C', quantity: 4, unit_price: '5.000', line_total: '20.000' },
      ]),
    });
    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.get('c1')).toBe(4);
  });

  it('respects the sinceDays window', async () => {
    await insertReceipt(adapter, {
      id: 'r-old',
      idempotencyKey: 'k-old',
      linesJson: JSON.stringify([{ product_id: 'p1', name: 'A', sku: 'A', quantity: 100, unit_price: '1', line_total: '1' }]),
      createdAtSql: "datetime('now', '-60 days')",
    });
    await insertReceipt(adapter, {
      id: 'r-new',
      idempotencyKey: 'k-new',
      linesJson: JSON.stringify([{ product_id: 'p1', name: 'A', sku: 'A', quantity: 2, unit_price: '1', line_total: '1' }]),
      createdAtSql: "datetime('now', '-1 days')",
    });

    const counts30 = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts30.get('p1')).toBe(2);

    const counts90 = await aggregateProductSales(adapter, { sinceDays: 90 });
    expect(counts90.get('p1')).toBe(102);
  });

  it('skips malformed JSON without throwing', async () => {
    await insertReceipt(adapter, {
      id: 'r-bad',
      idempotencyKey: 'k-bad',
      linesJson: '{not-json',
    });
    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.size).toBe(0);
  });

  it('excludes voided receipts from the aggregation', async () => {
    await insertReceipt(adapter, {
      id: 'r-sold',
      idempotencyKey: 'k-sold',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: 7, unit_price: '1.000', line_total: '7.000' },
      ]),
    });
    await insertReceipt(adapter, {
      id: 'r-voided',
      idempotencyKey: 'k-voided',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: 99, unit_price: '1.000', line_total: '99.000' },
      ]),
    });
    // Mark the second receipt as voided after insertion.
    await adapter.execute(
      `UPDATE offline_receipts SET voided = 1 WHERE id = $1`,
      ['r-voided'],
    );

    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.get('p1')).toBe(7);
  });

  // v3-refund-chain-integration spec §7.3b — this repository needs NO
  // production code change for refunds: a refund row's `lines` JSON carries
  // NEGATIVE quantity (§7.2, mirrors the AVOIR print convention), and the
  // aggregation already sums the raw (non-abs) quantity field, so a refund's
  // negative units net out of the "frequently sold" count automatically.
  it('nets a refund row negative quantity against sale quantity for the same product (§7.3b)', async () => {
    await insertReceipt(adapter, {
      id: 'r-sale',
      idempotencyKey: 'k-sale',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: 5, unit_price: '1.000', line_total: '5.000' },
      ]),
      receiptKind: 'sale',
    });
    await insertReceipt(adapter, {
      id: 'r-refund',
      idempotencyKey: 'k-refund',
      linesJson: JSON.stringify([
        { product_id: 'p1', name: 'A', sku: 'A', quantity: -2, unit_price: '1.000', line_total: '-2.000' },
      ]),
      receiptKind: 'refund',
    });

    const counts = await aggregateProductSales(adapter, { sinceDays: 30 });
    expect(counts.get('p1')).toBe(3);
  });
});
