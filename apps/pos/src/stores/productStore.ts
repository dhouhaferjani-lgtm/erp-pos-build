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

function extractCategories(products: POSProduct[]): string[] {
  const uniqueCategories = new Set<string>();
  for (const p of products) {
    if (p.category) {
      uniqueCategories.add(p.category);
    }
  }
  return Array.from(uniqueCategories).sort();
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
          const categories = extractCategories(cachedProducts);
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
          if (diffProducts(currentInMemory, cachedProducts).changed
              || currentInMemory.length === 0) {
            clearScanCache();
          }
          set({
            products: cachedProducts,
            categories,
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

        const currentProducts = get().products;
        const { changed, products: merged } = diffProducts(currentProducts, freshProducts);
        if (changed || currentProducts.length === 0) {
          // Codex round-1 P2 (PR #98) — drop scan LRU when the
          // Menu-mode fetch updates in-memory state.
          clearScanCache();
          set({
            products: merged,
            categories: extractCategories(merged),
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
          const currentProducts = get().products;
          const { changed, products: merged } = diffProducts(currentProducts, freshProducts);
          if (changed || currentProducts.length === 0) {
            set({
              products: merged,
              categories: extractCategories(merged),
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
          const { changed, products: merged } = diffProducts(currentProducts, freshProducts);
          if (changed || currentProducts.length === 0) {
            // Codex round-1 P2 (PR #98) — drop scan LRU when the
            // foreground pull updates in-memory state.
            clearScanCache();
            set({
              products: merged,
              categories: extractCategories(merged),
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
      const { changed, products: merged } = diffProducts(get().products, freshProducts);
      if (changed) {
        // Codex round-1 P2 (PR #98) — invalidate the recent-scan LRU
        // when the in-memory catalog actually changes. Without this,
        // a deleted / updated product keeps resolving via Tier 0 to
        // its stale cached state until app restart or LRU eviction.
        // Clearing before `set()` means any concurrent resolver call
        // sees a cold cache and falls through to the fresh in-memory
        // + SQLite state.
        clearScanCache();
        set({ products: merged, categories: extractCategories(merged) });
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
