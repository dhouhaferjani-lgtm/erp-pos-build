/**
 * Task 12 — grid stock join assembler.
 *
 * `buildGridLocationStock` batch-reads `location_stock` ONCE for the loaded
 * catalog and produces the per-tile display map:
 *   - exempt products (non-product sellables, is_physical === false) → null
 *     (no stock chrome);
 *   - stock-managed products → the product-grain ('' variant) row's
 *     available/incoming columns;
 *   - missing row → zeros (matches the availability selector's
 *     "missing row ⇒ 0" rule).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import type Database from '@tauri-apps/plugin-sql';
import { makeProduct } from '@/test/helpers';
import type { LocationStockRow } from '@/lib/db/repositories/locationStockRepository';

vi.mock('@/lib/db/repositories/locationStockRepository', () => ({
  getStockForProducts: vi.fn(),
}));

import { getStockForProducts } from '@/lib/db/repositories/locationStockRepository';
import { buildGridLocationStock } from '../gridStock';

const db = {} as Database;

function makeRow(overrides: Partial<LocationStockRow> = {}): LocationStockRow {
  return {
    product_id: 'p1',
    variant_id: '',
    quantity: '10.0000',
    reserved: '0',
    available: '10.0000',
    incoming_transfer: '0',
    incoming_po: '0',
    updated_at: null,
    ...overrides,
  };
}

describe('buildGridLocationStock', () => {
  beforeEach(() => {
    vi.mocked(getStockForProducts).mockReset();
    vi.mocked(getStockForProducts).mockResolvedValue([]);
  });

  it('issues exactly ONE batched read with the bare ids of stock-managed products', async () => {
    const products = [
      makeProduct({ id: 'p1' }),
      // C2 Menu-style composite grid id with a bare sellable id underneath.
      makeProduct({ id: 'p2_cat9', sellable_id: 'p2' }),
      // Exempt: service product — must NOT be part of the batch.
      makeProduct({ id: 'p3', is_physical: false }),
      // Exempt: composite sellable — must NOT be part of the batch.
      makeProduct({ id: 'p4', sellableType: 'composite_item' }),
    ];

    await buildGridLocationStock(db, products);

    expect(getStockForProducts).toHaveBeenCalledTimes(1);
    expect(getStockForProducts).toHaveBeenCalledWith(db, ['p1', 'p2']);
  });

  it('keys the map by grid id and picks the product-grain ("" variant) row', async () => {
    vi.mocked(getStockForProducts).mockResolvedValue([
      makeRow({ product_id: 'p1', variant_id: 'v1', available: '99.0000' }),
      makeRow({
        product_id: 'p1',
        variant_id: '',
        available: '7.0000',
        incoming_transfer: '2.0000',
        incoming_po: '1.5000',
      }),
    ]);

    const map = await buildGridLocationStock(db, [makeProduct({ id: 'p1' })]);

    expect(map['p1']).toEqual({
      available: '7.0000',
      incoming_transfer: '2.0000',
      incoming_po: '1.5000',
    });
  });

  it('maps composite grid ids onto their bare sellable rows', async () => {
    vi.mocked(getStockForProducts).mockResolvedValue([
      makeRow({ product_id: 'p2', variant_id: '', available: '3.0000' }),
    ]);

    const map = await buildGridLocationStock(db, [
      makeProduct({ id: 'p2_cat9', sellable_id: 'p2' }),
    ]);

    expect(map['p2_cat9']).toEqual({
      available: '3.0000',
      incoming_transfer: '0',
      incoming_po: '0',
    });
  });

  it('marks exempt products null and never reads the DB when ALL are exempt', async () => {
    const map = await buildGridLocationStock(db, [
      makeProduct({ id: 'svc', is_physical: false }),
      makeProduct({ id: 'combo', sellableType: 'composite_item' }),
    ]);

    expect(map['svc']).toBeNull();
    expect(map['combo']).toBeNull();
    expect(getStockForProducts).not.toHaveBeenCalled();
  });

  it('defaults a stock-managed product with no local row to zeros (missing row ⇒ 0)', async () => {
    vi.mocked(getStockForProducts).mockResolvedValue([]);

    const map = await buildGridLocationStock(db, [makeProduct({ id: 'p1' })]);

    expect(map['p1']).toEqual({
      available: '0',
      incoming_transfer: '0',
      incoming_po: '0',
    });
  });
});
