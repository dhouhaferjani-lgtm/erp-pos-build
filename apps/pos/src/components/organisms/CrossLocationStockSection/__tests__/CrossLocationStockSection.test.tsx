import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({ t: (k: string, o?: { time?: string }) => (o?.time !== undefined ? `${k}:${o.time}` : k) }),
}));

const hookMock = vi.fn();
vi.mock('@/hooks/useCrossLocationStock', () => ({ useCrossLocationStock: (...a: unknown[]) => hookMock(...a) }));

const variantsMock = vi.fn((..._a: unknown[]) => ({ data: [], isLoading: false }));
vi.mock('@/hooks/useProductVariants', () => ({ useProductVariants: (...a: unknown[]) => variantsMock(...a) }));

import { CrossLocationStockSection } from '@/components/organisms/CrossLocationStockSection/CrossLocationStockSection';
import type { POSProduct } from '@/types/product';

const product = { id: 'p1', name: 'Widget', has_variants: false } as POSProduct;

const idleHook = {
  data: null,
  source: null,
  fetchedAt: null,
  isStale: false,
  isLoading: false,
  error: null,
  refresh: vi.fn(),
};

describe('CrossLocationStockSection gating', () => {
  it('renders nothing when not allowed', () => {
    hookMock.mockReturnValue(idleHook);
    const { container } = render(
      <CrossLocationStockSection product={product} canView={false} currentLocationId="l1" />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('renders location rows when data present', () => {
    hookMock.mockReturnValue({
      data: {
        product_id: 'p1',
        variant_id: null,
        variant_label: null,
        locations: [
          {
            location_id: 'l1',
            location_name: 'Shop A',
            location_type: 'shop',
            is_current: true,
            on_hand: '12.0000',
            incoming_transfer: '5.0000',
          },
        ],
        totals: { on_hand: '12.0000', incoming_transfer: '5.0000' },
        as_of: '2026-06-14T10:00:00Z',
      },
      source: 'live',
      fetchedAt: '2026-06-14T10:00:00Z',
      isStale: false,
      isLoading: false,
      error: null,
      refresh: vi.fn(),
    });
    render(<CrossLocationStockSection product={product} canView currentLocationId="l1" />);
    // is_current row is prefixed with an arrow marker, so match a substring.
    expect(screen.getByText(/Shop A/)).toBeInTheDocument();
    expect(screen.getByTestId('xloc-table')).toBeInTheDocument();
  });
});

describe('CrossLocationStockSection variant selector', () => {
  it('renders a variant <select> for variant products', () => {
    hookMock.mockReturnValue(idleHook);
    variantsMock.mockReturnValueOnce({
      data: [
        { id: 'v1', name_suffix: ' — S', is_default: true, display_order: 1 },
        { id: 'v2', name_suffix: ' — M', is_default: false, display_order: 2 },
      ],
      isLoading: false,
    } as unknown as { data: []; isLoading: boolean });
    const variantProduct = { id: 'p1', name: 'Shoe', has_variants: true } as POSProduct;
    render(<CrossLocationStockSection product={variantProduct} canView currentLocationId="l1" />);
    expect(screen.getByRole('combobox')).toBeInTheDocument();
    expect(screen.getAllByRole('option')).toHaveLength(2);
  });
});

describe('CrossLocationStockSection freshness', () => {
  it('shows the "as of" text when fetchedAt is present', () => {
    hookMock.mockReturnValue({
      ...idleHook,
      data: {
        product_id: 'p1',
        variant_id: null,
        variant_label: null,
        locations: [],
        totals: { on_hand: '0.0000', incoming_transfer: '0.0000' },
        as_of: '2026-06-14T10:00:00Z',
      },
      source: 'cache',
      fetchedAt: '2026-06-14T10:00:00Z',
    });
    render(<CrossLocationStockSection product={product} canView currentLocationId="l1" />);
    // t echoes 'crossLocationStock.asOf:<relativeTime>' — assert the key prefix.
    expect(screen.getByText(/crossLocationStock\.asOf:/)).toBeInTheDocument();
  });
});
