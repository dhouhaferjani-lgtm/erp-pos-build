/**
 * T2.1 Step B — three-tier scan resolver, plus the post-T2.1 LRU
 * recent-scan cache (Tier 0).
 *
 * Resolves a barcode/SKU scan code through tiers in order:
 *   0. **Recent-scan LRU** (`scanResolutionCache`) — O(1). Caches the
 *      last `SCAN_CACHE_MAX_SIZE` successful resolutions in this
 *      process. Repeat scans within the session skip the resolution
 *      chain entirely. Also doubles as the chooser-pick preference
 *      cache: when the cashier picks a product from a multi-match
 *      collision, that pick is cached so the same code's next scan
 *      doesn't re-mount the chooser modal.
 *   1. **In-memory** (`productStore.products`) — O(n), fast for n ≤ 5000.
 *      Matches `barcode === code || sku === code` (preserved from the
 *      pre-T2.1 inline scan handler at HomePage.tsx).
 *   2. **SQLite** (`getProductByBarcode(db, code)`) — `~10 ms`. The
 *      function name is legacy; it actually does
 *      `barcode = $1 OR sku = $1`.
 *   3. **API** (`fetchProductByBarcode(code, opts)`) — `≤ 5 s` (tunable).
 *      The backend filter accepts `barcode` query param and matches
 *      barcode OR sku server-side.
 *
 * Tier 3 result classification:
 *   - 0 results → `{ kind: 'miss' }`
 *   - exactly 1 → `{ kind: 'hit', product }` + SQLite write-through so the
 *     next scan in the same session is a Tier 2 hit.
 *   - >1 results → `{ kind: 'choose', candidates }` — the caller mounts a
 *     chooser modal (collision UX, matches Toast / Shopify POS pattern).
 *
 * After any tier 1/2/3 hit we ALSO write the resolved product to the
 * Tier 0 LRU so the next scan of the same code is an O(1) hit. Tier 3
 * `choose` is NOT cached here — the cashier hasn't expressed a
 * preference yet. The chooser pick handler in `HomePage.tsx` writes the
 * picked product to the cache once the cashier resolves the collision.
 *
 * Pre-tier guard: codes shorter than 2 chars or whitespace-only are
 * treated as phantom scanner events and short-circuit to `'miss'`
 * without any tier work.
 *
 * Logging: Tier 3 errors are caught + logged via `serializeErrorForLog`
 * (T0.1 contract) and downgraded to `{ kind: 'miss' }` so the cashier
 * sees the existing "product not found" UX rather than an unhandled
 * rejection.
 */
import type Database from '@tauri-apps/plugin-sql';
import type { POSProduct, POSProductVariant } from '@/types/product';
import {
  getProductsByBarcode,
  getProductById,
  upsertProducts,
} from '@/lib/db/repositories/productRepository';
import { getVariantByBarcode } from '@/lib/db/repositories/variantRepository';
import { fetchProductByBarcode } from '@/api/productApi';
import { serializeErrorForLog } from '@/lib/errorLogging';
import { getCachedScan, setCachedScan } from './scanResolutionCache';

/**
 * Tunable Tier 3 (API) timeout. The cashier sees an inline subtle
 * "Looking up…" spinner during this tier; longer than 5 s feels stuck.
 * Tunable here at the top of the file per cashier feedback.
 */
export const SCAN_API_TIMEOUT_MS = 5_000;

const MIN_SCAN_CODE_LENGTH = 2;

export type ResolveScannedCodeResult =
  | { kind: 'hit'; product: POSProduct }
  | { kind: 'variant-hit'; product: POSProduct; variant: POSProductVariant }
  | { kind: 'choose'; candidates: POSProduct[] }
  | { kind: 'miss' };

export interface ResolveScannedCodeDeps {
  db: Database;
  products: POSProduct[];
  /**
   * Active company id — passed through to the Tier 0 LRU so cache
   * lookups are tenant-scoped. Codex round-2 P2 (PR #98): a barcode
   * cached in company A must NEVER resolve a scan in company B.
   */
  companyId: string;
  /** Optional caller-provided abort signal — supports concurrent-scan cancellation. */
  signal?: AbortSignal;
}

