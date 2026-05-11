import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPOSProducts, fetchCompanyConfig, fetchActiveMenu, flattenMenuToProducts } from '@/api/productApi';
import { getDatabase } from '@/lib/db';
import {
  getAllProducts,
  reconcileMenuProducts,
} from '@/lib/db/repositories/productRepository';
import { useAuthStore } from '@/stores/authStore';
import { diffProducts } from '@/lib/sync/productDiff';
import { pullProductsForeground } from '@/lib/sync/syncService';
import { clearScanCache } from '@/lib/scan/scanResolutionCache';
import type { POSProduct } from '@/types/product';
import type { CompanyConfig } from '@/types/companyConfig';

/**
 * T2.1 Step A — foreground full-catalog pull timeout (per-page).
 *
 * The legacy `fetchPOSProducts({ limit: 500 })` API call was bounded by
 * `apiGet`'s default 10s; the foreground full-catalog pull paginates so
 * we apply the 30s ceiling per page (5K SKUs over Slow-3G is plausibly
 * ~30-60s total, but each individual page should stay well under 30s).
 */
const FOREGROUND_PULL_TIMEOUT_MS = 30_000;

interface ProductState {
  products: POSProduct[];
  categories: string[];
  isLoading: boolean;
  error: string | null;
  lastFetched: number | null;
  companyConfig: CompanyConfig | null;
}

interface ProductActions {
  fetchProducts: (force?: boolean) => Promise<void>;
  refreshFromSQLite: () => Promise<void>;
  getById: (id: string) => POSProduct | undefined;
  reset: () => void;
}

type ProductStore = ProductState & ProductActions;

const initialState: ProductState = {
  products: [],
  categories: [],
  isLoading: false,
  error: null,
  lastFetched: null,
  companyConfig: null,
};

/**
 * C2 Day 2 — Menu tenants emit one POSProduct per (sellable, category)
 * composite (`${sellable_id}_${menu_category_id}`). The legacy name-only
 * category Set is correct for non-Menu catalogs but mishandles a mid-
 * shift category rename on Menu tenants: cached rows carry the OLD name,
 * freshly flattened rows carry the NEW one, and both reach this stage
 * during the reconcile window. A naive dedupe produces two list entries
 * for one logical category — AND clicking either tab filters by name,
 * hiding the rows carrying the other label (Codex PR #109 r1 P2).
 *
 * Fix: canonicalize per `menu_category_id` BEFORE both category-list
 * extraction and `set({ products })`. First-seen name wins for the
 * canonical mapping; every product carrying that id is rewritten to
 * the canonical name in place via a fresh object (no input mutation).
 * Non-Menu rows (no `menu_category_id`) bypass canonicalization and
 * dedupe by name as before.
 */
function canonicalizeMenuCatalog(
  products: POSProduct[],
): { products: POSProduct[]; categories: string[] } {
  const canonicalNameById = new Map<string, string>();
  for (const p of products) {
    if (p.menu_category_id && p.category && !canonicalNameById.has(p.menu_category_id)) {
      canonicalNameById.set(p.menu_category_id, p.category);
    }
  }

  const namesWithoutId = new Set<string>();
  const normalized = products.map((p) => {
    if (p.menu_category_id) {
      const canonical = canonicalNameById.get(p.menu_category_id);
      if (canonical && canonical !== p.category) {
        return { ...p, category: canonical };
      }
      return p;
    }
    if (p.category) namesWithoutId.add(p.category);
    return p;
  });

  const categories = Array.from(
    new Set<string>([...canonicalNameById.values(), ...namesWithoutId]),
  ).sort();

  return { products: normalized, categories };
}

export function hasModule(config: CompanyConfig | null, moduleName: string): boolean {
  return config?.all_enabled_modules?.includes(moduleName) ?? false;
}

/**
 * Menu-tenant API fetch — verbatim from pre-T2.1.
 *
 * The Menu-mode catalog is published as a hierarchical menu structure
 * (categories + items + modifiers) rather than the flat /products
 * endpoint. T2.1 Step A explicitly does NOT touch this branch — the
 * Menu-tenant catalog desync (audit finding C2) is a separate session.
 */
