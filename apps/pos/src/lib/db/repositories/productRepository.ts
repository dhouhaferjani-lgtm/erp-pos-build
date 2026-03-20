import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';
import type { POSProduct } from '@/types/product';

interface ProductRow {
  id: string;
  name: string;
  sku: string;
  barcode: string | null;
  sale_price: string | null;
  stock_quantity: number;
  category: string | null;
  image_url: string | null;
  tax_rate: string | null;
  sellable_type: string | null;
}

function rowToProduct(row: ProductRow): POSProduct {
  return {
    id: row.id,
    name: row.name,
    sku: row.sku,
    barcode: row.barcode,
    sale_price: row.sale_price,
    stock_quantity: row.stock_quantity,
    category: row.category ?? undefined,
    image_url: row.image_url ?? undefined,
    tax_rate: row.tax_rate ?? undefined,
    sellableType: (row.sellable_type as POSProduct['sellableType']) ?? undefined,
  };
}

export async function getAllProducts(db: Database): Promise<POSProduct[]> {
  const rows = await queryAll<ProductRow>(db, 'SELECT * FROM products ORDER BY name');
  return rows.map(rowToProduct);
}

export async function getProductByBarcode(db: Database, barcode: string): Promise<POSProduct | null> {
  const row = await queryOne<ProductRow>(
    db,
    'SELECT * FROM products WHERE barcode = $1 OR sku = $1',
    [barcode]
  );
  return row ? rowToProduct(row) : null;
}

const BATCH_SIZE = 50;
const COLUMNS_PER_ROW = 10;

export async function upsertProducts(db: Database, products: POSProduct[]): Promise<void> {
  for (let i = 0; i < products.length; i += BATCH_SIZE) {
    const batch = products.slice(i, i + BATCH_SIZE);
    const params: unknown[] = [];
    const valueClauses: string[] = [];

    for (let j = 0; j < batch.length; j++) {
      const p = batch[j]!;
      const offset = j * COLUMNS_PER_ROW;
      valueClauses.push(
        `($${offset + 1}, $${offset + 2}, $${offset + 3}, $${offset + 4}, $${offset + 5}, $${offset + 6}, $${offset + 7}, $${offset + 8}, $${offset + 9}, $${offset + 10}, datetime('now'), datetime('now'))`
      );
      params.push(
        p.id,
        p.name,
        p.sku,
        p.barcode ?? null,
        p.sale_price,
        p.stock_quantity,
        p.category ?? null,
        p.image_url ?? null,
        p.tax_rate ?? null,
        p.sellableType ?? 'product',
      );
    }

    await execute(
      db,
      `INSERT INTO products (id, name, sku, barcode, sale_price, stock_quantity, category, image_url, tax_rate, sellable_type, updated_at, synced_at)
       VALUES ${valueClauses.join(', ')}
       ON CONFLICT(id) DO UPDATE SET
         name = excluded.name,
         sku = excluded.sku,
         barcode = excluded.barcode,
         sale_price = excluded.sale_price,
         stock_quantity = excluded.stock_quantity,
         category = excluded.category,
         image_url = excluded.image_url,
         tax_rate = excluded.tax_rate,
         sellable_type = excluded.sellable_type,
         updated_at = datetime('now'),
         synced_at = datetime('now')`,
      params
    );
  }
}

export async function getProductCount(db: Database): Promise<number> {
  const result = await queryOne<{ count: number }>(db, 'SELECT COUNT(*) as count FROM products');
  return result?.count ?? 0;
}
