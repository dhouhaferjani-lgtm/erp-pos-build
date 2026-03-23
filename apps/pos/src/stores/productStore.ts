import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPOSProducts, fetchCompanyConfig, fetchActiveMenu, flattenMenuToProducts } from '@/api/productApi';
import { getDatabase } from '@/lib/db';
import { getAllProducts, upsertProducts } from '@/lib/db/repositories/productRepository';
import { useAuthStore } from '@/stores/authStore';
import { diffProducts } from '@/lib/sync/productDiff';
import type { POSProduct } from '@/types/product';
import type { CompanyConfig } from '@/types/companyConfig';

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

async function fetchProductsFromAPI(config: CompanyConfig | null): Promise<POSProduct[]> {
  if (hasModule(config, 'Menu')) {
    const menu = await fetchActiveMenu();
    return flattenMenuToProducts(menu);
  }
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

    // Step 3: Fetch from API in parallel (background if we have local data)
    const doApiFetch = async () => {
      try {
        const freshProducts = await fetchProductsFromAPI(config);

        // Upsert to SQLite
        if (companyId) {
          try {
            const db = await getDatabase(companyId);
            await upsertProducts(db, freshProducts);
          } catch {
            // SQLite upsert failed silently — not critical
          }
        }

        // Diff against in-memory products to minimize re-renders
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

        // Clear loading if still set (first launch with no local data)
        if (get().isLoading) {
          set({ isLoading: false });
        }
      } catch (error) {
        // API failed — if we already have local data, silently continue
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
      if (freshProducts.length === 0) return;
      const { changed, products: merged } = diffProducts(get().products, freshProducts);
      if (changed) {
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
    set(initialState);
  },
}));
