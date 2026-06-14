/**
 * Task F5 — cross-location stock distribution cache repository.
 *
 * Caches the server response from GET /pos/products/{id}/stock-distribution
 * per product (and optionally per variant). The payload is stored as opaque
 * JSON TEXT — no decimal parsing occurs at this layer.
 *
 * `variant_id` uses '' for product-grain rows (SQLite PKs reject NULL).
 * Mirrors the conventions of locationStockRepository.ts.
 */
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';

// ──────────────────────────────────────────────────────────────────────────────
// Types
// ──────────────────────────────────────────────────────────────────────────────

export interface DistributionCacheRow {
  product_id: string;
  variant_id: string; // '' = product-grain
  variant_label: string | null;
  payload: string; // opaque JSON TEXT (quantities remain scale-4 decimal strings)
  fetched_at: string;
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/** Normalise variant_id: null → '' (product-grain sentinel). */
function vid(variantId: string | null): string {
  return variantId ?? '';
}

// ──────────────────────────────────────────────────────────────────────────────
// Public API
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Insert or update a distribution cache entry.
 * On conflict (same product_id + variant_id) the row is fully replaced.
 */
export async function upsertDistribution(
  db: Database,
  productId: string,
  variantId: string | null,
  variantLabel: string | null,
  payload: string,
  fetchedAt: string,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO product_stock_distribution_cache
       (product_id, variant_id, variant_label, payload, fetched_at)
     VALUES ($1, $2, $3, $4, $5)
     ON CONFLICT(product_id, variant_id) DO UPDATE SET
       variant_label = excluded.variant_label,
       payload       = excluded.payload,
       fetched_at    = excluded.fetched_at`,
    [productId, vid(variantId), variantLabel, payload, fetchedAt],
  );
}

/**
 * Read a single cache row for a product+variant key.
 * Pass `variantId = null` (or `''`) for the product-grain key.
 * Returns null when no row exists.
 */
export async function getDistribution(
  db: Database,
  productId: string,
  variantId: string | null,
): Promise<DistributionCacheRow | null> {
  const row = await queryOne<DistributionCacheRow>(
    db,
    `SELECT product_id, variant_id, variant_label, payload, fetched_at
       FROM product_stock_distribution_cache
      WHERE product_id = $1 AND variant_id = $2`,
    [productId, vid(variantId)],
  );
  return row ?? null;
}

/**
 * List all cached distribution rows for a product (all variants + product-grain).
 * Returns an empty array when no rows exist.
 */
export async function getAllForProduct(
  db: Database,
  productId: string,
): Promise<DistributionCacheRow[]> {
  return queryAll<DistributionCacheRow>(
    db,
    `SELECT product_id, variant_id, variant_label, payload, fetched_at
       FROM product_stock_distribution_cache
      WHERE product_id = $1`,
    [productId],
  );
}

/**
 * Delete all cached distribution rows for the given product ids.
 * Called by the syncService tombstone hook alongside deleteLocationStockForProducts.
 * No-op when productIds is empty.
 */
export async function deleteDistributionForProducts(
  db: Database,
  productIds: string[],
): Promise<void> {
  if (productIds.length === 0) return;
  const placeholders = productIds.map((_, i) => `$${i + 1}`).join(', ');
  await execute(
    db,
    `DELETE FROM product_stock_distribution_cache WHERE product_id IN (${placeholders})`,
    productIds,
  );
}
