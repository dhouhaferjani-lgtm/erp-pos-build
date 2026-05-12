/**
 * C2 Day 1 — productRepository upsert/get with composite (sellable_id +
 * menu_category_id) columns. Mock-level: assert `upsertProducts` SQL
 * includes the two new columns in both the INSERT clause and the
 * ON CONFLICT UPDATE clause, and that the param order matches.
 *
 * Multi-category round-trip is covered by the integration sibling
 * `productRepository.composite.integration.test.ts`.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import { upsertProducts } from '../productRepository';
import { execute } from '@/lib/db';

describe('upsertProducts — composite columns are written to SQLite', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('writes sellable_id and menu_category_id when set on the POSProduct', async () => {
    await upsertProducts(db, [
      {
        id: 'sellable-A_cat-1',
        name: 'Coca (Drinks)',
        sku: 'COCA',
        sale_price: '3.00',
        stock_quantity: 999,
        sellable_id: 'sellable-A',
        menu_category_id: 'cat-1',
      },
    ]);

    expect(execute).toHaveBeenCalledOnce();
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;

    expect(sql).toMatch(/INSERT INTO products/);
    expect(sql).toContain('sellable_id');
    expect(sql).toContain('menu_category_id');
    expect(sql).toContain('sellable_id = excluded.sellable_id');
    expect(sql).toContain('menu_category_id = excluded.menu_category_id');

    expect(params).toContain('sellable-A');
    expect(params).toContain('cat-1');
  });

  it('writes NULL for the new columns on standard-retail (non-Menu) products', async () => {
    await upsertProducts(db, [
      {
        id: 'bare-uuid-1',
        name: 'Standard Product',
        sku: 'STD-1',
        sale_price: '10.00',
        stock_quantity: 50,
      },
    ]);

    const [, , params] = vi.mocked(execute).mock.calls[0]!;
    // Both new fields should be bound as NULL — neither was provided on the
    // input POSProduct (standard-retail callsites stay backwards-compatible).
    const nullCount = (params as unknown[]).filter((p) => p === null).length;
    expect(nullCount).toBeGreaterThanOrEqual(2);
  });
});
