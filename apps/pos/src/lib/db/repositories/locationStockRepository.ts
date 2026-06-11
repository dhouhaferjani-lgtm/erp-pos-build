/**
 * Task 8 — local SQLite `location_stock` repository.
 *
 * Caches the terminal's own location stock pulled from GET /pos/stock-levels.
 * Quantities are TEXT decimal strings (scale-4) end-to-end — never coerced
 * to float. `variant_id` uses '' for product-grain rows (SQLite PKs reject NULL).
 *
 * Public API — Tasks 9/10/11/12 build against these exact shapes and names.
 */
import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';

// ──────────────────────────────────────────────────────────────────────────────
// Types
// ──────────────────────────────────────────────────────────────────────────────

export interface LocationStockRow {
  product_id: string;
  variant_id: string; // '' = product-grain
  quantity: string;
  reserved: string;
  available: string;
  incoming_transfer: string;
  incoming_po: string;
  updated_at: string | null;
}

/** Shape of the stock entries returned by GET /pos/stock-levels. */
export interface ServerStockRow {
  product_id: string;
  variant_id: string | null;
  quantity: string;
  reserved: string;
  available: string;
  updated_at: string | null;
}

/** Shape of the incoming entries returned by GET /pos/stock-levels. */
export interface ServerIncomingRow {
  product_id: string;
  variant_id: string | null;
  incoming_transfer: string;
  incoming_po: string;
}

// ──────────────────────────────────────────────────────────────────────────────
// Internal constants
// ──────────────────────────────────────────────────────────────────────────────

/** Match productRepository.ts DELETE_BATCH_SIZE. */
const DELETE_BATCH_SIZE = 200;

/**
 * Number of $-placeholder parameters per upsert row.
 * Columns: product_id, variant_id, quantity, reserved, available, updated_at
 */
const UPSERT_PARAMS_PER_ROW = 6;
const UPSERT_BATCH_SIZE = 50;

/**
 * Number of $-placeholder parameters per incoming upsert row.
 * Columns: product_id, variant_id, incoming_transfer, incoming_po
 */
const INCOMING_PARAMS_PER_ROW = 4;

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
 * Upsert stock rows from a delta or full pull.
 *
 * On conflict updates quantity/reserved/available/updated_at.
 * Incoming columns (incoming_transfer/incoming_po) are PRESERVED — they come
 * from a separate replaceIncoming call and must not be clobbered here.
 */
export async function upsertStockRows(
  db: Database,
  rows: ServerStockRow[],
): Promise<void> {
  if (rows.length === 0) return;

  for (let i = 0; i < rows.length; i += UPSERT_BATCH_SIZE) {
    const batch = rows.slice(i, i + UPSERT_BATCH_SIZE);
    const params: unknown[] = [];
    const valueClauses: string[] = [];

    for (let j = 0; j < batch.length; j++) {
      const r = batch[j]!;
      const offset = j * UPSERT_PARAMS_PER_ROW;
      valueClauses.push(
        `($${offset + 1}, $${offset + 2}, $${offset + 3}, $${offset + 4}, $${offset + 5}, $${offset + 6})`,
      );
      params.push(r.product_id, vid(r.variant_id), r.quantity, r.reserved, r.available, r.updated_at);
    }

    await execute(
      db,
      `INSERT INTO location_stock
         (product_id, variant_id, quantity, reserved, available, updated_at)
       VALUES ${valueClauses.join(', ')}
       ON CONFLICT(product_id, variant_id) DO UPDATE SET
         quantity   = excluded.quantity,
         reserved   = excluded.reserved,
         available  = excluded.available,
         updated_at = excluded.updated_at`,
      params,
    );
  }
}

/**
 * Full replace of the stock set (delta=false mode).
 *
 * 1. Upsert every row in the server set (preserves incoming on survivors).
 * 2. Delete any local rows whose (product_id, variant_id) key is absent
 *    from the server set — uses a Set for O(1) lookup (5K products).
 */
