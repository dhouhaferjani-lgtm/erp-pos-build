/**
 * Task 13 — ProductTable (the "Tableau" desktop-dense view).
 *
 * Mirrors the mocking convention of `ProductGrid.test.tsx` and
 * `ProductListRow.test.tsx`:
 *   - react-i18next returns raw keys, except `products.outOfStock` → real fr
 *     copy ("Rupture") and the `table.col*` header keys → their real fr labels
 *     so the header assertions read production copy.
 *   - `@tanstack/react-virtual` is stubbed to render EVERY row (jsdom has no
 *     layout, so the real virtualizer would render an unstable subset). Real
 *     virtualization (no clip, sticky header, 5-10k perf) is verified in the
 *     Task 20 Playwright pass, NOT here.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductTable, type ProductTableProps } from '../ProductTable';
import { tokens } from '@/lib/designTokens';
import { makeProduct } from '@/test/helpers';

vi.mock('react-i18next', async (importActual) => {
  const actual = await importActual<typeof import('react-i18next')>();
  const LABELS: Record<string, string> = {
    'products.outOfStock': 'Rupture',
    'table.colCode': 'Code',
    'table.colProduct': 'Produit',
    'table.colCategory': 'Catégorie',
    'table.colStock': 'Stock',
    'table.colPrice': 'Prix',
  };
  return {
    ...actual,
    useTranslation: () => ({
      t: (key: string, opts?: Record<string, unknown>) => {
        if (key in LABELS) return LABELS[key];
        if (opts?.count !== undefined) return `${key}:${String(opts.count)}`;
        return key;
      },
    }),
  };
});

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    decimals: 2,
    format: (amount: number | string) => `${String(amount)} EUR`,
  }),
}));

vi.mock('@/lib/images/useProductImage', () => ({
  useProductImage: () => null,
}));

// Render every row — jsdom has no layout for the real virtualizer.
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
    scrollToIndex: vi.fn(),
    measure: vi.fn(),
    measureElement: vi.fn(),
  }),
}));

const p1 = makeProduct({ id: 'p1', name: 'Alpha', sku: 'A-001', sale_price: '10.00', category: 'Electronics' });
const p2 = makeProduct({ id: 'p2', name: 'Beta', sku: 'B-001', sale_price: '20.00', category: 'Accessoires' });
const p3 = makeProduct({ id: 'p3', name: 'Gamma', sku: 'G-001', sale_price: '15.00', category: 'Electronics' });

function renderTable(overrides: Partial<ProductTableProps> = {}) {
  const defaults: ProductTableProps = {
    products: [p1, p2, p3],
    onAddToCart: vi.fn(),
  };
  return render(<ProductTable {...defaults} {...overrides} />);
}

describe('ProductTable', () => {
  it('renders the column headers Code / Produit / Catégorie / Stock / Prix', () => {
    renderTable();
    expect(screen.getByText('Code')).toBeTruthy();
    expect(screen.getByText('Produit')).toBeTruthy();
    expect(screen.getByText('Catégorie')).toBeTruthy();
    expect(screen.getByText('Stock')).toBeTruthy();
    expect(screen.getByText('Prix')).toBeTruthy();
  });

  it('renders product names with the shared tokens.productName recipe (unified typography)', () => {
    renderTable();
    const name = screen.getByText('Alpha');
    expect(name.className).toContain(tokens.productName.base);
    expect(name.className).toContain('truncate');
  });

  it('renders price cells in ink (data, not accent), not faint for in-stock rows', () => {
    renderTable();
    const priceCells = screen.getAllByTestId('price-row');
    expect(priceCells).toHaveLength(3);
    expect(priceCells[0]!.className).toContain('text-ink');
    expect(priceCells[0]!.className).not.toContain('text-ink-faint');
  });

  it('Enter on the focused row adds that product to cart', () => {
    const onAddToCart = vi.fn();
    renderTable({ onAddToCart });
    const firstRow = screen.getAllByRole('row')[1]!; // [0] = header
    firstRow.focus();
    fireEvent.keyDown(firstRow, { key: 'Enter' });
    expect(onAddToCart).toHaveBeenCalledWith(p1);
  });

  it('ArrowDown then Enter adds the NEXT product to cart', () => {
    const onAddToCart = vi.fn();
    renderTable({ onAddToCart });
    const firstRow = screen.getAllByRole('row')[1]!;
    firstRow.focus();
    fireEvent.keyDown(firstRow, { key: 'ArrowDown' });
    // roving focus has moved to the second data row
    fireEvent.keyDown(document.activeElement as Element, { key: 'Enter' });
    expect(onAddToCart).toHaveBeenCalledWith(p2);
  });

  it('out-of-stock + hardBlockOutOfStock disables the row add and Enter does not add', () => {
    const onAddToCart = vi.fn();
    renderTable({
      onAddToCart,
      hardBlockOutOfStock: true,
      locationStock: {
        p1: { available: '0', incoming_transfer: '0', incoming_po: '0' },
        p2: { available: '5', incoming_transfer: '0', incoming_po: '0' },
        p3: { available: '5', incoming_transfer: '0', incoming_po: '0' },
      },
    });

    const addButtons = screen.getAllByTestId('add-button');
    expect(addButtons[0]!.getAttribute('aria-disabled')).toBe('true');

    const firstRow = screen.getAllByRole('row')[1]!;
    firstRow.focus();
    fireEvent.keyDown(firstRow, { key: 'Enter' });
    expect(onAddToCart).not.toHaveBeenCalled();

    // still shows the "Rupture" stock state + a price cell
    expect(screen.getByText('Rupture')).toBeTruthy();
    expect(screen.getAllByTestId('price-row')[0]).toBeInTheDocument();
  });

  it('renders a visible view-details eye button per row and calls onViewDetails without adding to cart', () => {
    const onViewDetails = vi.fn();
    const onAddToCart = vi.fn();
    renderTable({ onViewDetails, onAddToCart });

    const eyeButtons = screen.getAllByTestId('view-details-button');
    expect(eyeButtons).toHaveLength(3);

    eyeButtons[0]!.click();
    expect(onViewDetails).toHaveBeenCalledTimes(1);
    expect(onViewDetails).toHaveBeenCalledWith(p1);
    expect(onAddToCart).not.toHaveBeenCalled();
  });

  it('does not render the eye button when onViewDetails is not provided', () => {
    renderTable({ onViewDetails: undefined });
    expect(screen.queryByTestId('view-details-button')).not.toBeInTheDocument();
  });
});
