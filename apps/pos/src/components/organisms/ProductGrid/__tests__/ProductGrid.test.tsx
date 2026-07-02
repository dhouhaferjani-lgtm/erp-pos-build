import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { ProductGrid, type ProductGridProps } from '../ProductGrid';
import { makeProduct } from '@/test/helpers';
import type { POSProduct } from '@/types/product';
import type { FiltresFilters } from '@/components/organisms/FiltresDrawer';

// ---------------------------------------------------------------------------
// settingsStore mock — hoisted so vi.mock factory can reference it.
// Each test that cares about displayMode/density mutates settingsStoreMock.state
// directly; a beforeEach resets it to safe defaults (grid + comfortable).
// ---------------------------------------------------------------------------
const settingsStoreMock = vi.hoisted(() => ({
  state: {
    displayMode: 'grid' as 'grid' | 'visual',
    density: 'comfortable' as 'comfortable' | 'dense',
    parapharmacySkinFiltersEnabled: true,
    setDisplayMode: vi.fn(),
  },
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: vi.fn(
    <T,>(selector: (s: typeof settingsStoreMock.state) => T): T =>
      selector(settingsStoreMock.state),
  ),
}));

// ---------------------------------------------------------------------------
// productStore mock — hoisted so Task 26 module-gating tests can control
// companyConfig. Default: null (Merchandising disabled → Filtres hidden).
// ---------------------------------------------------------------------------
const productStoreMock = vi.hoisted(() => ({
  state: {
    companyConfig: null as import('@/types/companyConfig').CompanyConfig | null,
  },
}));

vi.mock('@/stores/productStore', () => ({
  useProductStore: vi.fn(
    <T,>(
      selector: (s: { companyConfig: import('@/types/companyConfig').CompanyConfig | null }) => T,
    ): T => selector(productStoreMock.state),
  ),
  hasModule: (
    config: { all_enabled_modules?: string[] } | null,
    moduleName: string,
  ): boolean => {
    const modules = config?.all_enabled_modules ?? [];
    return modules.includes(moduleName);
  },
}));

// FiltresDrawer mock — avoids focus-trap DOM issues when the drawer is opened
// via button click. ProductGrid integration tests verify chip rendering and
// grid narrowing via the filters prop directly.
vi.mock('@/components/organisms/FiltresDrawer', () => ({
  FiltresDrawer: ({
    isOpen,
    onClose,
  }: {
    isOpen: boolean;
    onClose: () => void;
    products: POSProduct[];
    filters: FiltresFilters;
    onFiltersChange: (f: FiltresFilters) => void;
    resultCount: number;
  }) =>
    isOpen ? (
      <div role="dialog" data-testid="filtres-drawer" onClick={onClose}>
        FiltresDrawer
      </div>
    ) : null,
  EMPTY_FILTRES_FILTERS: { brands: [], categories: [], skinTypes: [], routines: [] },
}));

// Mock localStorage for ProductGrid's display mode storage
const localStorageMock = (() => {
  let store: Record<string, string> = {};
  return {
    getItem: (key: string) => store[key] ?? null,
    setItem: (key: string, value: string) => { store[key] = value; },
    removeItem: (key: string) => { delete store[key]; },
    clear: () => { store = {}; },
    get length() { return Object.keys(store).length; },
    key: (index: number) => Object.keys(store)[index] ?? null,
  };
})();
Object.defineProperty(globalThis, 'localStorage', { value: localStorageMock, writable: true });

const mockT = vi.fn((key: string) => key);

vi.mock('react-i18next', () => ({
  useTranslation: vi.fn(() => ({
    t: mockT,
    i18n: {} as ReturnType<typeof useTranslation>['i18n'],
    ready: true,
  })),
}));

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (amount: number | string) => {
      const num = typeof amount === 'string' ? parseFloat(amount) : amount;
      return `${num.toFixed(2)} EUR`;
    },
  }),
}));

vi.mock('@/components/molecules/ProductCard', () => ({
  ProductCard: ({
    product,
    onAddToCart,
  }: {
    product: { id: string; name: string };
    onAddToCart: (p: { id: string; name: string }) => void;
  }) => (
    <button data-testid={`product-${product.id}`} onClick={() => onAddToCart(product)}>
      {product.name}
    </button>
  ),
}));

// The virtualizer returns 0 rows in jsdom because the scroll container has no
// layout height. Mock it to render every row passed to it so product cards
// appear in the DOM for assertions.
//
// T2.1 Step C prereq (Codex round-1 m3): the mock now also exposes
// `scrollToIndex` and `measure` spies + a settable scroll-offset, so
// Step C's filter-state-guard tests can assert against them.
const virtualizerSpies = vi.hoisted(() => ({
  scrollToIndex: vi.fn(),
  measure: vi.fn(),
  scrollOffset: 0,
}));

vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: (opts: { count: number; estimateSize: () => number }) => ({
    getVirtualItems: () =>
      Array.from({ length: opts.count }, (_, i) => ({
        key: i,
        index: i,
        start: i * opts.estimateSize(),
        size: opts.estimateSize(),
      })),
    getTotalSize: () => opts.count * opts.estimateSize(),
    scrollToIndex: virtualizerSpies.scrollToIndex,
    measure: virtualizerSpies.measure,
    scrollOffset: virtualizerSpies.scrollOffset,
  }),
}));

function renderGrid(overrides: Partial<ProductGridProps> = {}) {
  const defaults: ProductGridProps = {
    products: [
      makeProduct({ id: 'p1', name: 'Alpha', sku: 'A-001', sale_price: '10.00', stock_quantity: 50, category: 'Electronics' }),
      makeProduct({ id: 'p2', name: 'Beta', sku: 'B-001', sale_price: '20.00', stock_quantity: 30, category: 'Accessories' }),
      makeProduct({ id: 'p3', name: 'Gamma', sku: 'G-001', sale_price: '15.00', stock_quantity: 0, category: 'Electronics' }),
    ],
    categories: ['Accessories', 'Electronics'],
    onAddToCart: vi.fn(),
    cartProductIds: [],
  };
  return render(<ProductGrid {...defaults} {...overrides} />);
}

