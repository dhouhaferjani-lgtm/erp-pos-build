import { useState, useMemo, useCallback, useRef } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useVirtualizer } from '@tanstack/react-virtual';
import { cn } from '@/lib/utils';
import { Search, X, Package, LayoutGrid, Image, TrendingUp } from 'lucide-react';
import { ProductCard } from '@/components/molecules/ProductCard';
import type { POSProduct } from '@/types/product';
import { useAuthStore } from '@/stores/authStore';
import { useMostSoldCounts } from '@/hooks/useMostSoldCounts';
import {
  CARD_MIN_H_GRID,
  CARD_MIN_H_VISUAL,
  GAP,
} from '@/components/molecules/ProductCard/cardSizing';

type DisplayMode = 'grid' | 'visual';
type SortMode = 'default' | 'mostSold';

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
  consumptionModeToggle?: ReactNode;
}

/** Column counts per display mode. */
function getColumns(displayMode: DisplayMode): number {
  // Match the original CSS grid classes:
  // grid mode:   grid-cols-3 sm:grid-cols-4 lg:grid-cols-5
  // visual mode: grid-cols-2 sm:grid-cols-3 lg:grid-cols-4
  // We use a sensible default that works for typical POS screens (>= lg).
  if (typeof window === 'undefined') return displayMode === 'grid' ? 5 : 4;
  const w = window.innerWidth;
  if (displayMode === 'grid') {
    if (w >= 1024) return 5;
    if (w >= 640) return 4;
    return 3;
  }
  // visual
  if (w >= 1024) return 4;
  if (w >= 640) return 3;
  return 2;
}

export function ProductGrid({
  products,
  categories,
  onAddToCart,
  onCustomize,
  cartProductIds,
  isLoading = false,
  consumptionModeToggle,
}: ProductGridProps) {
  const { t } = useTranslation('pos');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
  const [displayMode, setDisplayMode] = useState<DisplayMode>(getStoredDisplayMode);
  const [inStockOnly, setInStockOnly] = useState(false);
  const [sortMode, setSortMode] = useState<SortMode>('default');

  const companyId = useAuthStore((s) => s.companyId);
  const { counts: salesCounts } = useMostSoldCounts({
    companyId,
    enabled: sortMode === 'mostSold',
  });

  const scrollContainerRef = useRef<HTMLDivElement>(null);

  const allCategoriesLabel = t('products.allCategories');

  const handleDisplayModeChange = useCallback((mode: DisplayMode) => {
    setDisplayMode(mode);
    localStorage.setItem(DISPLAY_MODE_STORAGE_KEY, mode);
  }, []);

  // Products sorted by position/name or by most-sold
  const sortedProducts = useMemo(() => {
    const base = [...products];
    if (sortMode === 'mostSold') {
      return base.sort((a, b) => {
        const ca = salesCounts.get(a.id) ?? 0;
        const cb = salesCounts.get(b.id) ?? 0;
        if (cb !== ca) return cb - ca;
        return a.name.localeCompare(b.name);
      });
    }
    return base.sort((a, b) => {
      if (a.position !== undefined && b.position !== undefined) {
        return a.position - b.position;
      }
      if (a.position !== undefined) return -1;
      if (b.position !== undefined) return 1;
      return a.name.localeCompare(b.name);
    });
  }, [products, sortMode, salesCounts]);

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

  const columns = useMemo(() => getColumns(displayMode), [displayMode]);
  const rowCount = Math.ceil(filteredProducts.length / columns);
  const rowHeight = displayMode === 'grid' ? CARD_MIN_H_GRID : CARD_MIN_H_VISUAL;

  const virtualizer = useVirtualizer({
    count: rowCount,
    getScrollElement: () => scrollContainerRef.current,
    estimateSize: () => rowHeight + GAP,
    overscan: 3,
  });

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
      {/* Search bar + sort toggle + display mode toggle */}
      <div className="flex items-center gap-2">
        {consumptionModeToggle}
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

        {/* Most-sold sort toggle: label stays constant; aria-pressed reflects state.
            `title` gives a click-to-undo hint when pressed. */}
        <button
          onClick={() => setSortMode((m) => (m === 'mostSold' ? 'default' : 'mostSold'))}
          className={cn(
            'flex h-12 items-center gap-2 rounded-lg border px-4 text-sm font-medium transition-colors',
            sortMode === 'mostSold'
              ? 'border-primary-500 bg-primary-50 text-primary-700'
              : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50',
          )}
          title={sortMode === 'mostSold' ? t('products.sortDefault') : undefined}
          aria-pressed={sortMode === 'mostSold'}
        >
          <TrendingUp className="h-5 w-5" />
          {t('products.sortByMostSold')}
        </button>

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

      {/* Virtualized product grid */}
      {filteredProducts.length === 0 ? (
        <div className="flex flex-1 items-center justify-center">
          <div className="text-center">
            <Package className="mx-auto mb-4 h-16 w-16 text-gray-400" />
            <p className="text-lg text-gray-600">{t('products.notFound')}</p>
            <p className="mt-2 text-sm text-gray-600">
              {t('products.tryAdjusting')}
            </p>
          </div>
        </div>
      ) : (
        <div
          ref={scrollContainerRef}
          data-testid="product-grid-scroll"
          className="flex-1 overflow-y-auto"
        >
          <div
            className="relative w-full"
            style={{ height: `${virtualizer.getTotalSize()}px` }}
          >
            {virtualizer.getVirtualItems().map((virtualRow) => {
              const startIdx = virtualRow.index * columns;
              const rowProducts = filteredProducts.slice(startIdx, startIdx + columns);

              return (
                <div
                  key={virtualRow.key}
                  className={cn(
                    'absolute left-0 top-0 grid w-full gap-3',
                    displayMode === 'grid'
                      ? 'grid-cols-3 sm:grid-cols-4 lg:grid-cols-5'
                      : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
                  )}
                  style={{
                    height: `${virtualRow.size}px`,
                    transform: `translateY(${virtualRow.start}px)`,
                  }}
                >
                  {rowProducts.map((product) => (
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
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
