/**
 * Task F5 — crossLocationStockRepository tests.
 *
 * Uses the real SQLite engine via SqliteTestAdapter (better-sqlite3 in-memory).
 * All migrations including v52 (product_stock_distribution_cache) are applied
 * before each test so the table exists.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { SqliteTestAdapter } from '@/lib/db/__tests__/helpers/sqliteTestAdapter';
import { applyAllMigrations } from '@/lib/db/__tests__/helpers/migrationTestHelpers';
import {
  upsertDistribution,
  getDistribution,
  getAllForProduct,
  deleteDistributionForProducts,
} from '../crossLocationStockRepository';

describe('crossLocationStockRepository', () => {
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

  it('upserts and reads by product+variant', async () => {
    await upsertDistribution(db, 'p1', 'v1', 'M / Blue', '{"x":1}', '2026-06-14T10:00:00Z');
    const row = await getDistribution(db, 'p1', 'v1');
    expect(row?.payload).toBe('{"x":1}');
    expect(row?.variant_label).toBe('M / Blue');
  });

  it('product-grain uses empty variant key', async () => {
    await upsertDistribution(db, 'p2', null, null, '{"y":2}', '2026-06-14T10:00:00Z');
    expect((await getDistribution(db, 'p2', null))?.payload).toBe('{"y":2}');
  });

  it('lists all cached variants for a product', async () => {
    await upsertDistribution(db, 'p3', 'a', 'A', '{}', 't');
    await upsertDistribution(db, 'p3', 'b', 'B', '{}', 't');
    expect((await getAllForProduct(db, 'p3')).length).toBe(2);
  });

  it('deletes by product ids', async () => {
    await upsertDistribution(db, 'p4', '', null, '{}', 't');
    await deleteDistributionForProducts(db, ['p4']);
    expect(await getDistribution(db, 'p4', null)).toBeNull();
  });

  it('upsert overwrites payload on conflict', async () => {
    await upsertDistribution(db, 'p5', 'v1', 'S / Red', '{"old":true}', '2026-06-14T10:00:00Z');
    await upsertDistribution(db, 'p5', 'v1', 'S / Red updated', '{"new":true}', '2026-06-14T11:00:00Z');
    const row = await getDistribution(db, 'p5', 'v1');
    expect(row?.payload).toBe('{"new":true}');
    expect(row?.variant_label).toBe('S / Red updated');
    expect(row?.fetched_at).toBe('2026-06-14T11:00:00Z');
  });

  it('deleteDistributionForProducts is a no-op on empty array', async () => {
    await upsertDistribution(db, 'p6', '', null, '{"z":0}', 't');
    await deleteDistributionForProducts(db, []);
    expect(await getDistribution(db, 'p6', null)).not.toBeNull();
  });

  it('getAllForProduct returns empty array when no cache exists', async () => {
    const rows = await getAllForProduct(db, 'nonexistent');
    expect(rows).toEqual([]);
  });
});
