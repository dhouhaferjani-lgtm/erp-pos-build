import { useState, useMemo, useCallback, useRef, useEffect } from 'react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useVirtualizer } from '@tanstack/react-virtual';
import { cn } from '@/lib/utils';
import { Search, X, Package, Image, List, Table, TrendingUp, SlidersHorizontal } from 'lucide-react';
import { Button, Pill, SegmentedControl } from '@/components/ui';
import type { SegmentedOption } from '@/components/ui';
import { ProductCard } from '@/components/molecules/ProductCard';
import { ProductListRow } from './ProductListRow';
import { ProductTable } from './ProductTable';
import type { POSProduct } from '@/types/product';
import type { GridLocationStockMap } from '@/lib/stock/gridStock';
import { useAuthStore } from '@/stores/authStore';
import { useSettingsStore, type DisplayMode } from '@/stores/settingsStore';
import { useProductStore, hasModule } from '@/stores/productStore';
import { useMostSoldCounts } from '@/hooks/useMostSoldCounts';
import { bccomp } from '@/lib/decimal';
import {
  GAP,
  getColumns,
  getCardMinH,
} from '@/components/molecules/ProductCard/cardSizing';
import { FiltresDrawer, EMPTY_FILTRES_FILTERS } from '@/components/organisms/FiltresDrawer';
import type { FiltresFilters } from '@/components/organisms/FiltresDrawer';

type SortMode = 'default' | 'mostSold';

/**
 * View-mode → ProductCard *card-layout* map. Only the `vitrine` path renders
 * `ProductCard`; `liste` uses `ProductListRow` and `tableau` uses
 * `ProductTable`, so the card-layout is only ever `'visual'` (vitrine) — the
 * `'grid'` fallback is never actually reached by a rendered card, but keeps
 * `getColumns` / `getCardMinH` (which speak the `'grid'|'visual'` card-layout
 * vocabulary) well-typed.
 */
const cardLayoutFor = (mode: DisplayMode): 'grid' | 'visual' =>
  mode === 'vitrine' ? 'visual' : 'grid';

/**
 * Column count per view-mode. `vitrine` is the only multi-column card grid;
 * `liste` is a single measured column; `tableau` bypasses this virtualizer
 * entirely (ProductTable self-virtualizes) so its column count is inert.
 */
const columnsFor = (
  mode: DisplayMode,
  density: 'comfortable' | 'dense',
  width: number,
): number => (mode === 'vitrine' ? getColumns('visual', density, width) : 1);

/** Initial virtual-row height estimate for the touch `liste` row. */
const LIST_ROW_ESTIMATE = 52;

/**
 * Stable default for the `locationStock` prop — an inline `{}` default would
 * be a brand-new object every render, invalidating the `filteredProducts`
 * memo (which depends on it) on each pass.
 */
const EMPTY_LOCATION_STOCK: GridLocationStockMap = {};

export interface CompletedScan {
  barcode: string;
  target: EventTarget | null;
}

export interface ProductGridProps {
  products: POSProduct[];
  categories: string[];
  onAddToCart: (product: POSProduct) => void;
  onCustomize?: (product: POSProduct) => void;
  cartProductIds: string[];
  /**
   * Lane C 2026-09-17 — the last completed scanner burst, as reported by
   * `useBarcodeScanner` on HomePage. When its `target` is this grid's search
   * input and that input is still focused, the search value is replaced by the
   * decoded barcode and fully selected so the next scan overwrites it instead
   * of appending. Identity-based: pass a fresh object per scan; the same object
   * is never applied twice (re-render, refocus, loading round-trip, remount).
   */
  completedScan?: CompletedScan | null;
  /**
   * Owner polish 2026-07-09 (sub-task a): per-product cart quantity, shown in
   * the in-cart count chip on vitrine/compact cards. Optional — when absent
   * the chip falls back to a check glyph. Keyed by `product.id`.
   */
  cartQuantities?: Record<string, number>;
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
  onRequestRefill?: (product: POSProduct) => void;
  /**
   * Task 26 — Drawer-style active filters (brands, categories, skinTypes).
   * Owned by HomePage so the skin-advice bar (Task 27+) can also read/set
   * the skinType selection. Optional — when absent the grid applies no drawer
   * filters and the Filtres affordance is hidden.
   */
  filters?: FiltresFilters;
  /** Called when the user changes drawer filters. */
  onFiltersChange?: (filters: FiltresFilters) => void;
  /**
   * Task 27 — skin_type from the currently-attached customer (resolved by
   * HomePage from SQLite via getCustomerById). When provided and the
   * matching skin type is not already active, the bar auto-defaults it into
   * filters.skinTypes. Null / undefined = no customer or no skin type known.
   */
  customerSkinType?: string | null;
}

