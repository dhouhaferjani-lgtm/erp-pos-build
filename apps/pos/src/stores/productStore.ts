import { create } from 'zustand';
import i18n from '@/lib/i18n';
import { fetchPOSProducts, fetchCompanyConfig, fetchActiveMenu, flattenMenuToProducts } from '@/api/productApi';
import { getDatabase } from '@/lib/db';
import { getAllProducts, upsertProducts } from '@/lib/db/repositories/productRepository';
import { useAuthStore } from '@/stores/authStore';
import type { POSProduct } from '@/types/product';
import type { CompanyConfig } from '@/types/companyConfig';

const CACHE_DURATION_MS = 5 * 60 * 1000; // 5 minutes

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

export const useProductStore = create<ProductStore>()((set, get) => ({
  ...initialState,

  fetchProducts: async (force = false) => {
    const { lastFetched, isLoading } = get();
    if (isLoading) return;

    // Use cache if not forced and within cache duration
    if (!force && lastFetched && Date.now() - lastFetched < CACHE_DURATION_MS) {
      return;
    }

    set({ isLoading: true, error: null });

    try {
      // Fetch company config if not cached
      let config = get().companyConfig;
      if (!config) {
        try {
          config = await fetchCompanyConfig();
          set({ companyConfig: config });
        } catch {
          // Config fetch failed, proceed with retail mode
          config = null;
        }
      }

      let products: POSProduct[];

      if (hasModule(config, 'Menu')) {
        // F&B mode: fetch active menu with modifier groups
        const menu = await fetchActiveMenu();
        products = flattenMenuToProducts(menu);
      } else {
        // Retail mode: fetch products
        products = await fetchPOSProducts({ limit: 500 });
      }

      const categories = extractCategories(products);
      set({
        products,
        categories,
        isLoading: false,
        lastFetched: Date.now(),
      });

      // Upsert to SQLite for offline fallback
      try {
        const companyId = useAuthStore.getState().companyId;
        if (companyId) {
          const db = await getDatabase(companyId);
          await upsertProducts(db, products);
        }
      } catch {
        // SQLite upsert failed silently — not critical
      }
    } catch (error) {
      // API failed — try loading from SQLite cache
      try {
        const companyId = useAuthStore.getState().companyId;
        if (companyId) {
          const db = await getDatabase(companyId);
          const cachedProducts = await getAllProducts(db);
          if (cachedProducts.length > 0) {
            const categories = extractCategories(cachedProducts);
            set({
              products: cachedProducts,
              categories,
              isLoading: false,
              lastFetched: Date.now(),
            });
            return;
          }
        }
      } catch {
        // SQLite also failed
      }

      set({
        isLoading: false,
        error: error instanceof Error ? error.message : i18n.t('errors.unexpected', { ns: 'pos' }),
      });
    }
  },

  getById: (id: string) => {
    return get().products.find((p) => p.id === id);
  },

  reset: () => {
    set(initialState);
  },
}));
