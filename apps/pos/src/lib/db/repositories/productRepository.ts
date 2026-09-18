import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';
import type { POSProduct, ParapharmacyMeta } from '@/types/product';
import { cachedProductCategoryLabel, productCategoryLabel, type POSProductPayload } from '@/lib/productCategory';

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
  modifier_groups: string | null;
  sellable_id: string | null;
  menu_category_id: string | null;
  /** SQLite integer: 1 = physical (stock-checked), 0 = non-physical (exempt). */
  is_physical: number;
  /** SQLite integer: 1 = product has ≥1 active variant (picker required), 0 = plain product. */
  has_variants: number;
  // Task 20 — parapharmacy columns (nullable TEXT, added by migration v58).
  brand_id: string | null;
  brand_name: string | null;
  /** Raw JSON string — parsed by rowToProduct into ParapharmacyMeta | null. */
  parapharmacy_metadata: string | null;
  /**
   * UoM display precision (nullable INTEGER, added by migration v62). Absent on
   * pre-v62 devices — `rowToProduct` coerces missing/null to `null`.
   */
  quantity_decimals: number | null;
}

/**
 * Task 20 — wire shape accepted by upsertProducts.
 *
 * The server `/products` endpoint may send brand as a NESTED object
 * `brand: {id, name}` (matching the web `ProductData` shape) rather than
 * flat `brand_id` / `brand_name` fields.  We accept both and resolve via
 * `??` on the write path so existing call-sites (which send flat fields or
 * omit brand entirely) stay backwards-compatible.
 */
export type ProductPayload = POSProductPayload & {
  brand?: { id: string; name: string } | null;
};

