import type { POSProduct } from '@/types/product';

/**
 * Recent-scan LRU cache + chooser-pick preference cache.
 *
 * Closes the T2.1 PR #94 deferral noted in the kickoff doc:
 *   "Top-tier POS systems cache the last N (typically 50–100) successful
 *    scan resolutions to handle scan-the-same-product-twice without
 *    re-querying. Step B handles each scan independently. Adding LRU is
 *    a small follow-up."
 *
 * Two roles in one cache:
 *
 *   - **Recent-scan tier (Tier 0 of `resolveScannedCode`):** before the
 *     in-memory / SQLite / API tiers, check the cache. A hit short-
 *     circuits to the cached product, saving the resolver work for
 *     repeat scans (deli weight items, customer adds same product
 *     twice in quick succession, scan-then-rescan after correction).
 *
 *   - **Chooser-pick preference:** when the resolver returns
 *     `{ kind: 'choose', candidates }` and the cashier picks one
 *     candidate from `BarcodeChooserModal`, write that picked product
 *     to this cache so the next scan of the same code resolves to a
 *     cached HIT and the cashier doesn't see the chooser modal again
 *     for the same colliding code in the same session.
 *
 * Module-level mutable state — pragmatic per-process cache, cleared on
 * app restart, never persisted. No proactive invalidation: stale
 * entries (e.g. price change) are bounded by the LRU eviction window
 * + the natural session-end clear. If a product changes
 * mid-session and the cashier needs the new state, scanning the code
 * again hits the cached entry, but the upstream sync tick will have
 * already refreshed `productStore.products` — so the next scan after
 * the next sync tick (or app restart) will be correct. Acceptable
 * trade-off given the cache's session-only lifetime.
 *
 * `Map` preserves insertion order in ECMAScript, which gives us LRU
 * semantics for free: read = delete + re-insert; write at capacity =
 * delete the first key (oldest) before insert.
 */

/**
 * Cache capacity. 100 picked per the T2.1 kickoff doc's "typically
 * 50–100" reference to the Square / Toast / Shopify pattern. Tunable.
 */
export const SCAN_CACHE_MAX_SIZE = 100;

interface CacheEntry {
  companyId: string;
  product: POSProduct;
}

const cache = new Map<string, CacheEntry>();

/**
 * Read a cached scan resolution for `code` scoped to the active
 * `companyId`. Codex round-2 P2 (PR #98): a multi-tenant POS install
 * can switch companies without restarting; an entry from a previous
 * company must NEVER resolve a scan in the new company. The companyId
 * gate makes cross-tenant cache hits impossible. A stale entry from a
 * prior company is ALSO evicted on miss so the LRU doesn't keep
 * carrying it.
 *
 * Promotes a matching entry to most-recently-used. Returns `null` for
 * a miss (including a cross-company collision).
 */
export function getCachedScan(code: string, companyId: string): POSProduct | null {
  const hit = cache.get(code);
  if (hit === undefined) return null;
  if (hit.companyId !== companyId) {
    // Cross-tenant collision — evict the stale entry and miss.
    cache.delete(code);
    return null;
  }

  // LRU promotion: re-insert to move to the end of the iteration order.
  cache.delete(code);
  cache.set(code, hit);
  return hit.product;
}

/**
 * Cache a scan resolution scoped to the active `companyId`. Two
 * callers in production:
 *   - `resolveScannedCode` after a successful Tier 1 / 2 / 3 hit.
 *   - The chooser-pick handler in `HomePage.tsx` after the cashier
 *     resolves a multi-match collision.
 *
 * If `code` is already in the cache, the entry is updated AND promoted
 * to most-recently-used. If the cache is at capacity, the oldest entry
 * is evicted before the insert.
 */
export function setCachedScan(code: string, product: POSProduct, companyId: string): void {
  if (cache.has(code)) {
    cache.delete(code);
  } else if (cache.size >= SCAN_CACHE_MAX_SIZE) {
    const oldestKey = cache.keys().next().value;
    if (oldestKey !== undefined) {
      cache.delete(oldestKey);
    }
  }
  cache.set(code, { companyId, product });
}

/**
 * Forget a single cached entry — useful when an upstream signal
 * indicates the cached product is stale (out of stock, deleted,
 * deactivated). Currently unused in production but exposed for
 * defensive future wiring.
 */
export function evictCachedScan(code: string): void {
  cache.delete(code);
}

/**
 * Empty the cache. Intended for tests; production never needs this
 * because the cache lives only for the process lifetime.
 */
export function clearScanCache(): void {
  cache.clear();
}
