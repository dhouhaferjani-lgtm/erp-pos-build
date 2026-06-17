import type { POSProduct } from '@/types/product';

interface DiffResult {
  changed: boolean;
  products: POSProduct[];
}

const COMPARE_FIELDS: (keyof POSProduct)[] = [
  'name', 'sku', 'barcode', 'sale_price', 'stock_quantity',
  'category', 'image_url', 'tax_rate', 'sellableType', 'position',
  // C2 Day 2 (Codex PR #109 r3 P2) — the v30 migration backfills
  // `menu_category_id` / `sellable_id` onto rows that already exist
  // by id. Without comparing these here, `diffProducts` would return
  // the pre-migration cached row in `merged` because every visible
  // field matched, and the downstream `canonicalizeMenuCatalog` pass
  // would never see the freshly populated ids — leaving the
  // chooser-row category label and ID-based dedupe disabled for the
  // refreshed product until another visible field changes.
  'menu_category_id', 'sellable_id',
  // HIGH-2 fix: include has_variants so diffProducts detects when the
  // server-authoritative flag flips on an existing product. Without
  // this, a variant product already in the in-memory catalog would
  // never be updated (productEquals returns true) and tile-tap/scan
  // would read the stale has_variants=false value, bypassing the
  // VariantPickerModal entirely.
  'has_variants',
  // v51 migration: include is_physical so a product flipping
  // is_physical true↔false is re-projected into the in-memory catalog;
  // without this, stock enforcement would read the stale flag value.
  'is_physical',
];

function productEquals(a: POSProduct, b: POSProduct): boolean {
  const scalarMatch = COMPARE_FIELDS.every((key) => a[key] === b[key]);
  if (!scalarMatch) return false;
  if (a.modifier_groups || b.modifier_groups) {
    return JSON.stringify(a.modifier_groups) === JSON.stringify(b.modifier_groups);
  }
  return true;
}

export function diffProducts(current: POSProduct[], fetched: POSProduct[]): DiffResult {
  const currentMap = new Map(current.map((p) => [p.id, p]));
  let changed = current.length !== fetched.length;

  const merged = fetched.map((fetchedProduct) => {
    const existing = currentMap.get(fetchedProduct.id);
    if (existing && productEquals(existing, fetchedProduct)) {
      return existing; // preserve reference
    }
    changed = true;
    return fetchedProduct;
  });

  return { changed, products: merged };
}