async function fetchMenuProductsFromAPI(): Promise<POSProduct[]> {
  const menu = await fetchActiveMenu();
  return flattenMenuToProducts(menu);
}

/**
 * Standard-retail API fetch — legacy shape preserved for callers that
 * lack a `companyId` / SQLite handle (defensive fallback only). The
 * normal cold-start path uses `pullProductsForeground` instead — see
 * the non-Menu branch in `fetchProducts` below.
 */
async function fetchStandardProductsFromAPILegacy(): Promise<POSProduct[]> {
  return fetchPOSProducts({ limit: 500 });
}

let syncInFlight = false;

export const useProductStore = create<ProductStore>()((set, get) => ({
  ...initialState,

  fetchProducts: async (_force = false) => {
    const { isLoading } = get();
    if (isLoading) return;

    set({ isLoading: true, error: null });

    const companyId = useAuthStore.getState().companyId;

    // Step 1: Load from SQLite immediately (instant render)
    let hasLocalData = false;
    if (companyId) {
      try {
        const db = await getDatabase(companyId);
        const cachedProducts = await getAllProducts(db);
        if (cachedProducts.length > 0) {
          hasLocalData = true;
          // Codex review (PR #109 round 2, P2): the SQLite hydration
          // path also needs the canonical-name rewrite. Cached rows
          // can sit in SQLite with the pre-rename label after a
          // failed/pending sync; without normalization here the
          // ProductGrid filter hides the renamed rows behind the
          // canonical tab during the cache-only window before any
          // successful API refresh.
          const canonical = canonicalizeMenuCatalog(cachedProducts);
          // Codex round-4 P2 (PR #98) — invalidate the scan LRU when
          // the initial SQLite hydration replaces in-memory products.
          // SQLite can be ahead of the in-memory snapshot if a sync
          // tick wrote rows but a cache entry from before that tick
          // is still present (e.g. fetchProducts called from a
          // remounting route after a sync, before the next
          // refreshFromSQLite path runs). Clearing here means scans
          // can't resolve to a Tier 0 entry that pre-dates the
          // fresher SQLite catalog.
          const currentInMemory = get().products;
          if (diffProducts(currentInMemory, canonical.products).changed
              || currentInMemory.length === 0) {
            clearScanCache();
          }
          set({
            products: canonical.products,
            categories: canonical.categories,
            isLoading: false,
          });
        }
      } catch {
        // SQLite read failed — continue to API
      }
    }

    // Step 2: Fetch company config if not cached
    let config = get().companyConfig;
    if (!config) {
      try {
        config = await fetchCompanyConfig();
        set({ companyConfig: config });
      } catch {
        config = null;
      }
    }

    // Step 3: Fetch from API.
    //
    // Two paths after T2.1 Step A:
    //   - Menu-tenant: legacy menu-flatten path (verbatim). Catalog
    //     truthfulness for Menu-mode tenants is tracked separately
    //     (audit finding C2 — Otospex go-live coordination).
    //   - Non-Menu (standard-retail): paginated full pull via
    //     `pullProductsForeground`, then refresh the in-memory store
    //     from SQLite. Closes the 500-cap cold-start gap that left
    //     the cashier seeing only ~10% of a 5000-SKU catalog.
    const isMenuTenant = hasModule(config, 'Menu');

    const doMenuApiFetch = async () => {
      try {
        const freshProducts = await fetchMenuProductsFromAPI();

        // Reconcile the local SQLite catalog. Post-C2 Day 1, Menu-tenant
        // rows write at the composite primary key
        // `${sellable_id}_${menu_category_id}`. The reconciler at
        // `productRepository.reconcileMenuProducts` handles all four
        // codex review closures uniformly:
        //   - success-empty (r4 P2) ⇒ wipeAllProductRows.
        //   - success-non-empty ⇒ upsertProducts + wipeAllBareRows
        //     (r5 P2) + pruneStaleCompositeRows (r2 P2).
        // The same helper is also called from `runFullSync` post-
        // pullActiveMenu (r6 P1) so background sync ticks update the
        // grid for already-running cashier sessions.
        if (companyId) {
          try {
            const db = await getDatabase(companyId);
            await reconcileMenuProducts(db, freshProducts);
          } catch {
            // SQLite reconcile failed silently — not critical
          }
        }

        // Codex review (PR #109 round 4, P2) — canonicalize BEFORE
        // diffing. In-memory `currentProducts` is already canonical;
        // diffing against raw fresh labels would mark every Menu row
        // with a non-canonical category as changed on each tick,
        // re-clearing the scan cache and re-setting the store for no
        // real change.
        const canonicalFresh = canonicalizeMenuCatalog(freshProducts);
        const currentProducts = get().products;
        const { changed, products: merged } = diffProducts(currentProducts, canonicalFresh.products);
        if (changed || currentProducts.length === 0) {
          // Codex round-1 P2 (PR #98) — drop scan LRU when the
          // Menu-mode fetch updates in-memory state.
          clearScanCache();
          set({
            products: merged,
            categories: canonicalFresh.categories,
            lastFetched: Date.now(),
          });
        } else {
          set({ lastFetched: Date.now() });
        }

        if (get().isLoading) {
          set({ isLoading: false });
        }
      } catch (error) {
        if (!hasLocalData) {
          set({
            isLoading: false,
            error: error instanceof Error ? error.message : i18n.t('errors.unexpected', { ns: 'pos' }),
          });
        } else if (get().isLoading) {
          set({ isLoading: false });
        }
      }
    };

    const doStandardForegroundPull = async () => {
      // Defensive: if we somehow got here without a companyId, fall
      // back to the legacy 500-cap helper so the cashier sees SOMETHING.
      // The standard cold-start path always has a companyId by the
      // time productStore.fetchProducts runs.
      if (!companyId) {
        try {
          const freshProducts = await fetchStandardProductsFromAPILegacy();
          // Canonicalize before diff (Codex r4 P2). Standard catalogs
          // carry no menu_category_id so this is effectively a no-op
          // for them, but keeping the shape symmetric across paths
          // avoids future drift.
          const canonicalFresh = canonicalizeMenuCatalog(freshProducts);
          const currentProducts = get().products;
          const { changed, products: merged } = diffProducts(currentProducts, canonicalFresh.products);
          if (changed || currentProducts.length === 0) {
            set({
              products: merged,
              categories: canonicalFresh.categories,
              lastFetched: Date.now(),
            });
          } else {
            set({ lastFetched: Date.now() });
          }
          if (get().isLoading) set({ isLoading: false });
        } catch (error) {
          if (!hasLocalData) {
            set({
              isLoading: false,
              error: error instanceof Error ? error.message : i18n.t('errors.unexpected', { ns: 'pos' }),
            });
          } else if (get().isLoading) {
            set({ isLoading: false });
          }
        }
        return;
      }

      try {
        const db = await getDatabase(companyId);
        await pullProductsForeground(db, { timeoutMs: FOREGROUND_PULL_TIMEOUT_MS });
        // Read the now-complete catalog directly from SQLite. We
        // bypass `refreshFromSQLite` here because that helper returns
        // early when SQLite has zero rows — which is wrong for the
        // tombstone-driven catalog-wipe case (server-side wipe of all
        // products → pull tombstones the SQLite rows → in-memory still
        // has the stale set unless we explicitly clear it).
        //
        // T2.1 Step A Codex round-1 BLOCKER fix: handle the empty-
        // SQLite-post-pull case by clearing in-memory state too.
        const freshProducts = await getAllProducts(db);
        const currentProducts = get().products;
        if (freshProducts.length === 0) {
          if (currentProducts.length > 0) {
            // Codex round-1 P2 (PR #98) — drop scan LRU on
            // tombstone-driven catalog wipe.
            clearScanCache();
            set({ products: [], categories: [] });
          }
        } else {
          // Canonicalize before diff (Codex r4 P2).
          const canonicalFresh = canonicalizeMenuCatalog(freshProducts);
          const { changed, products: merged } = diffProducts(currentProducts, canonicalFresh.products);
          if (changed || currentProducts.length === 0) {
            // Codex round-1 P2 (PR #98) — drop scan LRU when the
            // foreground pull updates in-memory state.
            clearScanCache();
            set({
              products: merged,
              categories: canonicalFresh.categories,
            });
          }
        }
        set({ lastFetched: Date.now() });
        if (get().isLoading) set({ isLoading: false });
      } catch (error) {
        if (!hasLocalData) {
          set({
            isLoading: false,
            error: error instanceof Error ? error.message : i18n.t('errors.unexpected', { ns: 'pos' }),
          });
        } else if (get().isLoading) {
          set({ isLoading: false });
        }
      }
    };

    const doApiFetch = isMenuTenant ? doMenuApiFetch : doStandardForegroundPull;

    if (hasLocalData) {
      // Non-blocking: fire and forget the API fetch (guard against concurrent syncs)
      if (!syncInFlight) {
        syncInFlight = true;
        doApiFetch().finally(() => { syncInFlight = false; });
      }
    } else {
      // First launch (empty SQLite): await the API call
      await doApiFetch();
    }
  },

  refreshFromSQLite: async () => {
    try {
      const companyId = useAuthStore.getState().companyId;
      if (!companyId) return;
      const db = await getDatabase(companyId);
      const freshProducts = await getAllProducts(db);
      // Codex review (PR #107 round 7 P2): we used to early-return on an
      // empty SQLite result as a defensive guard against transient empty
      // reads. Post-C2 Day 1 (round-6 fix) the background pullActiveMenu
      // path can authoritatively wipe the products table when /active-menu
      // returns empty, and refreshFromSQLite is the channel through which
      // the cashier's in-memory grid catches up. Skipping the empty case
      // would leave the cashier selling from a stale in-memory snapshot
      // until app restart. Let `diffProducts` produce
      // `{ changed: true, products: [] }` when current is non-empty and
      // fresh is empty, and let the `if (changed)` branch clear in-memory
      // state along with the scan LRU.
      // Codex review (PR #109 round 4, P2) — canonicalize the SQLite
      // result BEFORE diffing. The in-memory snapshot is already
      // canonical; diffing raw mixed-label SQLite rows against it
      // would mark every Menu row with a non-canonical label as
      // changed on every scheduler tick, churning the scan cache and
      // re-setting the store for no real change.
      const canonicalFresh = canonicalizeMenuCatalog(freshProducts);
      const { changed, products: merged } = diffProducts(get().products, canonicalFresh.products);
      if (changed) {
        // Codex round-1 P2 (PR #98) — invalidate the recent-scan LRU
        // when the in-memory catalog actually changes. Without this,
        // a deleted / updated product keeps resolving via Tier 0 to
        // its stale cached state until app restart or LRU eviction.
        // Clearing before `set()` means any concurrent resolver call
        // sees a cold cache and falls through to the fresh in-memory
        // + SQLite state.
        clearScanCache();
        set({ products: merged, categories: canonicalFresh.categories });
      }
    } catch (error) {
      console.error('[productStore] refreshFromSQLite failed:', error);
    }
  },

  getById: (id: string) => {
    return get().products.find((p) => p.id === id);
  },

  reset: () => {
    // Codex round-2 P2 (PR #98) defense-in-depth — also clear the
    // tenant-scoped scan LRU. The cache key already includes the
    // companyId (so cross-tenant collisions are impossible), but
    // reset() is the canonical "this session is over" signal (called
    // from logout in Header / PinEntryPage), so dropping the cache
    // here keeps the process clean across rapid session churn even
    // before the LRU's natural eviction window catches up.
    clearScanCache();
    set(initialState);
  },
}));
