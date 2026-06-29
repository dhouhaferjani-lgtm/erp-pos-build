import { useState, useMemo, useCallback, useRef, useEffect } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useVirtualizer } from '@tanstack/react-virtual';
import { cn } from '@/lib/utils';
import { Search, X, Package, LayoutGrid, Image, TrendingUp, SlidersHorizontal } from 'lucide-react';
import { Button, Pill, SegmentedControl } from '@/components/ui';
import type { SegmentedOption } from '@/components/ui';
import { ProductCard } from '@/components/molecules/ProductCard';
import type { POSProduct } from '@/types/product';
import type { GridLocationStockMap } from '@/lib/stock/gridStock';
import { useAuthStore } from '@/stores/authStore';
import { useSettingsStore } from '@/stores/settingsStore';
import { useMostSoldCounts } from '@/hooks/useMostSoldCounts';
import { bccomp } from '@/lib/decimal';
import {
  GAP,
  getColumns,
  getCardMinH,
} from '@/components/molecules/ProductCard/cardSizing';

type DisplayMode = 'grid' | 'visual';
type SortMode = 'default' | 'mostSold';

/**
 * Stable default for the `locationStock` prop — an inline `{}` default would
 * be a brand-new object every render, invalidating the `filteredProducts`
 * memo (which depends on it) on each pass.
 */
