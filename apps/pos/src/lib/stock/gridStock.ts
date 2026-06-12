/**
 * Task 12 — grid stock join: ONE batched `location_stock` read for the whole
 * loaded catalog, producing the per-tile display map consumed by
 * `ProductGrid`/`ProductCard` via `productStore.locationStock`.
 *
 * Display-only — enforcement is Task 11's `stockGate` (which re-derives
 * effective availability per add, including pending receipts and the cart).
 * This map is the SERVER snapshot only: a tile can read "3 in stock" while
 * the gate blocks the 4th add because two are already in the cart.
 *
 * Map semantics (per grid product id):
 *   - `LocationStockDisplay` → stock-managed; render availability + badge.
 *   - `null`                 → exempt (service / composite sellable): NO
 *                              stock chrome at all.
 *   - absent (undefined)     → no join ran for this catalog (Menu tenants,
 *                              unknown tenant kind, browser dev): the tile
 *                              keeps the legacy `stock_quantity` rendering
 *                              verbatim — Menu display chrome UNCHANGED.
 *
 * The product-grain row (variant_id === '') is the tile-level number;
 * variant-level display is the variant picker's concern (out of scope for
 * Task 12 — noted in the plan).
 */
import type Database from '@tauri-apps/plugin-sql';
import {
  getStockForProducts,
  type LocationStockRow,
} from '@/lib/db/repositories/locationStockRepository';
import { isStockExempt } from './availability';
import type { POSProduct } from '@/types/product';

/** The display slice a product tile needs. Decimal strings — never floats. */
export interface LocationStockDisplay {
  available: string;
  incoming_transfer: string;
  incoming_po: string;
}

export type GridLocationStockMap = Record<string, LocationStockDisplay | null>;

/** Missing local row ⇒ 0 — mirrors the availability selector's rule. */
const ZERO_SLICE: LocationStockDisplay = {
  available: '0',
  incoming_transfer: '0',
  incoming_po: '0',
};

/**
 * Build the grid display map for the loaded catalog. Issues exactly ONE
 * batched repository call (the repository chunks the IN list internally).
 *
 * C2 Menu-mode ids: `product.id` may be a composite `${sellable}_${category}`
 * key while `location_stock` is keyed by the bare sellable UUID — the lookup
 * uses `sellable_id ?? id`, but the returned map is keyed by the GRID id so
 * `ProductGrid` can index it directly with `product.id`.
 */
export async function buildGridLocationStock(
  db: Database,
  products: ReadonlyArray<POSProduct>,
): Promise<GridLocationStockMap> {
  const map: GridLocationStockMap = {};
  const stockManaged: Array<{ gridId: string; bareId: string }> = [];
  const idsToFetch: string[] = [];
  const seenIds = new Set<string>();

  for (const product of products) {
    if (isStockExempt(product)) {
      map[product.id] = null;
      continue;
    }
    const bareId = product.sellable_id ?? product.id;
    stockManaged.push({ gridId: product.id, bareId });
    if (!seenIds.has(bareId)) {
      seenIds.add(bareId);
      idsToFetch.push(bareId);
    }
  }

  if (stockManaged.length === 0) {
    return map;
  }

  const rows = await getStockForProducts(db, idsToFetch);

  // Product-grain rows only — '' is the variant_id sentinel for the
  // tile-level number; variant rows belong to the variant picker.
  const byProductId = new Map<string, LocationStockRow>();
  for (const row of rows) {
    if (row.variant_id !== '') continue;
    byProductId.set(row.product_id, row);
  }

  for (const { gridId, bareId } of stockManaged) {
    const row = byProductId.get(bareId);
    map[gridId] = row
      ? {
          available: row.available,
          incoming_transfer: row.incoming_transfer,
          incoming_po: row.incoming_po,
        }
      : ZERO_SLICE;
  }

  return map;
}
