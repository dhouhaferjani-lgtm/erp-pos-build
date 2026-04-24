import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
}));

import {
  upsertMenuCategories,
  upsertMenuCategoryItems,
  getActiveMenu,
  deleteMenuCategories,
  deleteMenuCategoryItems,
} from '../menuRepository';
import { queryAll, execute } from '@/lib/db';

describe('menuRepository', () => {
  const db = {} as import('@tauri-apps/plugin-sql').default;

  beforeEach(() => { vi.clearAllMocks(); });

  it('upsertMenuCategories no-op on empty', async () => {
    await upsertMenuCategories(db, []);
    expect(execute).not.toHaveBeenCalled();
  });

  it('upsertMenuCategoryItems writes serialized modifier_groups', async () => {
    await upsertMenuCategoryItems(db, [{
      id: 'i1',
      menu_category_id: 'c1',
      sellable_id: 'p1',
      sellable_type: 'product',
      name: 'Latte',
      code: 'LAT',
      barcode: null,
      base_price: '3.50',
      effective_price: '3.50',
      image_url: null,
      tax_rate: '7.00',
      display_order: 0,
      is_available: true,
      modifier_groups: [{ id: 'mg1', name: 'Size', selection_type: 'single' as const, min_selections: 0, max_selections: 1, is_required: false, position: 0, modifiers: [] }],
      updated_at: '2026-04-23T00:00:00Z',
    }]);
    const [, sql, params] = vi.mocked(execute).mock.calls[0]!;
    expect(sql).toMatch(/INSERT INTO menu_category_items/);
    // Verify modifier_groups was serialized to JSON (any valid JSON string containing mg1)
    const serialized = (params as unknown[]).find((p) => typeof p === 'string' && (p as string).includes('"mg1"'));
    expect(serialized).toBeDefined();
    const parsed = JSON.parse(serialized as string) as unknown[];
    expect(parsed).toHaveLength(1);
  });

  it('getActiveMenu assembles categories with their items', async () => {
    vi.mocked(queryAll).mockResolvedValueOnce([
      { id: 'c1', name: 'Drinks', position: 0, updated_at: 'x' },
    ]);
    vi.mocked(queryAll).mockResolvedValueOnce([
      { id: 'i1', menu_category_id: 'c1', sellable_id: 'p1', sellable_type: 'product', name: 'Latte', code: 'LAT', barcode: null, base_price: '3.50', effective_price: '3.50', image_url: null, tax_rate: '7.00', display_order: 0, is_available: 1, modifier_groups: null, updated_at: 'x' },
    ]);

    const menu = await getActiveMenu(db);

    expect(menu.categories).toHaveLength(1);
    expect(menu.categories[0]?.items).toHaveLength(1);
    expect(menu.categories[0]?.items[0]?.is_available).toBe(true);
  });

  it('deleteMenuCategoryItems batches > 200', async () => {
    const ids = Array.from({ length: 250 }, (_, i) => `i${i}`);
    await deleteMenuCategoryItems(db, ids);
    expect(execute).toHaveBeenCalledTimes(2);
    vi.clearAllMocks();
    await deleteMenuCategories(db, ids);
    expect(execute).toHaveBeenCalledTimes(2);
  });
});
