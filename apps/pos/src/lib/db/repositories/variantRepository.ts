/**
 * FV1 — Offline product_variants repository (migration v53).
 *
 * Caches the variant catalog pulled from GET /pos/variants. Only active
 * variants are stored; deleted variants are removed via deleteVariantsById.
 * price_override is a decimal string — never coerced to float.
 *
 * stock_quantity is a Number projection from location_stock.available
 * (advisory in-stock/out-of-stock display only, NOT used in money math).
 */
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';
import type { POSProductVariant } from '@/types/product';

// ──────────────────────────────────────────────────────────────────────────────
// Types
// ──────────────────────────────────────────────────────────────────────────────

/** Shape of one variant row as delivered by GET /pos/variants. */
export interface ServerVariantRow {
  id: string;
  product_id: string;
  sku: string;
  barcode: string | null;
  name_suffix: string;
  price_override: string | null;
  image_url: string | null;
  is_default: boolean;
  display_order: number;
  updated_at: string | null;
}

/** Internal row shape read from product_variants LEFT JOIN location_stock. */
interface VariantJoinRow {
  id: string;
  product_id: string;
  sku: string;
  barcode: string | null;
  name_suffix: string;
  price_override: string | null;
  image_url: string | null;
  is_default: number;
  display_order: number;
  available: string | null;
}

// ──────────────────────────────────────────────────────────────────────────────
// Internal constants
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Number of $-placeholder parameters per upsert row.
 * Columns: id, product_id, sku, barcode, name_suffix, price_override,
 *          image_url, is_default, display_order, updated_at
 */
const UPSERT_PARAMS_PER_ROW = 10;
const UPSERT_BATCH_SIZE = 50;
const DELETE_BATCH_SIZE = 200;

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/** Convert location_stock.available (scale-4 string or null) → advisory Number. */
function toNumber(raw: string | null): number {
  if (raw == null) return 0;
  const n = Number(raw);
  return Number.isFinite(n) ? n : 0;
}

/** Map a raw join row to the public POSProductVariant shape. */
function toVariant(r: VariantJoinRow): POSProductVariant {
  return {
    id: r.id,
    product_id: r.product_id,
    // variant_code is not synced to the device; sku stands in for display fallback.
    variant_code: r.sku,
    sku: r.sku,
    barcode: r.barcode,
    name_suffix: r.name_suffix,
    is_default: r.is_default === 1,
    is_active: true,
    display_order: r.display_order,
    price_override: r.price_override,
    image_url: r.image_url,
    // Per-variant advisory stock — from location_stock.available (variant grain).
    stock_quantity: toNumber(r.available),
  };
}

// ──────────────────────────────────────────────────────────────────────────────
// Public API
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Upsert variant rows from a delta pull.
 *
 * On conflict: updates all columns and resets is_active = 1 (re-activates
 * any previously soft-deleted row that has come back into the feed).
 */
export async function upsertVariants(db: Database, rows: ServerVariantRow[]): Promise<void> {
  if (rows.length === 0) return;
  for (let i = 0; i < rows.length; i += UPSERT_BATCH_SIZE) {
    const batch = rows.slice(i, i + UPSERT_BATCH_SIZE);
    const params: unknown[] = [];
    const clauses: string[] = [];
    for (let j = 0; j < batch.length; j++) {
      const r = batch[j]!;
      const o = j * UPSERT_PARAMS_PER_ROW;
      clauses.push(
        `($${o + 1}, $${o + 2}, $${o + 3}, $${o + 4}, $${o + 5}, $${o + 6}, $${o + 7}, $${o + 8}, $${o + 9}, $${o + 10})`,
      );
      params.push(
        r.id,
        r.product_id,
        r.sku,
        r.barcode,
        r.name_suffix,
        r.price_override,
        r.image_url,
        r.is_default ? 1 : 0,
        r.display_order,
        r.updated_at,
      );
    }
    await execute(
      db,
      `INSERT INTO product_variants
         (id, product_id, sku, barcode, name_suffix, price_override, image_url, is_default, display_order, updated_at)
       VALUES ${clauses.join(', ')}
       ON CONFLICT(id) DO UPDATE SET
         product_id    = excluded.product_id,
         sku           = excluded.sku,
         barcode       = excluded.barcode,
         name_suffix   = excluded.name_suffix,
         price_override = excluded.price_override,
         image_url     = excluded.image_url,
         is_default    = excluded.is_default,
         display_order = excluded.display_order,
         updated_at    = excluded.updated_at,
         is_active     = 1`,
      params,
    );
  }
}

/**
 * Return all active variants for a product, ordered by display_order ASC, id ASC.
 * Joins location_stock for per-variant advisory stock.
 */
export async function getVariantsForProduct(
  db: Database,
  productId: string,
): Promise<POSProductVariant[]> {
  const rows = await queryAll<VariantJoinRow>(
    db,
    `SELECT v.id, v.product_id, v.sku, v.barcode, v.name_suffix, v.price_override, v.image_url,
            v.is_default, v.display_order, ls.available AS available
       FROM product_variants v
       LEFT JOIN location_stock ls ON ls.product_id = v.product_id AND ls.variant_id = v.id
      WHERE v.product_id = $1 AND v.is_active = 1
      ORDER BY v.display_order, v.id`,
    [productId],
  );
  return rows.map(toVariant);
}

/**
 * Look up a single active variant by barcode.
 * Returns null for empty/blank/whitespace-only barcodes (never match NULL/empty in the table).
 * The barcode is trimmed before the guard and the query so a scanner that
 * appends a trailing space cannot produce a false-negative lookup.
 */
export async function getVariantByBarcode(
  db: Database,
  barcode: string,
): Promise<POSProductVariant | null> {
  const code = barcode.trim();
  if (!code) return null;
  const row = await queryOne<VariantJoinRow>(
    db,
    `SELECT v.id, v.product_id, v.sku, v.barcode, v.name_suffix, v.price_override, v.image_url,
            v.is_default, v.display_order, ls.available AS available
       FROM product_variants v
       LEFT JOIN location_stock ls ON ls.product_id = v.product_id AND ls.variant_id = v.id
      WHERE v.barcode = $1 AND v.is_active = 1
      LIMIT 1`,
    [code],
  );
  return row ? toVariant(row) : null;
}

/**
 * Hard-delete variants by their UUIDs (tombstone from the server deleted_ids list).
 */
export async function deleteVariantsById(db: Database, ids: string[]): Promise<void> {
  if (ids.length === 0) return;
  for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
    const chunk = ids.slice(i, i + DELETE_BATCH_SIZE);
    const ph = chunk.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM product_variants WHERE id IN (${ph})`, chunk);
  }
}

/**
 * Delete all variants for the given product IDs (used when a product itself
 * is tombstoned so its variants are cleaned up in the same sync cycle).
 */
export async function deleteVariantsForProducts(db: Database, productIds: string[]): Promise<void> {
  if (productIds.length === 0) return;
  for (let i = 0; i < productIds.length; i += DELETE_BATCH_SIZE) {
    const chunk = productIds.slice(i, i + DELETE_BATCH_SIZE);
    const ph = chunk.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(db, `DELETE FROM product_variants WHERE product_id IN (${ph})`, chunk);
  }
}
