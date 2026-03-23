import type { POSProduct } from '@/types/product';

interface DiffResult {
  changed: boolean;
  products: POSProduct[];
}

const COMPARE_FIELDS: (keyof POSProduct)[] = [
  'name', 'sku', 'barcode', 'sale_price', 'stock_quantity',
  'category', 'image_url', 'tax_rate', 'sellableType', 'position',
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