describe('ProductGrid', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorageMock.clear();
    // Reset store mock to safe defaults.
    settingsStoreMock.state.displayMode = 'grid';
    settingsStoreMock.state.density = 'comfortable';
    settingsStoreMock.state.parapharmacySkinFiltersEnabled = true;
    settingsStoreMock.state.setDisplayMode = vi.fn();
    // Restore default t() behaviour (key-passthrough) after vi.clearAllMocks.
    mockT.mockImplementation((key: string) => key);
  });

  it('shows loading state', () => {
    renderGrid({ isLoading: true });
    expect(screen.getByText('products.loading')).toBeInTheDocument();
  });

  it('shows empty state when no products', () => {
    renderGrid({ products: [], categories: [] });
    expect(screen.getByText('products.empty')).toBeInTheDocument();
  });

  it('renders search input with clear button when query present', () => {
    renderGrid();

    const searchInput = screen.getByRole('textbox');
    fireEvent.change(searchInput, { target: { value: 'test' } });

    const clearButton = screen.getByLabelText('products.clearSearch');
    expect(clearButton).toBeInTheDocument();

    fireEvent.click(clearButton);
    expect((searchInput as HTMLInputElement).value).toBe('');
  });

  it('renders category filter buttons', () => {
    renderGrid();
    expect(screen.getByText('products.allCategories')).toBeInTheDocument();
    expect(screen.getByText(/Electronics/)).toBeInTheDocument();
    expect(screen.getByText(/Accessories/)).toBeInTheDocument();
  });

  it('shows not found message when search yields no results', () => {
    renderGrid();

    const searchInput = screen.getByRole('textbox');
    fireEvent.change(searchInput, { target: { value: 'zzzzzznotfound' } });

    expect(screen.getByText('products.notFound')).toBeInTheDocument();
  });

  it('sizes columns from the grid container, not the full app window', async () => {
    Object.defineProperty(window, 'innerWidth', {
      configurable: true,
      writable: true,
      value: 1600,
    });
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
      x: 0,
      y: 0,
      width: 900,
      height: 640,
      top: 0,
      right: 900,
      bottom: 640,
      left: 0,
      toJSON: () => ({}),
    } as DOMRect);
    settingsStoreMock.state.displayMode = 'visual';
    settingsStoreMock.state.density = 'dense';

    const products = Array.from({ length: 12 }, (_, index) =>
      makeProduct({
        id: `p-${index}`,
        name: `Product ${index}`,
        sku: `SKU-${index}`,
        sale_price: '10.000',
        stock_quantity: 10,
      }),
    );
    const { container } = renderGrid({ products, categories: [] });

    const firstRow = container.querySelector('[data-index="0"]') as HTMLElement | null;
    await waitFor(() => {
      expect(firstRow?.style.gridTemplateColumns).toBe('repeat(5, minmax(0, 1fr))');
    });
  });

  it('mounts without crashing with 100 products', () => {
    const products = Array.from({ length: 100 }, (_, i) =>
      makeProduct({
        id: `p-${i}`,
        name: `Product ${i}`,
        sku: `SKU-${i}`,
        sale_price: '10.00',
        stock_quantity: 10,
        category: 'Cat-A',
      }),
    );
    const { container } = renderGrid({ products, categories: ['Cat-A'] });
    expect(container).toBeTruthy();
  });

  it('has a scroll container with data-testid="product-grid-scroll"', () => {
    renderGrid();
    expect(screen.getByTestId('product-grid-scroll')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Most-sold sort toggle + popular row removal
// ---------------------------------------------------------------------------

vi.mock('@/hooks/useMostSoldCounts', () => ({
  useMostSoldCounts: () => ({
    counts: new Map([
      ['p-bestseller', 100],
      ['p-mid', 5],
    ]),
    isLoading: false,
  }),
}));

vi.mock('@/stores/authStore', () => {
  const state = { companyId: 'c1' as string | null };
  const useAuthStore = ((selector: (s: typeof state) => unknown) =>
    selector(state)) as unknown as {
      (selector: (s: typeof state) => unknown): unknown;
      getState: () => typeof state;
    };
  useAuthStore.getState = () => state;
  return { useAuthStore };
});

describe('ProductGrid most-sold sort + popular-row removal', () => {
  // Names chosen so alphabetical order (Mid, Omega, Zilch) diverges from
  // most-sold order (Omega 100, Mid 5, Zilch 0). This way the toggle-back
  // assertion catches a regression where the sort fails to revert.
  const products: POSProduct[] = [
    { id: 'p-zilch', name: 'Zilch', sku: 'Z', sale_price: '1.000', stock_quantity: 5, barcode: null },
    { id: 'p-bestseller', name: 'Omega', sku: 'O', sale_price: '1.000', stock_quantity: 5, barcode: null },
    { id: 'p-mid', name: 'Mid', sku: 'M', sale_price: '1.000', stock_quantity: 5, barcode: null },
  ];

  beforeEach(() => {
    // Override t() to return English strings for sort-toggle keys so the
    // getByRole name assertions match.
    mockT.mockImplementation((key: string) => {
      const map: Record<string, string> = {
        'products.sortByMostSold': 'Sort by most sold',
        'products.sortDefault': 'Sort default',
        'products.allCategories': 'All',
        'products.searchPlaceholder': 'Search',
        'products.clearSearch': 'Clear search',
        'display.inStockOnly': 'In stock only',
        'display.gridMode': 'Grid',
        'display.visualMode': 'Visual',
      };
      return map[key] ?? key;
    });
  });

  function renderGrid(extra?: Partial<ComponentProps<typeof ProductGrid>>) {
    return render(
      <ProductGrid
        products={products}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        {...extra}
      />,
    );
  }

  it('reorders products by most-sold-first when the sort button is clicked', () => {
    renderGrid();
    const toggle = screen.getByRole('button', { name: /sort by most sold/i });
    fireEvent.click(toggle);

    const cards = screen.getAllByRole('button', { name: /Omega|Mid|Zilch/ });
    // Most-sold counts: Omega 100, Mid 5, Zilch 0
    expect(cards[0]).toHaveAccessibleName('Omega');
    expect(cards[1]).toHaveAccessibleName('Mid');
    expect(cards[2]).toHaveAccessibleName('Zilch');
  });

  it('toggles back to default order on a second click', () => {
    renderGrid();
    const toggle = screen.getByRole('button', { name: /sort by most sold/i });
    fireEvent.click(toggle); // most-sold
    fireEvent.click(toggle); // back to default

    const cards = screen.getAllByRole('button', { name: /Omega|Mid|Zilch/ });
    // Default sort is alphabetical (no `position` field on these fixtures):
    // Mid → Omega → Zilch. Diverges from most-sold (Omega → Mid → Zilch),
    // so this assertion fails if the toggle does not actually revert.
    expect(cards[0]).toHaveAccessibleName('Mid');
    expect(cards[1]).toHaveAccessibleName('Omega');
    expect(cards[2]).toHaveAccessibleName('Zilch');
  });

  it('does NOT render the legacy Popular pill row', () => {
    renderGrid();
    expect(screen.queryByText(/^popular$/i)).toBeNull();
    expect(screen.queryByText(/^populaires$/i)).toBeNull();
  });

  it('keeps out-of-stock items below sellable ones even in most-sold mode', () => {
    // Out-of-stock best-seller must NOT sort above an in-stock low-seller.
    // Counts: Omega 100 (stock 0 → out), Mid 5 (stock 5), Zilch 0 (stock 5).
    const stockSpecific: POSProduct[] = [
      { id: 'p-bestseller', name: 'Omega', sku: 'O', sale_price: '1.000', stock_quantity: 0, barcode: null },
      { id: 'p-mid', name: 'Mid', sku: 'M', sale_price: '1.000', stock_quantity: 5, barcode: null },
      { id: 'p-zilch', name: 'Zilch', sku: 'Z', sale_price: '1.000', stock_quantity: 5, barcode: null },
    ];
    renderGrid({ products: stockSpecific });
    fireEvent.click(screen.getByRole('button', { name: /sort by most sold/i }));

    const cards = screen.getAllByRole('button', { name: /Omega|Mid|Zilch/ });
    // Sellable first (Mid 5 > Zilch 0 by most-sold), out-of-stock Omega last.
    expect(cards[0]).toHaveAccessibleName('Mid');
    expect(cards[1]).toHaveAccessibleName('Zilch');
    expect(cards[2]).toHaveAccessibleName('Omega');
  });
});

// ---------------------------------------------------------------------------
// T2.1 Step C — filter-state guards (selectedCategory invalidation +
// virtualizer composite-resetKey reset). Codex round-1 m1/m2/m3 fixes:
//   - resetKey must include identity (firstId/lastId), not just length.
//   - C.4 uses a stale `categories` prop, not a click on a nonexistent
//     button.
//   - The mock above (line 61-79) is extended with scrollToIndex +
//     measure spies BEFORE these tests run, so each RED comes from
//     production-behavior reasons (effect not wired) rather than a
//     missing-mock-API reason.
// ---------------------------------------------------------------------------

describe('ProductGrid — T2.1 Step C filter-state guards', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    virtualizerSpies.scrollToIndex.mockClear();
    virtualizerSpies.measure.mockClear();
    virtualizerSpies.scrollOffset = 0;
    mockT.mockImplementation((key: string) => {
      const map: Record<string, string> = {
        'products.allCategories': 'All',
        'products.searchPlaceholder': 'Search',
        'products.clearSearch': 'Clear search',
        'products.notFound': 'No products found',
        'products.tryAdjusting': 'Try adjusting filters',
      };
      return map[key] ?? key;
    });
  });

  function makeProducts(specs: Array<{ id: string; cat: string }>): POSProduct[] {
    return specs.map((s) =>
      makeProduct({
        id: s.id,
        name: `Product ${s.id}`,
        sku: s.id,
        sale_price: '10.00',
        stock_quantity: 10,
        category: s.cat,
      }),
    );
  }

  it('C.1: selectedCategory resets to null when the previously-selected category disappears from products[]', () => {
    const initial = makeProducts([
      { id: 'p1', cat: 'catA' },
      { id: 'p2', cat: 'catB' },
    ]);
    const { rerender } = render(
      <ProductGrid
        products={initial}
        categories={['catA', 'catB']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );

    // Click catA tab → selectedCategory becomes 'catA'.
    const catAButton = screen.getByRole('button', { name: /catA\s*\(1\)/ });
    fireEvent.click(catAButton);
    // Visible card: only p1.
    expect(screen.getByTestId('product-p1')).toBeInTheDocument();
    expect(screen.queryByTestId('product-p2')).not.toBeInTheDocument();

    // Sync tick: catA disappears, replaced with catC.
    const next = makeProducts([
      { id: 'p2', cat: 'catB' },
      { id: 'p3', cat: 'catC' },
    ]);
    rerender(
      <ProductGrid
        products={next}
        categories={['catB', 'catC']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );

    // selectedCategory must reset to null (= "all categories"), so both
    // p2 and p3 are visible. Pre-T2.1: selectedCategory='catA' is stale,
    // filteredProducts is empty, both p2/p3 hidden behind the empty UI.
    expect(screen.getByTestId('product-p2')).toBeInTheDocument();
    expect(screen.getByTestId('product-p3')).toBeInTheDocument();
  });

  it('C.2: selectedCategory persists when the category set is unchanged across renders', () => {
    const initial = makeProducts([
      { id: 'p1', cat: 'catA' },
      { id: 'p2', cat: 'catB' },
    ]);
    const { rerender } = render(
      <ProductGrid
        products={initial}
        categories={['catA', 'catB']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: /catA\s*\(1\)/ }));
    expect(screen.getByTestId('product-p1')).toBeInTheDocument();

    // Re-render with the same categories (catA still present).
    const next = makeProducts([
      { id: 'p1', cat: 'catA' },
      { id: 'p2', cat: 'catB' },
      { id: 'p3', cat: 'catA' },
    ]);
    rerender(
      <ProductGrid
        products={next}
        categories={['catA', 'catB']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );

    // selectedCategory persists → only catA products visible.
    expect(screen.getByTestId('product-p1')).toBeInTheDocument();
    expect(screen.getByTestId('product-p3')).toBeInTheDocument();
    expect(screen.queryByTestId('product-p2')).not.toBeInTheDocument();
  });

  it('C.3: virtualizer scrollToIndex(0) + measure() fire when the filter changes the visible subset', () => {
    const initial = makeProducts([
      { id: 'p1', cat: 'catA' },
      { id: 'p2', cat: 'catA' },
      { id: 'p3', cat: 'catB' },
    ]);
    render(
      <ProductGrid
        products={initial}
        categories={['catA', 'catB']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );
    // Baseline: ignore mount-time invocations.
    virtualizerSpies.scrollToIndex.mockClear();
    virtualizerSpies.measure.mockClear();

    // Click catB → filter narrows to {p3}; first/last id boundary changes.
    fireEvent.click(screen.getByRole('button', { name: /catB\s*\(1\)/ }));

    expect(virtualizerSpies.scrollToIndex).toHaveBeenCalledWith(0);
    expect(virtualizerSpies.measure).toHaveBeenCalled();
  });

  it('C.4: empty-state UI renders when filteredProducts is empty (stale category prop preserved)', () => {
    // Stale categories prop includes catEmpty even though no product
    // belongs to that category. With selectedCategory=catEmpty the
    // virtualizer scroll container must NOT mount.
    const products = makeProducts([{ id: 'p1', cat: 'catA' }]);
    render(
      <ProductGrid
        products={products}
        categories={['catA', 'catEmpty']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /catEmpty/ }));

    expect(screen.getByText('No products found')).toBeInTheDocument();
    expect(screen.queryByTestId('product-grid-scroll')).not.toBeInTheDocument();
  });

  it('C.5: rapid A→B→A category switching produces correct visible range each time', () => {
    const products = makeProducts([
      { id: 'p1', cat: 'catA' },
      { id: 'p2', cat: 'catA' },
      { id: 'p3', cat: 'catB' },
    ]);
    render(
      <ProductGrid
        products={products}
        categories={['catA', 'catB']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: /catA\s*\(2\)/ }));
    expect(screen.getByTestId('product-p1')).toBeInTheDocument();
    expect(screen.queryByTestId('product-p3')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /catB\s*\(1\)/ }));
    expect(screen.getByTestId('product-p3')).toBeInTheDocument();
    expect(screen.queryByTestId('product-p1')).not.toBeInTheDocument();

    virtualizerSpies.measure.mockClear();
    fireEvent.click(screen.getByRole('button', { name: /catA\s*\(2\)/ }));
    expect(screen.getByTestId('product-p1')).toBeInTheDocument();
    expect(screen.queryByTestId('product-p3')).not.toBeInTheDocument();
    // Third transition triggers measure (composite resetKey changed).
    expect(virtualizerSpies.measure).toHaveBeenCalled();
  });

  it('C.6: virtualizer resets on same-length-different-shape transition (firstId/lastId in resetKey)', () => {
    const initial = makeProducts([
      { id: 'a1', cat: 'catA' },
      { id: 'a2', cat: 'catA' },
      { id: 'a3', cat: 'catA' },
      { id: 'a4', cat: 'catA' },
    ]);
    const { rerender } = render(
      <ProductGrid
        products={initial}
        categories={['catA']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );
    virtualizerSpies.scrollToIndex.mockClear();

    // Re-render with same length (4) but all-different ids — composite
    // resetKey must catch this via firstId/lastId.
    const next = makeProducts([
      { id: 'b1', cat: 'catA' },
      { id: 'b2', cat: 'catA' },
      { id: 'b3', cat: 'catA' },
      { id: 'b4', cat: 'catA' },
    ]);
    rerender(
      <ProductGrid
        products={next}
        categories={['catA']}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );

    expect(virtualizerSpies.scrollToIndex).toHaveBeenCalledWith(0);
  });
});