export async function replaceAllStock(
  db: Database,
  rows: ServerStockRow[],
): Promise<void> {
  // Upsert the full set first.
  await upsertStockRows(db, rows);

  // Build a fast lookup of keys that should survive.
  const keepKeys = new Set(rows.map((r) => `${r.product_id}|${vid(r.variant_id)}`));

  // Load all local keys.
  const localRows = await queryAll<{ product_id: string; variant_id: string }>(
    db,
    'SELECT product_id, variant_id FROM location_stock',
  );

  const toDelete = localRows.filter(
    (row) => !keepKeys.has(`${row.product_id}|${row.variant_id}`),
  );

  if (toDelete.length === 0) return;

  // Delete in chunks to stay within SQLite parameter limits.
  for (let i = 0; i < toDelete.length; i += DELETE_BATCH_SIZE) {
    const chunk = toDelete.slice(i, i + DELETE_BATCH_SIZE);
    // Build a WHERE clause matching on both PK columns.
    const conditions = chunk
      .map((_, idx) => `(product_id = $${idx * 2 + 1} AND variant_id = $${idx * 2 + 2})`)
      .join(' OR ');
    const params: unknown[] = chunk.flatMap((row) => [row.product_id, row.variant_id]);
    await execute(db, `DELETE FROM location_stock WHERE ${conditions}`, params);
  }
}

/**
 * Replace the incoming snapshot (wholesale replace, arrives complete every pull).
 *
 * 1. Zero out ALL existing incoming_transfer/incoming_po values.
 * 2. For each row in the new set:
 *    - If the product already has a stock row → update the two incoming columns.
 *    - If not → INSERT with quantity/reserved/available defaulting to '0'.
 */
export async function replaceIncoming(
  db: Database,
  rows: ServerIncomingRow[],
): Promise<void> {
  // Step 1 — zero out all existing incoming values.
  await execute(
    db,
    `UPDATE location_stock SET incoming_transfer = '0', incoming_po = '0'`,
  );

  if (rows.length === 0) return;

  // Step 2 — upsert each incoming row (update the two incoming columns only;
  // rows without a prior stock entry are created with '0' stock defaults).
  for (let i = 0; i < rows.length; i += UPSERT_BATCH_SIZE) {
    const batch = rows.slice(i, i + UPSERT_BATCH_SIZE);
    const params: unknown[] = [];
    const valueClauses: string[] = [];

    for (let j = 0; j < batch.length; j++) {
      const r = batch[j]!;
      const offset = j * INCOMING_PARAMS_PER_ROW;
      valueClauses.push(
        `($${offset + 1}, $${offset + 2}, $${offset + 3}, $${offset + 4})`,
      );
      params.push(r.product_id, vid(r.variant_id), r.incoming_transfer, r.incoming_po);
    }

    await execute(
      db,
      `INSERT INTO location_stock
         (product_id, variant_id, incoming_transfer, incoming_po)
       VALUES ${valueClauses.join(', ')}
       ON CONFLICT(product_id, variant_id) DO UPDATE SET
         incoming_transfer = excluded.incoming_transfer,
         incoming_po       = excluded.incoming_po`,
      params,
    );
  }
}

/**
 * Read a single stock row.
 * Pass `variantId = null` (or `''`) for the product-grain key.
 */
export async function getStockFor(
  db: Database,
  productId: string,
  variantId: string | null,
): Promise<LocationStockRow | null> {
  const row = await queryOne<LocationStockRow>(
    db,
    `SELECT product_id, variant_id, quantity, reserved, available,
            incoming_transfer, incoming_po, updated_at
       FROM location_stock
      WHERE product_id = $1 AND variant_id = $2`,
    [productId, vid(variantId)],
  );
  return row ?? null;
}

/**
 * Batched read for the product grid (Task 12).
 * Returns all location_stock rows whose product_id is in `productIds`.
 * Returns an empty array when `productIds` is empty.
 */
export async function getStockForProducts(
  db: Database,
  productIds: string[],
): Promise<LocationStockRow[]> {
  if (productIds.length === 0) return [];

  // Chunk the IN list to stay within SQLite parameter limits.
  const results: LocationStockRow[] = [];

  for (let i = 0; i < productIds.length; i += DELETE_BATCH_SIZE) {
    const chunk = productIds.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = chunk.map((_, idx) => `$${idx + 1}`).join(', ');
    const rows = await queryAll<LocationStockRow>(
      db,
      `SELECT product_id, variant_id, quantity, reserved, available,
              incoming_transfer, incoming_po, updated_at
         FROM location_stock
        WHERE product_id IN (${placeholders})`,
      chunk,
    );
    results.push(...rows);
  }

  return results;
}

/**
 * Delete all location_stock rows for the given product ids.
 * Called by the syncService tombstone hook after deleteProducts().
 * Chunks the IN list to mirror productRepository.deleteProducts behaviour.
 */
export async function deleteForProducts(
  db: Database,
  productIds: string[],
): Promise<void> {
  if (productIds.length === 0) return;

  for (let i = 0; i < productIds.length; i += DELETE_BATCH_SIZE) {
    const chunk = productIds.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = chunk.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(
      db,
      `DELETE FROM location_stock WHERE product_id IN (${placeholders})`,
      chunk,
    );
  }
}