function rowToProduct(row: ProductRow): POSProduct {
  return {
    id: row.id,
    name: row.name,
    sku: row.sku,
    barcode: row.barcode,
    sale_price: row.sale_price,
    stock_quantity: row.stock_quantity,
    category: cachedProductCategoryLabel(row.category),
    image_url: row.image_url ?? undefined,
    tax_rate: row.tax_rate ?? undefined,
    sellableType: (row.sellable_type as POSProduct['sellableType']) ?? undefined,
    modifier_groups: (() => {
      if (!row.modifier_groups) return undefined;
      try { return JSON.parse(row.modifier_groups); }
      catch { console.warn(`[productRepository] corrupt modifier_groups for product ${row.id}`); return undefined; }
    })(),
    // C2 — Menu-tenant composite columns. Both stay `undefined` for
    // standard-retail rows (where `id` already IS the bare sellable UUID
    // and the columns are NULL post-migration v30).
    ...(row.sellable_id !== null ? { sellable_id: row.sellable_id } : {}),
    ...(row.menu_category_id !== null ? { menu_category_id: row.menu_category_id } : {}),
    // Task 10 — physicality flag. Column defaults to 1 (physical) in SQLite;
    // we map 0 → false, any other value → true (absent/1/null all stay physical).
    is_physical: row.is_physical !== 0,
    // M4 — variant flag. Column defaults to 0; map 1 → true, anything else → false.
    // Only set in the output when true to preserve the optional semantics of
    // POSProduct.has_variants (callers that don't care are unaffected by absence).
    ...(row.has_variants === 1 ? { has_variants: true } : {}),
    // Task 20 — brand columns. Passed through as-is (null stays null).
    brand_id: row.brand_id,
    brand_name: row.brand_name,
    // Task 20 — parapharmacy metadata. TEXT → ParapharmacyMeta | null.
    // rowToProduct is READ-ONLY (SQLite row → POSProduct); brand flattening
    // lives on the WRITE path in upsertProducts.
    parapharmacy_metadata: (() => {
      if (!row.parapharmacy_metadata) return null;
      try { return JSON.parse(row.parapharmacy_metadata) as ParapharmacyMeta; }
      catch { console.warn(`[productRepository] corrupt parapharmacy_metadata for product ${row.id}`); return null; }
    })(),
    // UoM display precision (migration v62). Absent on pre-v62 devices → null.
    quantity_decimals: row.quantity_decimals ?? null,
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

export async function getProductById(db: Database, id: string): Promise<POSProduct | null> {
  const row = await queryOne<ProductRow>(db, 'SELECT * FROM products WHERE id = $1', [id]);
  return row ? rowToProduct(row) : null;
}

/**
 * C2 Day 1 — Codex round 4 P2 closure: plural lookup for Menu-tenant
 * cross-listed barcodes.
 *
 * Post-C2 a Menu sellable cross-listed across categories surfaces as
 * multiple `products` rows that share the same `barcode` / `sku` but
 * differ on the composite `id`. The legacy `getProductByBarcode`
 * returns a single first match — which would auto-add the wrong
 * category-priced row at the Tier-2 SQLite step of the scan resolver,
 * bypassing the chooser modal and locking the receipt to whichever
 * row happened to come back first.
 *
 * `getProductsByBarcode` mirrors the API tier (`fetchProductByBarcode`)
 * by returning every match. The scan resolver fans out to the chooser
 * modal when length > 1.
 */
export async function getProductsByBarcode(db: Database, barcode: string): Promise<POSProduct[]> {
  const rows = await queryAll<ProductRow>(
    db,
    'SELECT * FROM products WHERE barcode = $1 OR sku = $1 ORDER BY id',
    [barcode]
  );
  return rows.map(rowToProduct);
}

const BATCH_SIZE = 50;
/** Number of $-placeholder parameters per product row (excludes datetime('now') literals).
 * Columns: id, name, sku, barcode, sale_price, stock_quantity, category, image_url,
 *          tax_rate, sellable_type, modifier_groups, sellable_id, menu_category_id,
 *          is_physical, has_variants, brand_id, brand_name, parapharmacy_metadata,
 *          quantity_decimals
 *          → 19 data params + 2 datetime('now') literals.
 *          quantity_decimals (v62) is appended LAST so existing positional param
 *          asserts (brand_id=15, brand_name=16, parapharmacy_metadata=17) stay valid.
 *          Batch math: 50 × 19 = 950 < SQLite 999-param limit ✓
 */
const PARAMS_PER_ROW = 19;

export async function upsertProducts(db: Database, products: ProductPayload[]): Promise<void> {
  for (let i = 0; i < products.length; i += BATCH_SIZE) {
    const batch = products.slice(i, i + BATCH_SIZE);
    const params: unknown[] = [];
    const valueClauses: string[] = [];

    for (let j = 0; j < batch.length; j++) {
      const p = batch[j]!;
      const offset = j * PARAMS_PER_ROW;
      valueClauses.push(
        `($${offset + 1}, $${offset + 2}, $${offset + 3}, $${offset + 4}, $${offset + 5}, $${offset + 6}, $${offset + 7}, $${offset + 8}, $${offset + 9}, $${offset + 10}, $${offset + 11}, $${offset + 12}, $${offset + 13}, $${offset + 14}, $${offset + 15}, $${offset + 16}, $${offset + 17}, $${offset + 18}, $${offset + 19}, datetime('now'), datetime('now'))`
      );

      // Task 20 — C-1: flatten nested brand object from the server API shape
      // `brand: {id, name}` onto the flat SQLite columns.  `??` fallback is
      // safe: if the payload already carries flat brand_id/brand_name (or
      // neither), this is a no-op.
      const brand_id = p.brand_id ?? p.brand?.id ?? null;
      const brand_name = p.brand_name ?? p.brand?.name ?? null;

      params.push(
        p.id,
        p.name,
        p.sku,
        p.barcode ?? null,
        p.sale_price,
        // Location-aware-stock tenants: real stock lives in `location_stock`, so
        // `/products` no longer carries stock_quantity (mapped value is undefined).
        // Default the legacy NOT NULL column to 0 rather than violate the constraint
        // and drop the entire catalog. See productRepository.stockQuantity.test.ts.
        p.stock_quantity ?? 0,
        productCategoryLabel(p.category) ?? null,
        p.image_url ?? null,
        p.tax_rate ?? null,
        p.sellableType ?? 'product',
        p.modifier_groups ? JSON.stringify(p.modifier_groups) : null,
        // C2 — Menu-tenant composite columns. Both default to NULL when
        // not set, so standard-retail callsites stay backwards-compatible.
        p.sellable_id ?? null,
        p.menu_category_id ?? null,
        // Task 10 — physicality flag. `undefined` (absent) maps to 1 (physical)
        // so the Menu flatten path, which never sets this field, stays safe.
        p.is_physical === false ? 0 : 1,
        // M4 — variant flag. `undefined` / falsy maps to 0 (non-variant).
        p.has_variants === true ? 1 : 0,
        // Task 20 — brand (flattened from nested or flat API payload).
        brand_id,
        brand_name,
        // Task 20 — parapharmacy metadata: object → JSON string → TEXT, or null.
        p.parapharmacy_metadata != null ? JSON.stringify(p.parapharmacy_metadata) : null,
        // UoM display precision (v62). Appended LAST; absent payloads → null.
        p.quantity_decimals ?? null,
      );
    }

    await execute(
      db,
      `INSERT INTO products (id, name, sku, barcode, sale_price, stock_quantity, category, image_url, tax_rate, sellable_type, modifier_groups, sellable_id, menu_category_id, is_physical, has_variants, brand_id, brand_name, parapharmacy_metadata, quantity_decimals, updated_at, synced_at)
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
         modifier_groups = excluded.modifier_groups,
         sellable_id = excluded.sellable_id,
         menu_category_id = excluded.menu_category_id,
         is_physical = excluded.is_physical,
         has_variants = excluded.has_variants,
         brand_id = excluded.brand_id,
         brand_name = excluded.brand_name,
         parapharmacy_metadata = excluded.parapharmacy_metadata,
         quantity_decimals = excluded.quantity_decimals,
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

/**
 * DEV-QA-111 — id-only projection of the standard-retail catalogue, for the
 * full-pull reconciliation in `pullProductsCore`.
 *
 * Deliberately NOT `getAllProducts`: the reconcile needs nothing but the id
 * set, and `getAllProducts` parses `modifier_groups` /
 * `parapharmacy_metadata` JSON for every row — wasted work on a catalogue of
 * thousands, on a device, on every cursor-less pull.
 *
 * Scoped to BARE rows (`menu_category_id IS NULL`). Menu-tenant composite
 * rows are owned by the `/active-menu` reconcile path
 * (`reconcileMenuProducts`, `pruneStaleCompositeRows`) and must never be
 * reconciled away by the flat `/products` pull — which, for a Menu tenant, is
 * gated off entirely at `pullProducts`. The POS `products` table carries no
 * `company_id` (a device DB is single-company by construction), so this
 * bare/composite split is the only scoping dimension that exists on this
 * layer.
 */
export async function getBareProductIds(db: Database): Promise<string[]> {
  const rows = await queryAll<{ id: string }>(
    db,
    'SELECT id FROM products WHERE menu_category_id IS NULL ORDER BY id',
  );
  return rows.map((r) => r.id);
}

const DELETE_BATCH_SIZE = 200;

export async function deleteProducts(db: Database, ids: string[]): Promise<void> {
  if (ids.length === 0) return;

  for (let i = 0; i < ids.length; i += DELETE_BATCH_SIZE) {
    const batch = ids.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = batch.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(
      db,
      `DELETE FROM products WHERE id IN (${placeholders})`,
      batch,
    );
  }
}

/**
 * C2 Day 1 — Codex round 1 P1 closure: when a Menu tenant's `flatten`
 * path writes composite-id rows post-Day-1, any pre-existing bare-id
 * rows (left over from a pre-Day-1 cache OR a since-gated `pullProducts`
 * pass) must be swept so `getAllProducts` doesn't return both shapes.
 *
 * The sweep is bounded: it only deletes rows whose `id` matches one of
 * the `sellable_id`s passed in AND whose `menu_category_id IS NULL`.
 * Composite rows (which have `menu_category_id NOT NULL`) are never
 * touched. Standard-retail rows for OTHER sellables (not represented in
 * the active menu) are also left alone — though for a Menu tenant
 * those should not exist post-fix because `pullProducts` is gated at
 * `runFullSync`.
 */
export async function deleteStaleBareSellableRows(
  db: Database,
  sellableIds: string[],
): Promise<void> {
  if (sellableIds.length === 0) return;

  for (let i = 0; i < sellableIds.length; i += DELETE_BATCH_SIZE) {
    const batch = sellableIds.slice(i, i + DELETE_BATCH_SIZE);
    const placeholders = batch.map((_, idx) => `$${idx + 1}`).join(', ');
    await execute(
      db,
      `DELETE FROM products
       WHERE id IN (${placeholders})
         AND menu_category_id IS NULL`,
      batch,
    );
  }
}

/**
 * C2 Day 1 — Codex round 2 P2 closure: prune composite Menu-tenant rows
 * whose composite id is no longer present in the fresh active-menu set.
 *
 * When an item is removed from a menu category (or marked unavailable)
 * but the same sellable remains in another category, the deleted-from
 * category's composite row would otherwise persist in SQLite — the C2
 * Day-1 sweep at `deleteStaleBareSellableRows` only removes BARE rows,
 * so a stale composite would keep showing up in the POS grid until the
 * next full catalog wipe.
 *
 * The prune is bounded: only composite rows (`menu_category_id IS NOT
 * NULL`) whose `id` is not in `freshCompositeIds` are deleted. Bare-id
 * rows for non-Menu tenants are untouched. Empty `freshCompositeIds`
 * is a defensive no-op — a transient empty fetch must not wipe the
 * cached catalog.
 */
export async function pruneStaleCompositeRows(
  db: Database,
  freshCompositeIds: string[],
): Promise<void> {
  if (freshCompositeIds.length === 0) return;

  const existing = await queryAll<{ id: string }>(
    db,
    'SELECT id FROM products WHERE menu_category_id IS NOT NULL',
  );

  const freshSet = new Set(freshCompositeIds);
  const stale = existing.map((r) => r.id).filter((id) => !freshSet.has(id));

  if (stale.length === 0) return;

  await deleteProducts(db, stale);
}

/**
 * C2 Day 1 — Codex round 3 P2 closure: explicit "wipe everything" for the
 * success-empty active-menu case.
 *
 * `pruneStaleCompositeRows` short-circuits on empty input as a defensive
 * no-op for transient API failures (so a network blip never wipes the
 * cached catalog). But when the menu API succeeds with an empty
 * response, we DO want to clear stale composite rows — otherwise a
 * later restart or offline fallback would hydrate from `getAllProducts`
 * and surface items the active menu has removed. Callers that have
 * proven the empty result is server-authoritative use this wipe path
 * instead.
 *
 * Only composite rows (`menu_category_id IS NOT NULL`) are affected;
 * bare-id rows (standard-retail orphans on a tenant transitioning
 * modes) are left alone.
 */
export async function wipeAllCompositeRows(db: Database): Promise<void> {
  await execute(
    db,
    'DELETE FROM products WHERE menu_category_id IS NOT NULL',
  );
}

/**
 * C2 Day 1 — Codex round 5 P2 closure: full bare-row wipe for the
 * Menu-tenant success-non-empty path.
 *
 * The round-1 helper `deleteStaleBareSellableRows` was sellable-
 * targeted — it only swept bare rows whose `id` matched a sellable
 * present in the fresh menu. Bare orphans for sellables REMOVED from
 * the menu entirely (pre-C2 cache rows that never got a composite
 * counterpart) survived the sweep and resurfaced as ghost items via
 * `getAllProducts` after a restart / offline fallback.
 *
 * Codex's framing: for Menu tenants, EVERY bare row is stale (because
 * `pullProducts` is gated for Menu tenants, no new bare rows are ever
 * written post-C2; only pre-C2 cache leftovers remain). The successful
 * `/active-menu` fetch is the canonical "what should exist" signal —
 * everything outside that signal can be dropped.
 *
 * Only the Menu-tenant fetch path in `productStore.doMenuApiFetch`
 * should call this. Standard-retail tenants would have their entire
 * catalog wiped if they hit this; the Menu branch never fires for
 * them.
 */
export async function wipeAllBareRows(db: Database): Promise<void> {
  await execute(db, 'DELETE FROM products WHERE menu_category_id IS NULL');
}

/**
 * C2 Day 1 — Codex round 4 P2 closure: total wipe for the Menu-tenant
 * success-empty path.
 *
 * For a Menu tenant on a server-authoritative empty `/active-menu`
 * response, EVERY local product row is stale: composite rows reflect
 * the previous menu state (gone now), and bare-id rows are pre-C2
 * cache orphans (no longer maintained because `pullProducts` is gated
 * for Menu tenants). The wipe therefore drops every row.
 *
 * Only the Menu-tenant success-empty branch in
 * `productStore.doMenuApiFetch` should call this. Standard-retail
 * tenants never reach that branch (they go through the foreground
 * pull path).
 */
export async function wipeAllProductRows(db: Database): Promise<void> {
  await execute(db, 'DELETE FROM products');
}

/**
 * C2 Day 1 — Codex round 6 P1 closure: shared reconciliation logic for
 * the Menu-tenant `products` table.
 *
 * The /active-menu pull lands category + item tables (menu_categories,
 * menu_category_items) but the POS grid reads from `products`. Two
 * callers need to flatten the menu into `products` and reconcile stale
 * rows:
 *
 *   1. `productStore.doMenuApiFetch` — foreground fetch from
 *      LoginPage / HomePage mount.
 *   2. `runFullSync` (post-pullActiveMenu) — background scheduler tick
 *      every 60s. Without this, a mid-shift menu change on the server
 *      lands in menu_categories but never reaches the cashier's grid
 *      until the next foreground fetch, so the cashier keeps selling
 *      stale items.
 *
 * Logic is identical to `doMenuApiFetch`'s post-flatten step:
 *   - empty fresh set ⇒ wipeAllProductRows (success-empty contract).
 *   - non-empty ⇒ upsertProducts + wipeAllBareRows + pruneStaleCompositeRows.
 *
 * The helper is silent on errors (callers wrap their own try/catch
 * with the appropriate logging contract — productStore is best-effort
 * silent; runFullSync logs via logSyncOperation).
 */
export async function reconcileMenuProducts(
  db: Database,
  freshProducts: POSProduct[],
): Promise<void> {
  if (freshProducts.length === 0) {
    await wipeAllProductRows(db);
    return;
  }
  await upsertProducts(db, freshProducts);
  await wipeAllBareRows(db);
  const freshCompositeIds = freshProducts
    .filter((p) => typeof p.menu_category_id === 'string')
    .map((p) => p.id);
  if (freshCompositeIds.length > 0) {
    await pruneStaleCompositeRows(db, freshCompositeIds);
  }
}
