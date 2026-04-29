import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { useTranslation } from 'react-i18next';
import { ProductGrid, type ProductGridProps } from '../ProductGrid';
import { makeProduct } from '@/test/helpers';
import type { POSProduct } from '@/types/product';

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
});