const EMPTY_LOCATION_STOCK: GridLocationStockMap = {};

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
  onViewDetails?: (product: POSProduct) => void;
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
  onViewDetails,
}: ProductGridProps) {
  const { t } = useTranslation('pos');

  // ---------------------------------------------------------------------------
  // settingsStore — single source of truth for displayMode and density
  // (Task 25: replaced localStorage dual-source with store subscription)
  // ---------------------------------------------------------------------------
  const displayMode = useSettingsStore((s) => s.displayMode);
  const density = useSettingsStore((s) => s.density);
  const setDisplayMode = useSettingsStore((s) => s.setDisplayMode);

  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
  const [inStockOnly, setInStockOnly] = useState(false);
  const [sortMode, setSortMode] = useState<SortMode>('default');

  const companyId = useAuthStore((s) => s.companyId);
  const { counts: salesCounts } = useMostSoldCounts({
    companyId,
    enabled: sortMode === 'mostSold',
  });

  const scrollContainerRef = useRef<HTMLDivElement>(null);

  const allCategoriesLabel = t('products.allCategories');

  // ---------------------------------------------------------------------------
  // Density-aware column count — recomputed on displayMode / density change and
  // on window resize so the virtualizer row math stays correct.
  // ---------------------------------------------------------------------------
  const [columns, setColumns] = useState<number>(() =>
    getColumns(displayMode, density, typeof window !== 'undefined' ? window.innerWidth : 1280),
  );

  useEffect(() => {
    const update = () =>
      setColumns(getColumns(displayMode, density, typeof window !== 'undefined' ? window.innerWidth : 1280));
    update();
    window.addEventListener('resize', update, { passive: true });
    return () => window.removeEventListener('resize', update);
  }, [displayMode, density]);

  const handleDisplayModeChange = useCallback(
    (mode: DisplayMode) => {
      setDisplayMode(mode);
    },
    [setDisplayMode],
  );

  const displayModeOptions = useMemo<SegmentedOption<DisplayMode>[]>(
    () => [
      {
        value: 'grid',
        icon: <LayoutGrid className="h-5 w-5" />,
        ariaLabel: t('display.gridMode'),
      },
      {
        value: 'visual',
        icon: <Image className="h-5 w-5" />,
        ariaLabel: t('display.visualMode'),
      },
    ],
    [t],
  );

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

  // ---------------------------------------------------------------------------
  // Active filter count — used for the Filtres badge.
  // Counts drawer-style filters (inStockOnly); category is shown in the Pill row.
  // ---------------------------------------------------------------------------
  const activeFilterCount = inStockOnly ? 1 : 0;

  const rowHeight = getCardMinH(displayMode, density);
  const rowCount = Math.ceil(filteredProducts.length / columns);

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
      {/* ------------------------------------------------------------------ */}
      {/* Toolbar: search · Filtres · Top ventes · view toggle               */}
      {/* ------------------------------------------------------------------ */}
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

        {/* Filtres — placeholder button; drawer is a later task (Task 26+).
            Badge shows the count of active drawer-style filters.             */}
        <Button
          variant="secondary"
          size="md"
          leftIcon={<SlidersHorizontal className="h-5 w-5" />}
          aria-label={t('products.filters')}
        >
          {t('products.filters')}
          {activeFilterCount > 0 && (
            <span className="ml-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-action text-xs font-semibold text-ink-inverse">
              {activeFilterCount}
            </span>
          )}
        </Button>

        {/* Most-sold sort toggle — secondary Button atom; active/pressed state
            signalled by a soft action-subtle fill, not a saturated block. */}
        <Button
          variant="secondary"
          size="md"
          leftIcon={<TrendingUp className="h-5 w-5" />}
          onClick={() => setSortMode((m) => (m === 'mostSold' ? 'default' : 'mostSold'))}
          className={cn(
            sortMode === 'mostSold' &&
              'border-action bg-action-subtle text-action-strong hover:bg-action-subtle',
          )}
          title={sortMode === 'mostSold' ? t('products.sortDefault') : undefined}
          aria-pressed={sortMode === 'mostSold'}
        >
          {t('products.sortByMostSold')}
        </Button>

        {/* Display mode toggle — the ONE segmented-control voice (atom). */}
        <SegmentedControl
          options={displayModeOptions}
          value={displayMode}
          onChange={handleDisplayModeChange}
          ariaLabel={t('display.mode')}
        />
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* Category Pill row + In Stock toggle                                 */}
      {/* ------------------------------------------------------------------ */}
      {categories.length > 0 && (
        <div className="scrollbar-none flex items-center gap-2 overflow-x-auto pb-1">
          <Pill
            selected={selectedCategory === null}
            onClick={() => setSelectedCategory(null)}
          >
            {allCategoriesLabel}
            <span className="ml-1.5 text-xs opacity-70">({products.length})</span>
          </Pill>

          {categories.map((category) => (
            <Pill
              key={category}
              selected={selectedCategory === category}
              onClick={() => setSelectedCategory(category)}
            >
              {category}
              {categoryCounts[category] !== undefined && (
                <span className="ml-1.5 text-xs opacity-70">({categoryCounts[category]})</span>
              )}
            </Pill>
          ))}

          {/* In Stock Only — currently in the Pill row; will migrate to the
              Filtres drawer in a later task. Contributes to activeFilterCount. */}
          <Pill
            selected={inStockOnly}
            onClick={() => setInStockOnly((prev) => !prev)}
            className="ml-auto"
          >
            {t('display.inStockOnly')}
          </Pill>
        </div>
      )}

      {/* Placeholder slot for active filter-chip row (Task 26+) */}
      {/* data-testid="filter-chip-row" reserved for the next task */}

      {/* ------------------------------------------------------------------ */}
      {/* Virtualized product grid                                            */}
      {/* ------------------------------------------------------------------ */}
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
                  className="absolute left-0 top-0 grid w-full gap-3"
                  style={{
                    // gridTemplateColumns is computed from JS (density-aware),
                    // replacing the old responsive Tailwind grid-cols-* classes.
                    gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                    height: `${virtualRow.size}px`,
                    // Pin the single grid track to exactly rowHeight so the
                    // leftover space below each card equals GAP (12px) — the
                    // same as the `gap-3` column gap. Without this, cards whose
                    // content exceeds rowHeight shrink the vertical gutter and
                    // it no longer matches the horizontal one.
                    gridAutoRows: `${rowHeight}px`,
                    transform: `translateY(${virtualRow.start}px)`,
                  }}
                >
                  {rowProducts.map((product) => (
                    <ProductCard
                      key={product.id}
                      product={product}
                      onAddToCart={onAddToCart}
                      onCustomize={onCustomize}
                      onViewDetails={onViewDetails}
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
