import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { FiltresDrawer, EMPTY_FILTRES_FILTERS } from '../FiltresDrawer';
import type { FiltresDrawerProps } from '../FiltresDrawer';
import { makeProduct } from '@/test/helpers';
import type { POSProduct } from '@/types/product';

// ---------------------------------------------------------------------------
// i18n mock — key-passthrough; opts.defaultValue as fallback for missing keys.
// ---------------------------------------------------------------------------
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: { ns?: string; defaultValue?: string; count?: number }) =>
      opts?.defaultValue ?? (opts?.count !== undefined ? `${key} (${opts.count})` : key),
    i18n: {},
    ready: true,
  }),
}));

// ---------------------------------------------------------------------------
// Mock @/components/ui to avoid focus-trap + CSS DOM complexity in jsdom.
// The Pill mock renders both the clickable inner button and the remove button.
// ---------------------------------------------------------------------------
vi.mock('@/components/ui', () => ({
  Drawer: ({
    isOpen,
    children,
    title,
    footer,
  }: {
    isOpen: boolean;
    children: React.ReactNode;
    title: string;
    footer?: React.ReactNode;
  }) =>
    isOpen ? (
      <div role="dialog">
        <h2>{title}</h2>
        <div data-testid="drawer-body">{children}</div>
        {footer && <div data-testid="drawer-footer">{footer}</div>}
      </div>
    ) : null,
  Pill: ({
    children,
    selected,
    onClick,
    onRemove,
    removeLabel,
  }: {
    children: React.ReactNode;
    selected?: boolean;
    onClick?: () => void;
    onRemove?: () => void;
    removeLabel?: string;
  }) => (
    <span data-selected={selected ? 'true' : 'false'}>
      {onClick ? (
        <button type="button" aria-pressed={selected} onClick={onClick}>
          {children}
        </button>
      ) : (
        children
      )}
      {onRemove && (
        <button type="button" aria-label={removeLabel ?? 'remove'} onClick={onRemove}>
          ×
        </button>
      )}
    </span>
  ),
}));

const PARA_PRODUCTS: POSProduct[] = [
  makeProduct({
    id: 'p1',
    name: 'Avene Cream',
    sku: 'A1',
    brand_name: 'Avene',
    category: 'Soin visage',
    parapharmacy_metadata: {
      suitable_skin_types: ['dry', 'sensitive'],
      equivalent_product_ids: [],
      complement_product_ids: [],
      routine_refs: [],
    },
  }),
  makeProduct({
    id: 'p2',
    name: 'Vichy Gel',
    sku: 'V1',
    brand_name: 'Vichy',
    category: 'Gel corps',
    parapharmacy_metadata: {
      suitable_skin_types: ['oily', 'combination'],
      equivalent_product_ids: [],
      complement_product_ids: [],
      routine_refs: [],
    },
  }),
  makeProduct({
    id: 'p3',
    name: 'La Roche-Posay Serum',
    sku: 'LRP1',
    brand_name: 'La Roche-Posay',
    category: 'Soin visage',
    parapharmacy_metadata: {
      suitable_skin_types: ['sensitive'],
      equivalent_product_ids: [],
      complement_product_ids: [],
      routine_refs: [],
    },
  }),
];

function renderDrawer(overrides: Partial<FiltresDrawerProps> = {}) {
  return render(
    <FiltresDrawer
      isOpen
      onClose={vi.fn()}
      products={PARA_PRODUCTS}
      filters={EMPTY_FILTRES_FILTERS}
      onFiltersChange={vi.fn()}
      resultCount={PARA_PRODUCTS.length}
      {...overrides}
    />,
  );
}