export async function resolveScannedCode(
  rawCode: string,
  deps: ResolveScannedCodeDeps,
): Promise<ResolveScannedCodeResult> {
  const code = rawCode.trim();
  if (code.length < MIN_SCAN_CODE_LENGTH) {
    return { kind: 'miss' };
  }

  // Tier 0 — recent-scan LRU. O(1) hit before any tier work; also the
  // chooser-pick preference: a code that previously triggered the
  // chooser and was resolved by the cashier comes back here as a hit.
  // Tenant-scoped via deps.companyId — cross-company collisions
  // resolve to a miss.
  const cachedHit = getCachedScan(code, deps.companyId);
  if (cachedHit) {
    return { kind: 'hit', product: cachedHit };
  }

  // Tier 1 — in-memory snapshot. Preserves barcode OR sku disjunction
  // from the pre-T2.1 inline handler.
  //
  // Codex review (PR #107 round 4 P2): C2 Day 1 introduces composite-id
  // rows for Menu-tenant cross-listed sellables. The same `barcode` /
  // `sku` can now appear on multiple POSProducts (one per category).
  // We must therefore look at ALL matches — auto-adding the first
  // would lock the receipt to whichever category-priced row happened
  // to come back first in the in-memory snapshot. Multiple matches
  // route to the chooser modal (matching the Tier-3 API contract).
  const inMemoryMatches = deps.products.filter(
    (p) => p.barcode === code || p.sku === code,
  );
  if (inMemoryMatches.length === 1) {
    const product = inMemoryMatches[0]!;
    setCachedScan(code, product, deps.companyId);
    return { kind: 'hit', product };
  }
  if (inMemoryMatches.length > 1) {
    // Multi-match collision — defer to chooser. No Tier 0 cache write
    // (no preference yet) — the chooser pick handler in HomePage
    // writes the cashier's pick to the LRU once they resolve.
    return { kind: 'choose', candidates: inMemoryMatches };
  }

  // Tier 2 — SQLite. Variant-barcode lookup runs first within this tier
  // so a variant barcode short-circuits before the product-barcode scan.
  // Variant-hits are NOT written to the Tier 0 LRU (the LRU stores
  // POSProduct only; caching here would lose the variant on the next repeat
  // scan). If the variant's parent product is not in the in-memory snapshot,
  // we fall back to a SQLite getProductById lookup. If the parent is still
  // not found (not yet synced), we fall through to the existing product tiers
  // rather than crashing.
  //
  // `getProductsByBarcode` (plural) returns every match for the product-
  // barcode sub-tier; same multi-match handling as Tier 1.
  try {
    const variant = await getVariantByBarcode(deps.db, code);
    if (variant) {
      const product =
        deps.products.find((p) => p.id === variant.product_id) ??
        (await getProductById(deps.db, variant.product_id));
      if (product) {
        // Do NOT cache variant-hits via setCachedScan — the LRU entry stores
        // POSProduct only; a repeat scan of the same variant barcode must
        // still surface the variant.
        return { kind: 'variant-hit', product, variant };
      }
      // Parent product not yet synced → fall through to the product tiers below.
    }

    const sqliteMatches = await getProductsByBarcode(deps.db, code);
    if (sqliteMatches.length === 1) {
      const product = sqliteMatches[0]!;
      setCachedScan(code, product, deps.companyId);
      return { kind: 'hit', product };
    }
    if (sqliteMatches.length > 1) {
      return { kind: 'choose', candidates: sqliteMatches };
    }
  } catch (err) {
    // SQLite read failure → log + fall through to Tier 3. The cashier
    // sees the API tier's outcome; the SQLite bad state is captured
    // for diagnosis.
    console.error(
      '[POS][resolveScannedCode] SQLite tier failed, falling through to API',
      serializeErrorForLog(err),
    );
  }

  // Tier 3 — API. Caller-provided signal threads through to apiGet so
  // a subsequent scan can abort the in-flight call.
  try {
    const apiResults = await fetchProductByBarcode(code, {
      timeoutMs: SCAN_API_TIMEOUT_MS,
      signal: deps.signal,
    });
    if (apiResults.length === 0) {
      return { kind: 'miss' };
    }
    if (apiResults.length === 1) {
      const product = apiResults[0]!;
      // Write-through to SQLite so the next scan of this code is a
      // Tier 2 hit. Best-effort: a write failure here doesn't change
      // the result.
      try {
        await upsertProducts(deps.db, [product]);
      } catch (err) {
        console.error(
          '[POS][resolveScannedCode] SQLite write-through failed (non-fatal)',
          serializeErrorForLog(err),
        );
      }
      setCachedScan(code, product, deps.companyId);
      return { kind: 'hit', product };
    }
    // Multiple matches — collision UX. Caller resolves with chooser
    // modal; no auto-pick AND no Tier 0 cache write here. The chooser
    // pick handler in HomePage.tsx writes to the LRU once the cashier
    // resolves the collision, so the next scan of the same code skips
    // the chooser.
    return { kind: 'choose', candidates: apiResults };
  } catch (err) {
    console.error(
      '[POS][resolveScannedCode] API tier failed',
      serializeErrorForLog(err),
    );
    return { kind: 'miss' };
  }
}
