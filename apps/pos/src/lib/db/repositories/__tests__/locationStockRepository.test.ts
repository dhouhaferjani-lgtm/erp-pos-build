/**
 * Task 8 — location_stock repository tests.
 *
 * Uses the real SQLite engine via SqliteTestAdapter (better-sqlite3 in-memory).
 * All migrations up to and including v50 (location_stock) are applied before
 * each test so the table exists.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import type { ServerStockRow, ServerIncomingRow, LocationStockRow } from '../locationStockRepository';
import {
  upsertStockRows,
  replaceAllStock,
  replaceIncoming,
  getStockFor,
  getStockForProducts,
  deleteForProducts,
} from '../locationStockRepository';

/** Helper — build a minimal ServerStockRow. */
function stockRow(overrides: Partial<ServerStockRow> & { product_id: string }): ServerStockRow {
  return {
    variant_id: null,
    quantity: '10.0000',
    reserved: '2.0000',
    available: '8.0000',
    updated_at: '2026-06-12T10:00:00.000Z',
    ...overrides,
  };
}

/** Helper — build a minimal ServerIncomingRow. */
function incomingRow(overrides: Partial<ServerIncomingRow> & { product_id: string }): ServerIncomingRow {
  return {
    variant_id: null,
    incoming_transfer: '5.0000',
    incoming_po: '3.0000',
    ...overrides,
  };
}

