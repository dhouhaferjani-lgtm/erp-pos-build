import { useState, useMemo, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { Search, X, Package, LayoutGrid, Image } from 'lucide-react';
import { useCurrency } from '@/lib/currency';
import { ProductCard } from '@/components/molecules/ProductCard';
import type { POSProduct } from '@/types/product';

type DisplayMode = 'grid' | 'visual';

const DISPLAY_MODE_STORAGE_KEY = 'pos-display-mode';

function getStoredDisplayMode(): DisplayMode {
  const stored = localStorage.getItem(DISPLAY_MODE_STORAGE_KEY);
  if (stored === 'grid' || stored === 'visual') return stored;
  return 'grid';
}

export interface ProductGridProps {
  products: POSProduct[];
  categories: string[];
  onAddToCart: (product: POSProduct) => void;
  onCustomize?: (product: POSProduct) => void;
  cartProductIds: string[];
  isLoading?: boolean;
}

const POPULAR_COUNT = 8;

export function ProductGrid({
  products,
  categories,
  onAddToCart,
  onCustomize,
  cartProductIds,
  isLoading = false,
}: ProductGridProps) {
  const { t } = useTranslation('pos');
  const { format } = useCurrency();
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
  const [displayMode, setDisplayMode] = useState<DisplayMode>(getStoredDisplayMode);
  const [inStockOnly, setInStockOnly] = useState(false);

  const allCategoriesLabel = t('products.allCategories');

  const handleDisplayModeChange = useCallback((mode: DisplayMode) => {
    setDisplayMode(mode);
    localStorage.setItem(DISPLAY_MODE_STORAGE_KEY, mode);
  }, []);

  // Products sorted by position/name
  const sortedProducts = useMemo(() => {
    return [...products].sort((a, b) => {
      if (a.position !== undefined && b.position !== undefined) {
        return a.position - b.position;
      }
      if (a.position !== undefined) return -1;
      if (b.position !== undefined) return 1;
      return a.name.localeCompare(b.name);
    });
  }, [products]);

  // Popular items (first N by position)
  const popularProducts = useMemo(() => {
    return sortedProducts.filter((p) => p.stock_quantity > 0).slice(0, POPULAR_COUNT);
  }, [sortedProducts]);

  // Category product counts
  const categoryCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    for (const p of products) {
      if (p.category) {
        counts[p.category] = (counts[p.category] ?? 0) + 1;
      }
    }
    return counts;
  }, [products]);

  const filteredProducts = useMemo(() => {
    let filtered = sortedProducts;

    if (selectedCategory) {
      filtered = filtered.filter((p) => p.category === selectedCategory);
    }

    if (inStockOnly) {
      filtered = filtered.filter((p) => p.stock_quantity > 0);
    }

    if (searchQuery.trim()) {
      const query = searchQuery.toLowerCase();
      filtered = filtered.filter(
        (p) =>
          p.name.toLowerCase().includes(query) ||
          p.sku.toLowerCase().includes(query) ||
          (p.barcode && p.barcode.toLowerCase().includes(query)),
      );
    }

    return filtered;
  }, [sortedProducts, selectedCategory, searchQuery, inStockOnly]);

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <div className="mx-auto h-12 w-12 animate-spin rounded-full border-b-2 border-primary-600" />
          <p className="mt-4 text-gray-600">{t('products.loading')}</p>
        </div>
      </div>
    );
  }

  if (products.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <Package className="mx-auto mb-4 h-16 w-16 text-gray-400" />
          <p className="text-lg text-gray-600">{t('products.empty')}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col gap-2">
      {/* Search bar + display mode toggle */}
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder={t('products.searchPlaceholder')}
            className="w-full rounded-lg border border-gray-300 py-2 pl-10 pr-10 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
          />
          {searchQuery && (
            <button
              onClick={() => setSearchQuery('')}
              className="absolute right-3 top-1/2 -translate-y-1/2"
              aria-label={t('products.clearSearch')}
            >
              <X className="h-5 w-5 text-gray-400 hover:text-gray-600" />
            </button>
          )}
        </div>

        {/* Display mode toggle */}
        <div className="flex rounded-lg border border-gray-300 bg-white">
          <button
            onClick={() => handleDisplayModeChange('grid')}
            className={cn(
              'flex h-12 w-12 items-center justify-center rounded-l-lg transition-colors',
              displayMode === 'grid'
                ? 'bg-primary-600 text-white'
                : 'text-gray-500 hover:bg-gray-100',
            )}
            title={t('display.gridMode')}
          >
            <LayoutGrid className="h-5 w-5" />
          </button>
          <button
            onClick={() => handleDisplayModeChange('visual')}
            className={cn(
              'flex h-12 w-12 items-center justify-center rounded-r-lg transition-colors',
              displayMode === 'visual'
                ? 'bg-primary-600 text-white'
                : 'text-gray-500 hover:bg-gray-100',
            )}
            title={t('display.visualMode')}
          >
            <Image className="h-5 w-5" />
          </button>
        </div>
      </div>

      {/* Category tabs + In Stock filter */}
      {categories.length > 0 && (
        <div className="flex items-center gap-2 overflow-x-auto pb-1">
          <button
            onClick={() => setSelectedCategory(null)}
            className={cn(
              'whitespace-nowrap rounded-full px-5 py-3 text-base font-medium transition-colors',
              'min-h-[48px]',
              selectedCategory === null
                ? 'bg-primary-600 text-white'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
            )}
          >
            {allCategoriesLabel}
            <span className="ml-1.5 text-xs opacity-70">({products.length})</span>
          </button>
          {categories.map((category) => (
            <button
              key={category}
              onClick={() => setSelectedCategory(category)}
              className={cn(
                'whitespace-nowrap rounded-full px-5 py-3 text-base font-medium transition-colors',
                'min-h-[48px]',
                selectedCategory === category
                  ? 'bg-primary-600 text-white'
                  : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
              )}
            >
              {category}
              {categoryCounts[category] !== undefined && (
                <span className="ml-1.5 text-xs opacity-70">({categoryCounts[category]})</span>
              )}
            </button>
          ))}

          {/* In Stock Only toggle */}
          <button
            onClick={() => setInStockOnly((prev) => !prev)}
            className={cn(
              'ml-auto whitespace-nowrap rounded-full px-5 py-3 text-base font-medium transition-colors',
              'min-h-[48px]',
              inStockOnly
                ? 'bg-green-600 text-white'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200',
            )}
          >
            {t('display.inStockOnly')}
          </button>
        </div>
      )}

      {/* Popular items row (only shown in "All" category, no search query) */}
      {!selectedCategory && !searchQuery && popularProducts.length > 0 && (
        <div>
          <h3 className="mb-2 text-sm font-semibold text-gray-500 uppercase tracking-wide">
            {t('products.popular')}
          </h3>
          <div className="flex gap-2 overflow-x-auto pb-2">
            {popularProducts.map((product) => (
              <button
                key={`popular-${product.id}`}
                onClick={() => onAddToCart(product)}
                className={cn(
                  'flex shrink-0 items-center gap-2 rounded-full border-2 px-4 py-2 text-sm font-medium transition-all',
                  'min-h-[44px] active:scale-[0.95]',
                  cartProductIds.includes(product.id)
                    ? 'border-primary-500 bg-primary-50 text-primary-700'
                    : 'border-gray-200 bg-white text-gray-800 hover:border-primary-300 hover:shadow-sm',
                )}
              >
                <span className="max-w-[120px] truncate">{product.name}</span>
                <span className="font-bold text-primary-600">{format(product.sale_price ?? '0')}</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Grid */}
      {filteredProducts.length === 0 ? (
        <div className="flex flex-1 items-center justify-center">
          <div className="text-center">
            <Package className="mx-auto mb-4 h-16 w-16 text-gray-400" />
            <p className="text-lg text-gray-600">{t('products.notFound')}</p>
            <p className="mt-2 text-sm text-gray-500">
              {t('products.tryAdjusting')}
            </p>
          </div>
        </div>
      ) : (
        <div
          className={cn(
            'grid gap-3 overflow-y-auto',
            displayMode === 'grid'
              ? 'grid-cols-3 sm:grid-cols-4 lg:grid-cols-5'
              : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
          )}
        >
          {filteredProducts.map((product) => (
            <ProductCard
              key={product.id}
              product={product}
              onAddToCart={onAddToCart}
              onCustomize={onCustomize}
              isInCart={cartProductIds.includes(product.id)}
              displayMode={displayMode}
            />
          ))}
        </div>
      )}
    </div>
  );
}
