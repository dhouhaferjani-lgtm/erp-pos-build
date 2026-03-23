import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductGrid, type ProductGridProps } from '../ProductGrid';
import { makeProduct } from '@/test/helpers';

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

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
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