// ---------------------------------------------------------------------------
// Task 25 — settingsStore is the single source of truth for displayMode
// ---------------------------------------------------------------------------

describe('ProductGrid — Task 25 settingsStore dual-source reconcile', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorageMock.clear();
    settingsStoreMock.state.displayMode = 'grid';
    settingsStoreMock.state.density = 'comfortable';
    settingsStoreMock.state.setDisplayMode = vi.fn();
    mockT.mockImplementation((key: string) => {
      const map: Record<string, string> = {
        'products.allCategories': 'All',
        'products.searchPlaceholder': 'Search',
        'products.clearSearch': 'Clear search',
        'display.gridMode': 'Grid',
        'display.visualMode': 'Visual',
        'display.mode': 'Display mode',
        'products.sortByMostSold': 'Sort by most sold',
        'display.inStockOnly': 'In stock only',
        'products.filters': 'Filtres',
      };
      return map[key] ?? key;
    });
  });

  function renderGrid(extra?: Partial<ProductGridProps>) {
    const defaults: ProductGridProps = {
      products: [
        makeProduct({ id: 'p1', name: 'Alpha', sku: 'A1', sale_price: '10.000', stock_quantity: 5 }),
        makeProduct({ id: 'p2', name: 'Beta', sku: 'B1', sale_price: '20.000', stock_quantity: 5 }),
      ],
      categories: [],
      onAddToCart: vi.fn(),
      cartProductIds: [],
    };
    return render(<ProductGrid {...defaults} {...extra} />);
  }

  it('reads displayMode from settingsStore — visual mode is reflected in the view toggle even when localStorage says grid', () => {
    // localStorage would have driven 'grid' in the old dual-source
    localStorageMock.setItem('pos-display-mode', 'grid');
    // Store says 'visual'
    settingsStoreMock.state.displayMode = 'visual';

    renderGrid();

    // SegmentedControl renders role="radio" buttons with aria-checked
    const visualRadio = screen.getByRole('radio', { name: 'Visual' });
    expect(visualRadio).toHaveAttribute('aria-checked', 'true');

    const gridRadio = screen.getByRole('radio', { name: 'Grid' });
    expect(gridRadio).toHaveAttribute('aria-checked', 'false');
  });

  it('clicking the view toggle calls settingsStore.setDisplayMode, not localStorage', () => {
    settingsStoreMock.state.displayMode = 'grid';
    renderGrid();

    const visualRadio = screen.getByRole('radio', { name: 'Visual' });
    fireEvent.click(visualRadio);

    // The store setter must have been called
    expect(settingsStoreMock.state.setDisplayMode).toHaveBeenCalledWith('visual');
  });
});