export function ProductGrid({
  products,
  categories,
  onAddToCart,
  onCustomize,
  cartProductIds,
  cartQuantities,
  completedScan,
  isLoading = false,
  consumptionModeToggle,
  locationStock = EMPTY_LOCATION_STOCK,
  hardBlockOutOfStock = true,
  onViewDetails,
  onRequestRefill,
  filters,
  onFiltersChange,
  customerSkinType,
}: ProductGridProps) {
  const { t } = useTranslation('pos');

  // ---------------------------------------------------------------------------
  // settingsStore — single source of truth for displayMode and density
  // (Task 25: replaced localStorage dual-source with store subscription)
  // ---------------------------------------------------------------------------
  const displayMode = useSettingsStore((s) => s.displayMode);
  const density = useSettingsStore((s) => s.density);
  const showParapharmacyFilters = useSettingsStore((s) => s.parapharmacySkinFiltersEnabled);
  const setDisplayMode = useSettingsStore((s) => s.setDisplayMode);

  const [searchQuery, setSearchQuery] = useState('');
  const searchInputRef = useRef<HTMLInputElement>(null);
  const consumedScanRef = useRef<CompletedScan | null>(null);

  // Apply a completed scan exactly once, and only when it targeted our
  // focused search input. Write the DOM value BEFORE the state update so
  // React's commit is a no-op on the node and the selection survives (jsdom
  // and browsers reset selection to the end whenever `value` is assigned).
  useEffect(() => {
    if (!completedScan || consumedScanRef.current === completedScan) return;
    consumedScanRef.current = completedScan;
    const input = searchInputRef.current;
    if (!input || completedScan.target !== input || document.activeElement !== input) return;
    input.value = completedScan.barcode;
    input.setSelectionRange(0, completedScan.barcode.length);
    setSearchQuery(completedScan.barcode);
  }, [completedScan]);
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
  const [inStockOnly, setInStockOnly] = useState(false);
  const [sortMode, setSortMode] = useState<SortMode>('default');

  // Task 26 — Merchandising module gate for the Filtres drawer.
  const companyConfig = useProductStore((s) => s.companyConfig);
  const isMerchandisingEnabled = hasModule(companyConfig, 'Merchandising');
  const [filtresOpen, setFiltresOpen] = useState(false);

  // ---------------------------------------------------------------------------
  // Task 27 — auto-default skin type from attached customer.
  // Stable refs ensure the effect only triggers when customerSkinType changes,
  // not on every filter-state update (avoids infinite-loop risk if the parent
  // updates filtresFilters on every render).
  // ---------------------------------------------------------------------------
  const _filtersRef = useRef(filters);
  _filtersRef.current = filters;
  const _onFiltersChangeRef = useRef(onFiltersChange);
  _onFiltersChangeRef.current = onFiltersChange;

  useEffect(() => {
    if (!isMerchandisingEnabled || !showParapharmacyFilters) return;
    if (!customerSkinType) return;
    const f = _filtersRef.current;
    const onChange = _onFiltersChangeRef.current;
    if (!f || !onChange) return;
    if (f.skinTypes.length > 0) return;        // don't override any existing manual skin selection
    onChange({ ...f, skinTypes: [customerSkinType] });
    // customerSkinType / isMerchandisingEnabled are the change-drivers; filters/onFiltersChange are
    // read via stable refs — refs are excluded from deps by convention.
  }, [customerSkinType, isMerchandisingEnabled, showParapharmacyFilters]);

  const companyId = useAuthStore((s) => s.companyId);
  const { counts: salesCounts } = useMostSoldCounts({
    companyId,
    enabled: sortMode === 'mostSold',
  });

  const gridRootRef = useRef<HTMLDivElement>(null);
  const scrollContainerRef = useRef<HTMLDivElement>(null);

  const allCategoriesLabel = t('products.allCategories');

  // ---------------------------------------------------------------------------
  // Density-aware column count — recomputed on displayMode / density change and
  // on window resize so the virtualizer row math stays correct.
  // ---------------------------------------------------------------------------
  const [columns, setColumns] = useState<number>(() =>
    columnsFor(displayMode, density, typeof window !== 'undefined' ? window.innerWidth : 1280),
  );

  useEffect(() => {
    const update = () => {
      const measuredWidth = gridRootRef.current?.getBoundingClientRect().width ?? 0;
      const fallbackWidth = typeof window !== 'undefined' ? window.innerWidth : 1280;
      const width = measuredWidth > 0 ? measuredWidth : fallbackWidth;
      setColumns(columnsFor(displayMode, density, width));
    };
    update();
    window.addEventListener('resize', update, { passive: true });
    const ResizeObserverCtor = typeof ResizeObserver !== 'undefined' ? ResizeObserver : null;
    const observer = ResizeObserverCtor !== null ? new ResizeObserverCtor(update) : null;
    if (observer && gridRootRef.current) observer.observe(gridRootRef.current);
    return () => {
      window.removeEventListener('resize', update);
      observer?.disconnect();
    };
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
        value: 'vitrine',
        icon: <Image className="h-5 w-5" />,
        ariaLabel: t('display.vitrine'),
      },
      {
        value: 'liste',
        icon: <List className="h-5 w-5" />,
        ariaLabel: t('display.liste'),
      },
      {
        value: 'tableau',
        icon: <Table className="h-5 w-5" />,
        ariaLabel: t('display.tableau'),
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

    // Task 26 — drawer-style facet filters (Merchandising module).
    if (filters?.brands && filters.brands.length > 0) {
      filtered = filtered.filter(
        (p) => p.brand_name != null && filters.brands.includes(p.brand_name),
      );
    }
    if (filters?.categories && filters.categories.length > 0) {
      filtered = filtered.filter(
        (p) => p.category != null && filters.categories.includes(p.category),
      );
    }
    if (showParapharmacyFilters && filters?.skinTypes && filters.skinTypes.length > 0) {
      filtered = filtered.filter((p) => {
        const sst = p.parapharmacy_metadata?.suitable_skin_types;
        return sst != null && filters.skinTypes.some((st) => sst.includes(st));
      });
    }
    if (showParapharmacyFilters && filters?.routines && filters.routines.length > 0) {
      filtered = filtered.filter((p) => {
        const refs = p.parapharmacy_metadata?.routine_refs;
        return refs != null && filters.routines.some((routine) =>
          refs.some((ref) => ref.step_label === routine),
        );
      });
    }

    return filtered;
  }, [sortedProducts, selectedCategory, searchQuery, inStockOnly, locationStock, filters, showParapharmacyFilters]);

  // ---------------------------------------------------------------------------
  // Active filter count — badge on the Filtres button. Counts ONLY the
  // drawer-style facet selections; `inStockOnly` is a separate toggle outside
  // the drawer, so including it would make the badge misrepresent the drawer.
  // ---------------------------------------------------------------------------
  const activeFilterCount =
    (filters?.brands.length ?? 0) +
    (filters?.categories.length ?? 0) +
    (showParapharmacyFilters ? (filters?.skinTypes.length ?? 0) + (filters?.routines.length ?? 0) : 0);

  const rowHeight =
    displayMode === 'liste' ? LIST_ROW_ESTIMATE : getCardMinH(cardLayoutFor(displayMode), density);
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
    <div ref={gridRootRef} className="flex h-full flex-col gap-2">
      {/* ------------------------------------------------------------------ */}
      {/* Toolbar: search · Filtres · Top ventes · view toggle               */}
      {/* ------------------------------------------------------------------ */}
      <div className="flex items-center gap-2">
        {consumptionModeToggle}
        <div className="relative min-w-0 flex-1">
          <Search className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-ink-faint" />
          <input
            ref={searchInputRef}
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder={t('products.searchPlaceholder')}
            className="w-full rounded-ctl border border-border-subtle bg-surface-raised py-2 pl-10 pr-10 text-sm text-ink placeholder:text-ink-faint focus:border-action focus:outline-none focus:ring-2 focus:ring-action"
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

        {/* Filtres — module-gated (Merchandising). Task 26: real drawer + filter chips. */}
        {isMerchandisingEnabled && (
          <Button
            variant="secondary"
            size="md"
            leftIcon={<SlidersHorizontal className="h-5 w-5" />}
            onClick={() => setFiltresOpen(true)}
            aria-label={t('products.filters')}
            aria-expanded={filtresOpen}
            data-testid="filtres-button"
          >
            {t('products.filters')}
            {activeFilterCount > 0 && (
              <span className="ml-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-action text-xs font-semibold text-ink-inverse">
                {activeFilterCount}
              </span>
            )}
          </Button>
        )}

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

      {/* Active filter-chip row (Task 26) — shown only when Merchandising module is on
          and there are active drawer filters. */}
      {isMerchandisingEnabled && filters && (
        filters.brands.length > 0 ||
        filters.categories.length > 0 ||
        (showParapharmacyFilters && (filters.skinTypes.length > 0 || filters.routines.length > 0))
      ) && (
        <div className="flex flex-wrap items-center gap-2" data-testid="filter-chip-row">
          {filters.brands.map((brand) => (
            <Pill
              key={`brand-${brand}`}
              selected
              onRemove={() =>
                onFiltersChange?.({
                  ...filters,
                  brands: filters.brands.filter((b) => b !== brand),
                })
              }
              removeLabel={t('products.filterRemove', { value: brand })}
            >
              {brand}
            </Pill>
          ))}
          {filters.categories.map((cat) => (
            <Pill
              key={`cat-${cat}`}
              selected
              onRemove={() =>
                onFiltersChange?.({
                  ...filters,
                  categories: filters.categories.filter((c) => c !== cat),
                })
              }
              removeLabel={t('products.filterRemove', { value: cat })}
            >
              {cat}
            </Pill>
          ))}
          {showParapharmacyFilters && filters.skinTypes.map((st) => (
            <Pill
              key={`skin-${st}`}
              selected
              onRemove={() =>
                onFiltersChange?.({
                  ...filters,
                  skinTypes: filters.skinTypes.filter((s) => s !== st),
                })
              }
              removeLabel={t('products.filterRemove', {
                value: t(`skin_type.${st}`, { ns: 'smart-prompts', defaultValue: st }),
              })}
            >
              {t(`skin_type.${st}`, { ns: 'smart-prompts', defaultValue: st })}
            </Pill>
          ))}
          {showParapharmacyFilters && filters.routines.map((routine) => (
            <Pill
              key={`routine-${routine}`}
              selected
              onRemove={() =>
                onFiltersChange?.({
                  ...filters,
                  routines: filters.routines.filter((r) => r !== routine),
                })
              }
              removeLabel={t('products.filterRemove', { value: routine })}
            >
              {routine}
            </Pill>
          ))}
        </div>
      )}

      {/* Filtres drawer (Task 26) — module-gated */}
      {isMerchandisingEnabled && (
        <FiltresDrawer
          isOpen={filtresOpen}
          onClose={() => setFiltresOpen(false)}
          products={products}
          filters={filters ?? EMPTY_FILTRES_FILTERS}
          onFiltersChange={(f) => {
            onFiltersChange?.(f);
          }}
          resultCount={filteredProducts.length}
          showParapharmacyFilters={showParapharmacyFilters}
        />
      )}

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
      ) : displayMode === 'tableau' ? (
        // Tableau — desktop-dense ARIA grid. ProductTable OWNS its own
        // virtualizer + scroll container, so it bypasses the card virtualizer
        // above entirely (the shared search/facet filters still apply via
        // `filteredProducts`).
        <div className="min-h-0 flex-1">
          <ProductTable
            products={filteredProducts}
            onAddToCart={onAddToCart}
            onViewDetails={onViewDetails}
            locationStock={locationStock}
            hardBlockOutOfStock={hardBlockOutOfStock}
          />
        </div>
      ) : (
        <div
          ref={scrollContainerRef}
          data-testid="product-grid-scroll"
          className="flex-1 overflow-y-auto bg-[#e3e8ee]"
        >
          <div
            className="relative w-full"
            style={{ height: `${virtualizer.getTotalSize()}px` }}
          >
            {virtualizer.getVirtualItems().map((virtualRow) => {
              // Liste — one measured ProductListRow per virtual row (columns=1).
              // Rows are flush (each row's own border-b is the separator), so no
              // paddingBottom gutter; height is still DYNAMICALLY MEASURED.
              if (displayMode === 'liste') {
                const product = filteredProducts[virtualRow.index];
                if (!product) return null;
                return (
                  <div
                    key={virtualRow.key}
                    ref={virtualizer.measureElement}
                    data-index={virtualRow.index}
                    className="absolute left-0 top-0 w-full"
                    style={{ transform: `translateY(${virtualRow.start}px)` }}
                  >
                    <ProductListRow
                      product={product}
                      onAddToCart={onAddToCart}
                      onViewDetails={onViewDetails}
                      locationStock={locationStock[product.id]}
                      hardBlockOutOfStock={hardBlockOutOfStock}
                    />
                  </div>
                );
              }

              // Vitrine — density-aware N-column image-card grid.
              const startIdx = virtualRow.index * columns;
              const rowProducts = filteredProducts.slice(startIdx, startIdx + columns);

              return (
                <div
                  key={virtualRow.key}
                  ref={virtualizer.measureElement}
                  data-index={virtualRow.index}
                  className="absolute left-0 top-0 grid w-full gap-2.5"
                  style={{
                    // gridTemplateColumns is computed from JS (density-aware),
                    // replacing the old responsive Tailwind grid-cols-* classes.
                    gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                    // Rows are DYNAMICALLY MEASURED (measureElement ref above):
                    // no fixed height / gridAutoRows pin. Cards are content-sized
                    // and equalize within a row via the grid's default
                    // align-items:stretch. paddingBottom supplies the row gutter
                    // (measured into the row height so the next row sits below it).
                    paddingBottom: `${GAP}px`,
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
                      onRequestRefill={onRequestRefill}
                      isInCart={cartProductIds.includes(product.id)}
                      cartQuantity={cartQuantities?.[product.id]}
                      displayMode={cardLayoutFor(displayMode)}
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