describe('locationStockRepository', () => {
  let adapter: SqliteTestAdapter;
  let db: ReturnType<SqliteTestAdapter['asDatabase']>;

  beforeEach(async () => {
    adapter = new SqliteTestAdapter();
    db = adapter.asDatabase();
    await applyAllMigrations(adapter);
  });

  afterEach(() => {
    adapter.close();
  });

  // ──────────────────────────────────────────────────────────────
  // 1. upsertStockRows
  // ──────────────────────────────────────────────────────────────
  describe('upsertStockRows', () => {
    it('inserts a new row (null variant_id maps to empty string key)', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1' })]);

      const row = await getStockFor(db, 'p1', null);
      expect(row).not.toBeNull();
      expect(row!.product_id).toBe('p1');
      expect(row!.variant_id).toBe('');
      expect(row!.quantity).toBe('10.0000');
      expect(row!.available).toBe('8.0000');
    });

    it('inserts a variant row (non-null variant_id preserved)', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1', variant_id: 'v1' })]);

      const row = await getStockFor(db, 'p1', 'v1');
      expect(row).not.toBeNull();
      expect(row!.variant_id).toBe('v1');
    });

    it('updates quantity/reserved/available on conflict', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1', quantity: '5.0000', reserved: '1.0000', available: '4.0000' })]);
      await upsertStockRows(db, [stockRow({ product_id: 'p1', quantity: '20.0000', reserved: '3.0000', available: '17.0000' })]);

      const row = await getStockFor(db, 'p1', null);
      expect(row!.quantity).toBe('20.0000');
      expect(row!.reserved).toBe('3.0000');
      expect(row!.available).toBe('17.0000');
    });

    it('preserves incoming columns across a stock upsert', async () => {
      // Seed incoming data directly
      await replaceIncoming(db, [incomingRow({ product_id: 'p1', incoming_transfer: '5.0000', incoming_po: '3.0000' })]);
      // Now upsert stock — incoming columns must survive
      await upsertStockRows(db, [stockRow({ product_id: 'p1', quantity: '10.0000', reserved: '0.0000', available: '10.0000' })]);

      const row = await getStockFor(db, 'p1', null);
      expect(row!.incoming_transfer).toBe('5.0000');
      expect(row!.incoming_po).toBe('3.0000');
    });
  });

  // ──────────────────────────────────────────────────────────────
  // 2. replaceAllStock
  // ──────────────────────────────────────────────────────────────
  describe('replaceAllStock', () => {
    it('deletes rows absent from the new set', async () => {
      await upsertStockRows(db, [
        stockRow({ product_id: 'p1' }),
        stockRow({ product_id: 'p2' }),
      ]);

      // Full replace — p2 is no longer in the server set
      await replaceAllStock(db, [stockRow({ product_id: 'p1', quantity: '99.0000', reserved: '0.0000', available: '99.0000' })]);

      expect(await getStockFor(db, 'p2', null)).toBeNull();
    });

    it('updates surviving rows with new stock values', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1', quantity: '5.0000', reserved: '0.0000', available: '5.0000' })]);

      await replaceAllStock(db, [stockRow({ product_id: 'p1', quantity: '99.0000', reserved: '1.0000', available: '98.0000' })]);

      const row = await getStockFor(db, 'p1', null);
      expect(row!.quantity).toBe('99.0000');
    });

    it('preserves incoming columns on surviving rows', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1' })]);
      await replaceIncoming(db, [incomingRow({ product_id: 'p1', incoming_transfer: '7.0000', incoming_po: '2.0000' })]);

      await replaceAllStock(db, [stockRow({ product_id: 'p1', quantity: '15.0000', reserved: '0.0000', available: '15.0000' })]);

      const row = await getStockFor(db, 'p1', null);
      expect(row!.incoming_transfer).toBe('7.0000');
      expect(row!.incoming_po).toBe('2.0000');
    });

    it('handles variant and product-grain rows independently', async () => {
      // p1 product-grain + p1/v1 variant
      await upsertStockRows(db, [
        stockRow({ product_id: 'p1', variant_id: null }),
        stockRow({ product_id: 'p1', variant_id: 'v1' }),
      ]);

      // Full replace keeps only the variant row
      await replaceAllStock(db, [stockRow({ product_id: 'p1', variant_id: 'v1', quantity: '3.0000', reserved: '0.0000', available: '3.0000' })]);

      expect(await getStockFor(db, 'p1', null)).toBeNull();
      const vRow = await getStockFor(db, 'p1', 'v1');
      expect(vRow!.quantity).toBe('3.0000');
    });
  });

  // ──────────────────────────────────────────────────────────────
  // 3. replaceIncoming
  // ──────────────────────────────────────────────────────────────
  describe('replaceIncoming', () => {
    it('zeroes all existing incoming then applies new values', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1' }), stockRow({ product_id: 'p2' })]);
      await replaceIncoming(db, [incomingRow({ product_id: 'p1', incoming_transfer: '4.0000', incoming_po: '1.0000' })]);
      await replaceIncoming(db, [incomingRow({ product_id: 'p2', incoming_transfer: '6.0000', incoming_po: '2.0000' })]);

      const p1 = await getStockFor(db, 'p1', null);
      const p2 = await getStockFor(db, 'p2', null);
      // p1 should now be zeroed (it was excluded from the second call)
      expect(p1!.incoming_transfer).toBe('0');
      expect(p1!.incoming_po).toBe('0');
      // p2 should have the new values
      expect(p2!.incoming_transfer).toBe('6.0000');
      expect(p2!.incoming_po).toBe('2.0000');
    });

    it('creates stock-less rows with zero quantity defaults', async () => {
      // No prior stock row for p3
      await replaceIncoming(db, [incomingRow({ product_id: 'p3', incoming_transfer: '5.0000', incoming_po: '0.0000' })]);

      const row = await getStockFor(db, 'p3', null);
      expect(row).not.toBeNull();
      expect(row!.quantity).toBe('0');
      expect(row!.reserved).toBe('0');
      expect(row!.available).toBe('0');
      expect(row!.incoming_transfer).toBe('5.0000');
    });

    it('a second call with a smaller set zeroes rows dropped from it', async () => {
      await replaceIncoming(db, [
        incomingRow({ product_id: 'p1', incoming_transfer: '5.0000', incoming_po: '1.0000' }),
        incomingRow({ product_id: 'p2', incoming_transfer: '3.0000', incoming_po: '0.0000' }),
      ]);
      // Second call only includes p1
      await replaceIncoming(db, [
        incomingRow({ product_id: 'p1', incoming_transfer: '2.0000', incoming_po: '0.0000' }),
      ]);

      const p2 = await getStockFor(db, 'p2', null);
      expect(p2!.incoming_transfer).toBe('0');
      expect(p2!.incoming_po).toBe('0');
      const p1 = await getStockFor(db, 'p1', null);
      expect(p1!.incoming_transfer).toBe('2.0000');
    });
  });

  // ──────────────────────────────────────────────────────────────
  // 4. getStockFor
  // ──────────────────────────────────────────────────────────────
  describe('getStockFor', () => {
    it('returns null for an unknown product', async () => {
      expect(await getStockFor(db, 'unknown', null)).toBeNull();
    });

    it('returns the row for product-grain key (variant_id null → empty string)', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1', quantity: '10.0000', reserved: '0.0000', available: '10.0000' })]);
      const row = await getStockFor(db, 'p1', null);
      expect(row).not.toBeNull();
      expect(row!.variant_id).toBe('');
    });

    it('returns the row for an explicit variant key', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1', variant_id: 'v42', quantity: '3.0000', reserved: '0.0000', available: '3.0000' })]);
      const row = await getStockFor(db, 'p1', 'v42');
      expect(row).not.toBeNull();
      expect(row!.variant_id).toBe('v42');
    });

    it('distinguishes between product-grain and variant rows', async () => {
      await upsertStockRows(db, [
        stockRow({ product_id: 'p1', variant_id: null, quantity: '10.0000', reserved: '0.0000', available: '10.0000' }),
        stockRow({ product_id: 'p1', variant_id: 'v1', quantity: '3.0000', reserved: '0.0000', available: '3.0000' }),
      ]);

      const grain = await getStockFor(db, 'p1', null);
      const variant = await getStockFor(db, 'p1', 'v1');

      expect(grain!.quantity).toBe('10.0000');
      expect(variant!.quantity).toBe('3.0000');
    });
  });

  // ──────────────────────────────────────────────────────────────
  // 5. getStockForProducts
  // ──────────────────────────────────────────────────────────────
  describe('getStockForProducts', () => {
    it('returns all rows for the given product ids', async () => {
      await upsertStockRows(db, [
        stockRow({ product_id: 'p1' }),
        stockRow({ product_id: 'p2' }),
        stockRow({ product_id: 'p3' }),
      ]);

      const rows = await getStockForProducts(db, ['p1', 'p3']);
      expect(rows).toHaveLength(2);
      const ids = rows.map((r: LocationStockRow) => r.product_id).sort();
      expect(ids).toEqual(['p1', 'p3']);
    });

    it('returns an empty array when product ids list is empty', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1' })]);
      const rows = await getStockForProducts(db, []);
      expect(rows).toHaveLength(0);
    });

    it('returns nothing for unknown product ids', async () => {
      const rows = await getStockForProducts(db, ['unknown']);
      expect(rows).toHaveLength(0);
    });

    it('returns both product-grain and variant rows for a product', async () => {
      await upsertStockRows(db, [
        stockRow({ product_id: 'p1', variant_id: null }),
        stockRow({ product_id: 'p1', variant_id: 'v1' }),
      ]);

      const rows = await getStockForProducts(db, ['p1']);
      expect(rows).toHaveLength(2);
    });
  });

  // ──────────────────────────────────────────────────────────────
  // 6. deleteForProducts (tombstone cascade)
  // ──────────────────────────────────────────────────────────────
  describe('deleteForProducts', () => {
    it('is a no-op when ids list is empty', async () => {
      await upsertStockRows(db, [stockRow({ product_id: 'p1' })]);
      await deleteForProducts(db, []);
      expect(await getStockFor(db, 'p1', null)).not.toBeNull();
    });

    it('removes all rows (grain + variants) for the given product ids', async () => {
      await upsertStockRows(db, [
        stockRow({ product_id: 'p1', variant_id: null }),
        stockRow({ product_id: 'p1', variant_id: 'v1' }),
        stockRow({ product_id: 'p2' }),
      ]);

      await deleteForProducts(db, ['p1']);

      expect(await getStockFor(db, 'p1', null)).toBeNull();
      expect(await getStockFor(db, 'p1', 'v1')).toBeNull();
      // p2 must survive
      expect(await getStockFor(db, 'p2', null)).not.toBeNull();
    });

    it('handles batches larger than the chunk size without error', async () => {
      // Insert 250 product rows to force chunking (DELETE_BATCH_SIZE = 200)
      const rows = Array.from({ length: 250 }, (_, i) =>
        stockRow({ product_id: `product-${i}` }),
      );
      await upsertStockRows(db, rows);

      const allIds = rows.map((r) => r.product_id);
      await deleteForProducts(db, allIds);

      const survivors = await getStockForProducts(db, allIds);
      expect(survivors).toHaveLength(0);
    });
  });
});