// ---------------------------------------------------------------------------
// (a) Drawer renders brand / category / skin-type facets from a mock product set
// ---------------------------------------------------------------------------
describe('FiltresDrawer — facets from product set', () => {
  it('renders a brand pill for each distinct brand_name', () => {
    renderDrawer();
    expect(screen.getByRole('button', { name: 'Avene' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Vichy' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'La Roche-Posay' })).toBeInTheDocument();
  });

  it('renders one category pill per distinct category (deduplicated)', () => {
    renderDrawer();
    // 'Soin visage' appears for p1 + p3 but must render exactly ONE pill.
    const soins = screen.getAllByRole('button', { name: 'Soin visage' });
    expect(soins).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Gel corps' })).toBeInTheDocument();
  });

  it('renders skin-type pills for all distinct skin types across products', () => {
    renderDrawer();
    // Skin types from products: dry, sensitive, oily, combination.
    // t('skin_type.X', { defaultValue: X }) returns X via mock.
    expect(screen.getByRole('button', { name: 'dry' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'sensitive' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'oily' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'combination' })).toBeInTheDocument();
  });

  it('renders routine step-label facets without exposing routine UUIDs', () => {
    const withRoutines: POSProduct[] = [
      makeProduct({
        id: 'pr1',
        name: 'Routine Product',
        sku: 'R1',
        parapharmacy_metadata: {
          suitable_skin_types: [],
          equivalent_product_ids: [],
          complement_product_ids: [],
          routine_refs: [{ routine_id: 'r-uuid-1', step_order: 1, step_label: 'Nettoyant' }],
        },
      }),
    ];
    renderDrawer({ products: withRoutines });
    expect(screen.getByRole('button', { name: 'Nettoyant' })).toBeInTheDocument();
    expect(screen.queryByText('r-uuid-1')).not.toBeInTheDocument();
  });

  it('shows the empty-state message when no products have parapharmacy facets', () => {
    const plain: POSProduct[] = [
      makeProduct({ id: 'pp1', name: 'Plain Product', sku: 'PP1' }),
    ];
    renderDrawer({ products: plain });
    expect(screen.getByText('products.filtersNoFacets')).toBeInTheDocument();
  });

  it('calls onFiltersChange when a brand pill is clicked', () => {
    const onFiltersChange = vi.fn();
    renderDrawer({ onFiltersChange });
    fireEvent.click(screen.getByRole('button', { name: 'Avene' }));
    expect(onFiltersChange).toHaveBeenCalledWith({
      brands: ['Avene'],
      categories: [],
      skinTypes: [],
      routines: [],
    });
  });

  it('deselects a brand when the pill is clicked again', () => {
    const onFiltersChange = vi.fn();
    renderDrawer({
      filters: { brands: ['Avene'], categories: [], skinTypes: [], routines: [] },
      onFiltersChange,
    });
    fireEvent.click(screen.getByRole('button', { name: 'Avene' }));
    expect(onFiltersChange).toHaveBeenCalledWith({
      brands: [],
      categories: [],
      skinTypes: [],
      routines: [],
    });
  });

  it('shows the live result count in the footer', () => {
    renderDrawer({ resultCount: 2 });
    // The t mock renders 'products.filtersResultCount (2)' for count interpolation
    const footer = screen.getByTestId('drawer-footer');
    expect(footer.textContent).toContain('2');
  });

  it('shows the clear-all button when filters are active', () => {
    renderDrawer({
      filters: { brands: ['Avene'], categories: [], skinTypes: [], routines: [] },
    });
    expect(screen.getByText('products.filtersClearAll')).toBeInTheDocument();
  });

  it('hides the clear-all button when no filters are active', () => {
    renderDrawer({ filters: EMPTY_FILTRES_FILTERS });
    expect(screen.queryByText('products.filtersClearAll')).not.toBeInTheDocument();
  });

  it('calls onFiltersChange with EMPTY_FILTRES_FILTERS when clear-all is clicked', () => {
    const onFiltersChange = vi.fn();
    renderDrawer({
      filters: { brands: ['Avene'], categories: ['Soin visage'], skinTypes: ['dry'], routines: [] },
      onFiltersChange,
    });
    fireEvent.click(screen.getByText('products.filtersClearAll'));
    expect(onFiltersChange).toHaveBeenCalledWith(EMPTY_FILTRES_FILTERS);
  });

  it('renders nothing when closed', () => {
    renderDrawer({ isOpen: false });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