// ---------------------------------------------------------------------------
// Task 26 — Filtres drawer (module-gated)
// (b) applying filters narrows the grid + shows removable chips + count badge
// (c) the Filtres affordance is hidden when hasModule returns false
// ---------------------------------------------------------------------------

describe('ProductGrid — Task 26 Filtres drawer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    productStoreMock.state.companyConfig = null;
    settingsStoreMock.state.displayMode = 'grid';
    settingsStoreMock.state.density = 'comfortable';
    settingsStoreMock.state.setDisplayMode = vi.fn();
    mockT.mockImplementation((key: string, opts?: { defaultValue?: string; ns?: string }) =>
      opts?.defaultValue ?? key,
    );
  });

  const brandProducts = [
    makeProduct({ id: 'p-avene', name: 'Avene Cream', sku: 'A1', sale_price: '10.000', stock_quantity: 5, brand_name: 'Avene' }),
    makeProduct({ id: 'p-vichy', name: 'Vichy Gel', sku: 'V1', sale_price: '8.000', stock_quantity: 5, brand_name: 'Vichy' }),
    makeProduct({ id: 'p-plain', name: 'Generic Cream', sku: 'G1', sale_price: '5.000', stock_quantity: 5 }),
  ];

  // (c) Filtres affordance hidden when module off
  it('hides the Filtres button when Merchandising module is not enabled', () => {
    productStoreMock.state.companyConfig = null;
    render(
      <ProductGrid
        products={brandProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );
    expect(screen.queryByTestId('filtres-button')).not.toBeInTheDocument();
  });

  it('shows the Filtres button when Merchandising module is enabled', () => {
    productStoreMock.state.companyConfig = {
      all_enabled_modules: ['Merchandising'],
    } as import('@/types/companyConfig').CompanyConfig;
    render(
      <ProductGrid
        products={brandProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );
    expect(screen.getByTestId('filtres-button')).toBeInTheDocument();
  });

  // (b) Brand filter narrows the grid
  it('applies a brand filter: only matching products remain visible', () => {
    productStoreMock.state.companyConfig = {
      all_enabled_modules: ['Merchandising'],
    } as import('@/types/companyConfig').CompanyConfig;
    const filters: FiltresFilters = {
      brands: ['Avene'],
      categories: [],
      skinTypes: [],
      routines: [],
    };
    render(
      <ProductGrid
        products={brandProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={vi.fn()}
      />,
    );
    expect(screen.getByTestId('product-p-avene')).toBeInTheDocument();
    expect(screen.queryByTestId('product-p-vichy')).not.toBeInTheDocument();
    expect(screen.queryByTestId('product-p-plain')).not.toBeInTheDocument();
  });

  // (b) Chip row visible with removable chips
  it('renders a chip for each active filter in the filter-chip row', () => {
    productStoreMock.state.companyConfig = {
      all_enabled_modules: ['Merchandising'],
    } as import('@/types/companyConfig').CompanyConfig;
    const filters: FiltresFilters = {
      brands: ['Avene'],
      categories: [],
      skinTypes: [],
      routines: [],
    };
    render(
      <ProductGrid
        products={brandProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={vi.fn()}
      />,
    );
    const chipRow = screen.getByTestId('filter-chip-row');
    expect(chipRow).toBeInTheDocument();
    expect(chipRow).toHaveTextContent('Avene');
  });

  // (b) Removable chip calls onFiltersChange correctly
  it('removing a chip calls onFiltersChange with the filter removed', () => {
    productStoreMock.state.companyConfig = {
      all_enabled_modules: ['Merchandising'],
    } as import('@/types/companyConfig').CompanyConfig;
    const onFiltersChange = vi.fn();
    const filters: FiltresFilters = {
      brands: ['Avene'],
      categories: [],
      skinTypes: [],
      routines: [],
    };
    render(
      <ProductGrid
        products={brandProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={onFiltersChange}
      />,
    );
    // The Pill's onRemove button has a per-item aria-label = t('products.filterRemove', {value}) = key passthrough
    const removeBtn = screen.getByLabelText('products.filterRemove');
    fireEvent.click(removeBtn);
    expect(onFiltersChange).toHaveBeenCalledWith({
      brands: [],
      categories: [],
      skinTypes: [],
      routines: [],
    });
  });

  // (b) activeFilterCount badge shows on Filtres button
  it('shows the active-filter count badge on the Filtres button', () => {
    productStoreMock.state.companyConfig = {
      all_enabled_modules: ['Merchandising'],
    } as import('@/types/companyConfig').CompanyConfig;
    const filters: FiltresFilters = {
      brands: ['Avene', 'Vichy'],
      categories: ['Soin'],
      skinTypes: [],
      routines: [],
    };
    render(
      <ProductGrid
        products={brandProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={vi.fn()}
      />,
    );
    const btn = screen.getByTestId('filtres-button');
    // 2 brands + 1 category = 3
    expect(btn.textContent).toContain('3');
  });

  // (c) No chip row shown when module is off (even if filters prop is non-empty)
  it('does NOT render the filter-chip row when Merchandising module is disabled', () => {
    productStoreMock.state.companyConfig = null;
    const filters: FiltresFilters = {
      brands: ['Avene'],
      categories: [],
      skinTypes: [],
      routines: [],
    };
    render(
      <ProductGrid
        products={brandProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={vi.fn()}
      />,
    );
    expect(screen.queryByTestId('filter-chip-row')).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Task 27 — Skin-advice bar
// (a) skin pills toggle the shared skinTypes filter (products filtered accordingly)
// (b) customerSkinType prop auto-defaults the matching pill via onFiltersChange
// (c) bar hidden when Merchandising module is disabled
// ---------------------------------------------------------------------------

describe('ProductGrid — Task 27 Skin-advice bar', () => {
  // Shared ParapharmacyMeta baseline — only suitable_skin_types differs per product.
  const baseParapharmacyMeta = {
    equivalent_product_ids: [],
    complement_product_ids: [],
    routine_refs: [],
  };

  // Products with parapharmacy_metadata for skin-type filtering
  const skinProducts = [
    makeProduct({
      id: 'p-dry',
      name: 'Dry Skin Cream',
      sku: 'D1',
      sale_price: '12.000',
      stock_quantity: 5,
      parapharmacy_metadata: { ...baseParapharmacyMeta, suitable_skin_types: ['dry'] },
    }),
    makeProduct({
      id: 'p-oily',
      name: 'Oily Skin Gel',
      sku: 'O1',
      sale_price: '10.000',
      stock_quantity: 5,
      parapharmacy_metadata: { ...baseParapharmacyMeta, suitable_skin_types: ['oily'] },
    }),
    makeProduct({
      id: 'p-all',
      name: 'Universal Serum',
      sku: 'U1',
      sale_price: '20.000',
      stock_quantity: 5,
      parapharmacy_metadata: { ...baseParapharmacyMeta, suitable_skin_types: ['dry', 'oily', 'normal', 'combination', 'sensitive'] },
    }),
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    settingsStoreMock.state.displayMode = 'grid';
    settingsStoreMock.state.density = 'comfortable';
    settingsStoreMock.state.setDisplayMode = vi.fn();
    // Enable Merchandising module
    productStoreMock.state.companyConfig = {
      all_enabled_modules: ['Merchandising'],
    } as import('@/types/companyConfig').CompanyConfig;
    mockT.mockImplementation((key: string, opts?: { defaultValue?: string; ns?: string }) =>
      opts?.defaultValue ?? key,
    );
  });

  // (c) bar hidden when module disabled
  it('(c) hides the skin-advice bar when Merchandising module is disabled', () => {
    productStoreMock.state.companyConfig = null;
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={{ brands: [], categories: [], skinTypes: [], routines: [] }}
        onFiltersChange={vi.fn()}
      />,
    );
    expect(screen.queryByTestId('skin-advice-bar')).not.toBeInTheDocument();
  });

  // (c) bar also hidden when filters prop is absent (module may be on but no filter state)
  it('(c) hides the skin-advice bar when filters prop is absent', () => {
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
      />,
    );
    expect(screen.queryByTestId('skin-advice-bar')).not.toBeInTheDocument();
  });

  // (a) skin pills narrow the grid via filters.skinTypes
  it('(a) grid shows only products matching the active skinTypes filter', () => {
    const filters: FiltresFilters = { brands: [], categories: [], skinTypes: ['dry'], routines: [] };
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={vi.fn()}
      />,
    );
    // 'dry' filter: p-dry and p-all (has 'dry') are visible; p-oily is not
    expect(screen.getByTestId('product-p-dry')).toBeInTheDocument();
    expect(screen.getByTestId('product-p-all')).toBeInTheDocument();
    expect(screen.queryByTestId('product-p-oily')).not.toBeInTheDocument();
  });

  // (b) customerSkinType auto-defaults the skin type filter via onFiltersChange
  it('(b) customerSkinType="dry" triggers onFiltersChange to add dry to skinTypes', () => {
    const onFiltersChange = vi.fn();
    const filters: FiltresFilters = { brands: [], categories: [], skinTypes: [], routines: [] };
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={onFiltersChange}
        customerSkinType="dry"
      />,
    );
    // The useEffect fires on mount (customerSkinType changed from undefined → 'dry')
    expect(onFiltersChange).toHaveBeenCalledWith(
      expect.objectContaining({ skinTypes: ['dry'], routines: [] }),
    );
  });

  // (b) no auto-default when customerSkinType is already in the active filter
  it('(b) does NOT call onFiltersChange when customerSkinType is already active', () => {
    const onFiltersChange = vi.fn();
    // 'dry' is already in skinTypes
    const filters: FiltresFilters = { brands: [], categories: [], skinTypes: ['dry'], routines: [] };
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={onFiltersChange}
        customerSkinType="dry"
      />,
    );
    // Should NOT call onFiltersChange since 'dry' is already active
    expect(onFiltersChange).not.toHaveBeenCalled();
  });

  // (b) no auto-default when customerSkinType is null (no customer / no skin type known)
  it('(b) does NOT call onFiltersChange when customerSkinType is null', () => {
    const onFiltersChange = vi.fn();
    const filters: FiltresFilters = { brands: [], categories: [], skinTypes: [], routines: [] };
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={onFiltersChange}
        customerSkinType={null}
      />,
    );
    expect(onFiltersChange).not.toHaveBeenCalled();
  });

  // Important fix: module OFF — effect must not fire even when customerSkinType is set
  it('(b) does NOT call onFiltersChange when Merchandising module is OFF, even with customerSkinType set', () => {
    // Disable the module
    productStoreMock.state.companyConfig = null;
    const onFiltersChange = vi.fn();
    const filters: FiltresFilters = { brands: [], categories: [], skinTypes: [], routines: [] };
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={onFiltersChange}
        customerSkinType="dry"
      />,
    );
    // The isMerchandisingEnabled guard must prevent any auto-default
    expect(onFiltersChange).not.toHaveBeenCalled();
  });

  // Minor fix: pre-existing manual selection must not be overridden by customer default
  it('(b) does NOT call onFiltersChange when a different skin type is already manually selected', () => {
    const onFiltersChange = vi.fn();
    // User already selected 'oily'; customer has 'dry' — must not wipe the manual choice
    const filters: FiltresFilters = { brands: [], categories: [], skinTypes: ['oily'], routines: [] };
    render(
      <ProductGrid
        products={skinProducts}
        categories={[]}
        onAddToCart={vi.fn()}
        cartProductIds={[]}
        filters={filters}
        onFiltersChange={onFiltersChange}
        customerSkinType="dry"
      />,
    );
    // skinTypes.length > 0 guard must block the auto-default
    expect(onFiltersChange).not.toHaveBeenCalled();
  });
});
