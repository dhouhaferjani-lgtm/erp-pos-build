import { useState, useMemo, useCallback, useRef, useEffect } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useVirtualizer } from '@tanstack/react-virtual';
import { cn } from '@/lib/utils';
import { Search, X, Package, LayoutGrid, Image, TrendingUp } from 'lucide-react';
import { tokens } from '@/lib/designTokens';
import { ProductCard } from '@/components/molecules/ProductCard';
import type { POSProduct } from '@/types/product';
import type { GridLocationStockMap } from '@/lib/stock/gridStock';
import { useAuthStore } from '@/stores/authStore';
import { useMostSoldCounts } from '@/hooks/useMostSoldCounts';
import { bccomp } from '@/lib/decimal';
import {
  CARD_MIN_H_GRID,
  CARD_MIN_H_VISUAL,
  GAP,
} from '@/components/molecules/ProductCard/cardSizing';

type DisplayMode = 'grid' | 'visual';
type SortMode = 'default' | 'mostSold';

const DISPLAY_MODE_STORAGE_KEY = 'pos-display-mode';

/**
 * Stable default for the `locationStock` prop — an inline `{}` default would
 * be a brand-new object every render, invalidating the `filteredProducts`
 * memo (which depends on it) on each pass.
 */
const EMPTY_LOCATION_STOCK: GridLocationStockMap = {};

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
  /**
   * Task 12 — per-tile location-stock display map
   * (`productStore.locationStock`, passed down by HomePage). `{}` for Menu
   * tenants / browser dev → every lookup is undefined → legacy tile
   * rendering verbatim.
   */
  locationStock?: GridLocationStockMap;
  /** Threads to every ProductCard — see its prop doc. Default true. */
  hardBlockOutOfStock?: boolean;
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
  locationStock = EMPTY_LOCATION_STOCK,
  hardBlockOutOfStock = true,
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

  /**
   * Sellable-first guard. Out-of-stock items (`stock_quantity <= 0`) must
   * never sort above a sellable one, regardless of the active sort mode
   * (incl. "most sold"). Returns a negative number when `a` should come
   * first, positive when `b` should, or 0 when both share the same
   * sellable bucket (delegating to the per-mode comparator). Uses the
   * location-stock slice when present (Task 12 parity with `inStockOnly`);
   * `null` slices (services) are always treated as sellable.
   */
  const isOutOfStock = useCallback(
    (p: POSProduct): boolean => {
      const slice = locationStock[p.id];
      if (slice === null) return false;
      if (slice !== undefined) return bccomp(slice.available, '0') <= 0;
      return p.stock_quantity <= 0;
    },
    [locationStock],
  );

  // Products sorted by position/name or by most-sold, with out-of-stock
  // items pushed to the end as a stable secondary sort.
  const sortedProducts = useMemo(() => {
    const base = [...products];
    const sellableFirst = (
      a: POSProduct,
      b: POSProduct,
      tiebreak: () => number,
    ): number => {
      const aOut = isOutOfStock(a);
      const bOut = isOutOfStock(b);
      if (aOut !== bOut) return aOut ? 1 : -1;
      return tiebreak();
    };

    if (sortMode === 'mostSold') {
      return base.sort((a, b) =>
        sellableFirst(a, b, () => {
          const ca = salesCounts.get(a.id) ?? 0;
          const cb = salesCounts.get(b.id) ?? 0;
          if (cb !== ca) return cb - ca;
          return a.name.localeCompare(b.name);
        }),
      );
    }
    return base.sort((a, b) =>
      sellableFirst(a, b, () => {
        if (a.position !== undefined && b.position !== undefined) {
          return a.position - b.position;
        }
        if (a.position !== undefined) return -1;
        if (b.position !== undefined) return 1;
        return a.name.localeCompare(b.name);
      }),
    );
  }, [products, sortMode, salesCounts, isOutOfStock]);

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
      // Task 12 — when the location-stock slice exists it wins over the
      // legacy stock_quantity; null = exempt (services) → always "in stock";
      // undefined (Menu tenants / browser dev) → legacy behaviour verbatim.
      filtered = filtered.filter((p) => {
        const slice = locationStock[p.id];
        if (slice === null) return true;
        if (slice !== undefined) return bccomp(slice.available, '0') > 0;
        return p.stock_quantity > 0;
      });
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
  }, [sortedProducts, selectedCategory, searchQuery, inStockOnly, locationStock]);

  const columns = useMemo(() => getColumns(displayMode), [displayMode]);
  const rowCount = Math.ceil(filteredProducts.length / columns);
  const rowHeight = displayMode === 'grid' ? CARD_MIN_H_GRID : CARD_MIN_H_VISUAL;

  const virtualizer = useVirtualizer({
    count: rowCount,
    getScrollElement: () => scrollContainerRef.current,
    estimateSize: () => rowHeight + GAP,
    overscan: 3,
  });

  /**
   * T2.1 Step C — selectedCategory invalidation.
   *
   * Watches the SET of distinct categories derived from `products`.
   * If a sync tick (or a stale `categories` prop) drops the currently-
   * selected category, reset to null (= "all categories"). This fixes
   * the "tap a category that no longer exists silently produces an
   * empty grid" symptom (audit C3).
   *
   * Effect-loop avoidance: uses a `useRef` to remember the previous
   * category-set HASH (sorted-string-join). The reset only fires when
   * the set actually changes; identical category sets across renders
   * (e.g. same products + same categories) are no-ops.
   */
  const previousCategorySetHash = useRef<string>('');
  useEffect(() => {
    const currentCategorySet = new Set<string>();
    for (const p of products) {
      if (p.category) currentCategorySet.add(p.category);
    }
    const currentHash = Array.from(currentCategorySet).sort().join('|');
    if (currentHash !== previousCategorySetHash.current) {
      previousCategorySetHash.current = currentHash;
      if (selectedCategory && !currentCategorySet.has(selectedCategory)) {
        setSelectedCategory(null);
      }
    }
  }, [products, selectedCategory]);

  /**
   * T2.1 Step C — virtualizer composite-resetKey effect.
   *
   * Fixes the stale-window symptom (audit C4 + Codex round-1 m1):
   * `useVirtualizer` keeps its scroll range and measure cache across
   * `products[]` changes. After a category switch — or a sync tick
   * that swaps row identity at the same length — the virtualizer can
   * render a stale window (empty rows where products should be).
   *
   * The composite resetKey includes:
   *   - displayMode | columns | rowHeight (UI variables)
   *   - selectedCategory | searchQuery (filter variables)
   *   - filteredProducts.length | firstId | lastId (identity boundary)
   *
   * Length-only is insufficient — same-length-different-shape
   * transitions would slip through. The first/last id pair catches
   * this cheaply without hashing the full array.
   *
   * Empty-state branch: when `filteredProducts.length === 0`, the
   * empty-state UI mounts and the virtualizer scroll container
   * unmounts. `scrollToIndex(0)` on a zero-row virtualizer is a no-op
   * but avoid the call defensively to keep the spy assertions clean.
   */
  const resetKey = useMemo(() => {
    const firstId = filteredProducts[0]?.id ?? '';
    const lastId = filteredProducts[filteredProducts.length - 1]?.id ?? '';
    return [
      displayMode,
      String(columns),
      String(rowHeight),
      selectedCategory ?? '',
      searchQuery,
      String(filteredProducts.length),
      firstId,
      lastId,
    ].join('|');
  }, [displayMode, columns, rowHeight, selectedCategory, searchQuery, filteredProducts]);

  useEffect(() => {
    if (filteredProducts.length === 0) return;
    virtualizer.scrollToIndex(0);
    virtualizer.measure();
    // virtualizer is intentionally omitted — it's a new instance every
    // render; the resetKey captures the change-trigger we care about.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resetKey]);

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <div className="mx-auto h-12 w-12 animate-spin rounded-full border-b-2 border-action" />
          <p className="mt-4 text-ink-muted">{t('products.loading')}</p>
        </div>
      </div>
    );
  }

  if (products.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-center">
          <Package className="mx-auto mb-4 h-16 w-16 text-ink-faint" />
          <p className="text-lg text-ink-muted">{t('products.empty')}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col gap-2">
      {/* Search bar + sort toggle + display mode toggle — one control voice */}
      <div className="flex items-center gap-2">
        {consumptionModeToggle}
        <div className="relative min-w-0 flex-1">
          <Search className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-faint" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder={t('products.searchPlaceholder')}
            className="w-full rounded-lg border border-border-subtle bg-surface-raised py-2 pl-10 pr-10 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-2 focus:ring-action"
          />
          {searchQuery && (
            <button
              onClick={() => setSearchQuery('')}
              className="absolute right-3 top-1/2 -translate-y-1/2"
              aria-label={t('products.clearSearch')}
            >
              <X className="h-5 w-5 text-ink-faint hover:text-ink-muted" />
            </button>
          )}
        </div>

        {/* Most-sold sort toggle. Styled as a secondary button (consistent
            with the toolbar voice); the active/pressed state is signalled by
            a soft action-subtle fill + action text, not a saturated block.
            `aria-pressed` reflects state; `title` gives a click-to-undo hint. */}
        <button
          onClick={() => setSortMode((m) => (m === 'mostSold' ? 'default' : 'mostSold'))}
          className={cn(
            tokens.button.secondary,
            'h-12',
            sortMode === 'mostSold' &&
              'border-action bg-action-subtle text-action-strong hover:bg-action-subtle',
          )}
          title={sortMode === 'mostSold' ? t('products.sortDefault') : undefined}
          aria-pressed={sortMode === 'mostSold'}
        >
          <TrendingUp className="h-5 w-5" />
          {t('products.sortByMostSold')}
        </button>

        {/* Display mode toggle — the ONE segmented-control voice. */}
        <div className={tokens.segmented.root}>
          <button
            onClick={() => handleDisplayModeChange('grid')}
            className={cn(
              tokens.segmented.item,
              'flex h-10 w-10 items-center justify-center',
              displayMode === 'grid'
                ? tokens.segmented.itemActive
                : tokens.segmented.itemInactive,
            )}
            title={t('display.gridMode')}
            aria-pressed={displayMode === 'grid'}
          >
            <LayoutGrid className="h-5 w-5" />
          </button>
          <button
            onClick={() => handleDisplayModeChange('visual')}
            className={cn(
              tokens.segmented.item,
              'flex h-10 w-10 items-center justify-center',
              displayMode === 'visual'
                ? tokens.segmented.itemActive
                : tokens.segmented.itemInactive,
            )}
            title={t('display.visualMode')}
            aria-pressed={displayMode === 'visual'}
          >
            <Image className="h-5 w-5" />
          </button>
        </div>
      </div>

      {/* Category tabs + In Stock filter */}
      {categories.length > 0 && (
        <div className="scrollbar-none flex items-center gap-2 overflow-x-auto pb-1">
          <button
            onClick={() => setSelectedCategory(null)}
            className={cn(
              'whitespace-nowrap rounded-full px-5 py-3 text-base font-medium transition-colors',
              'min-h-[48px]',
              selectedCategory === null
                ? 'bg-action text-ink-inverse'
                : 'bg-surface-sunken text-ink-muted hover:bg-surface-canvas',
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
                  ? 'bg-action text-ink-inverse'
                  : 'bg-surface-sunken text-ink-muted hover:bg-surface-canvas',
              )}
            >
              {category}
              {categoryCounts[category] !== undefined && (
                <span className="ml-1.5 text-xs opacity-70">({categoryCounts[category]})</span>
              )}
            </button>
          ))}

          {/* In Stock Only toggle — a filter (interactive), so it adopts the
              action color when active, NOT success-green (green is reserved
              for confirmed money/sync events). */}
          <button
            onClick={() => setInStockOnly((prev) => !prev)}
            className={cn(
              'ml-auto whitespace-nowrap rounded-full px-5 py-3 text-base font-medium transition-colors',
              'min-h-[48px]',
              inStockOnly
                ? 'bg-action text-ink-inverse'
                : 'bg-surface-sunken text-ink-muted hover:bg-surface-canvas',
            )}
            aria-pressed={inStockOnly}
          >
            {t('display.inStockOnly')}
          </button>
        </div>
      )}

      {/* Virtualized product grid */}
      {filteredProducts.length === 0 ? (
        <div className="flex flex-1 items-center justify-center">
          <div className="text-center">
            <Package className="mx-auto mb-4 h-16 w-16 text-ink-faint" />
            <p className="text-lg text-ink-muted">{t('products.notFound')}</p>
            <p className="mt-2 text-sm text-ink-faint">
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
                      locationStock={locationStock[product.id]}
                      hardBlockOutOfStock={hardBlockOutOfStock}
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
