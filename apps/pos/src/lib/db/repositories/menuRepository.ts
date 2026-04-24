import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';
import type { ModifierGroup } from '@/types/modifier';

const DELETE_BATCH_SIZE = 200;

export interface CachedMenuCategory {
  id: string;
  name: string;
  position: number;
  items: CachedMenuCategoryItem[];
}

export interface CachedMenuCategoryItem {
  id: string;
  menu_category_id: string;
  sellable_id: string;
  sellable_type: string;
  name: string;
  code: string;
  barcode: string | null;
  base_price: string;
  effective_price: string;
  image_url: string | null;
  tax_rate: string | null;
  display_order: number;
  is_available: boolean;
  modifier_groups: ModifierGroup[] | null;
}

interface MenuCategoryRow {
  id: string;
  name: string;
  position: number;
  updated_at: string;
}

interface MenuCategoryItemRow {
  id: string;
  menu_category_id: string;
  sellable_id: string;
  sellable_type: string;
  name: string;
  code: string;
  barcode: string | null;
  base_price: string;
  effective_price: string;
  image_url: string | null;
  tax_rate: string | null;
  display_order: number;
  is_available: number;
  modifier_groups: string | null;
  updated_at: string;
}

function itemRowToItem(row: MenuCategoryItemRow): CachedMenuCategoryItem {
  return {
    id: row.id,
    menu_category_id: row.menu_category_id,
    sellable_id: row.sellable_id,
    sellable_type: row.sellable_type,
    name: row.name,
    code: row.code,
    barcode: row.barcode,
    base_price: row.base_price,
    effective_price: row.effective_price,
    image_url: row.image_url,
    tax_rate: row.tax_rate,
    display_order: row.display_order,
    is_available: row.is_available === 1,
    modifier_groups: row.modifier_groups ? (JSON.parse(row.modifier_groups) as ModifierGroup[]) : null,
  };
}

export interface MenuCategoryUpsertInput {
  id: string;
  name: string;
  position: number;
  updated_at: string;
}

export interface MenuCategoryItemUpsertInput extends Omit<CachedMenuCategoryItem, 'modifier_groups'> {
  modifier_groups: ModifierGroup[] | null;
  updated_at: string;
}

export async function upsertMenuCategories(
  db: Database,
  categories: MenuCategoryUpsertInput[],
): Promise<void> {
  if (categories.length === 0) return;
  for (const c of categories) {
    await execute(
      db,
      `INSERT INTO menu_categories (id, name, position, updated_at, synced_at)
       VALUES ($1, $2, $3, $4, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         name = excluded.name, position = excluded.position,
         updated_at = excluded.updated_at, synced_at = datetime('now')`,
      [c.id, c.name, c.position, c.updated_at],
    );
  }
}

export async function upsertMenuCategoryItems(
  db: Database,
  items: MenuCategoryItemUpsertInput[],
): Promise<void> {
  if (items.length === 0) return;
  for (const i of items) {
    await execute(
      db,
      `INSERT INTO menu_category_items (id, menu_category_id, sellable_id, sellable_type, name, code, barcode, base_price, effective_price, image_url, tax_rate, display_order, is_available, modifier_groups, updated_at, synced_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, datetime('now'))
       ON CONFLICT(id) DO UPDATE SET
         menu_category_id = excluded.menu_category_id,
         sellable_id = excluded.sellable_id, sellable_type = excluded.sellable_type,
         name = excluded.name, code = excluded.code, barcode = excluded.barcode,
         base_price = excluded.base_price, effective_price = excluded.effective_price,
         image_url = excluded.image_url, tax_rate = excluded.tax_rate,
         display_order = excluded.display_order, is_available = excluded.is_available,
         modifier_groups = excluded.modifier_groups, updated_at = excluded.updated_at,
         synced_at = datetime('now')`,
      [
        i.id, i.menu_category_id, i.sellable_id, i.sellable_type,
        i.name, i.code, i.barcode, i.base_price, i.effective_price,
        i.image_url, i.tax_rate, i.display_order, i.is_available ? 1 : 0,
        i.modifier_groups ? JSON.stringify(i.modifier_groups) : null,
        i.updated_at,
      ],
    );
  }
}

export async function getActiveMenu(db: Database): Promise<{ categories: CachedMenuCategory[] }> {
  const categoryRows = await queryAll<MenuCategoryRow>(
    db,
    `SELECT * FROM menu_categories ORDER BY position ASC`,
  );
  if (categoryRows.length === 0) return { categories: [] };

  const itemRows = await queryAll<MenuCategoryItemRow>(
    db,
    `SELECT * FROM menu_category_items ORDER BY menu_category_id, display_order ASC`,
  );

  const itemsByCategory = new Map<string, CachedMenuCategoryItem[]>();
  for (const row of itemRows) {
    const arr = itemsByCategory.get(row.menu_category_id) ?? [];
    arr.push(itemRowToItem(row));
    itemsByCategory.set(row.menu_category_id, arr);
  }

  return {
    categories: categoryRows.map((c) => ({
      id: c.id,
      name: c.name,
      position: c.position,
      items: itemsByCategory.get(c.id) ?? [],
    })),
  };
}

export async function deleteMenuCategories(db: Database, ids: string[]): Promise<void> {
  if (ids.length === 0) return;
  for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
    const batch = ids.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = batch.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM menu_categories WHERE id IN (${placeholders})`, batch);
  }
}

export async function deleteMenuCategoryItems(db: Database, ids: string[]): Promise<void> {
  if (ids.length === 0) return;
  for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
    const batch = ids.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = batch.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM menu_category_items WHERE id IN (${placeholders})`, batch);
  }
}
