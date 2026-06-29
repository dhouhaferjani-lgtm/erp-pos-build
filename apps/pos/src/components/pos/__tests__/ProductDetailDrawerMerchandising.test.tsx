/**
 * Task 28 — Merchandising tabs in the product detail drawer.
 *
 * Coverage:
 *   (a) Équivalents/Compléments tabs resolve id arrays → product rows
 *   (b) Routine tab reconstructs ordered routine steps from shared routine_id
 *   (c) Tapping a row calls addItemGated
 *   (d) Tabs hidden when Merchandising module is NOT enabled
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ProductDetailDrawer } from '@/components/pos/ProductDetailDrawer';
import type { POSProduct } from '@/types/product';
import type { CompanyConfig } from '@/types/companyConfig';

// ---------------------------------------------------------------------------
// i18n / currency stubs
// ---------------------------------------------------------------------------
vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({ t: (k: string) => k }),
}));
vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (n: string | number) => String(n) }),
}));

// ---------------------------------------------------------------------------
// Stub network-dependent hooks so the test never hits async boundaries.
// ---------------------------------------------------------------------------
vi.mock('@/hooks/useCrossLocationStock', () => ({
  useCrossLocationStock: () => ({
    data: null, source: null, fetchedAt: null, isStale: false,
    isLoading: false, error: null, refresh: () => {},
  }),
}));
vi.mock('@/hooks/useProductVariants', () => ({
  useProductVariants: () => ({ variants: [], isLoading: false, status: 'idle' }),
}));

// ---------------------------------------------------------------------------
// cartIngress — capture addItemGated calls
// ---------------------------------------------------------------------------
const addItemGatedMock = vi.fn().mockResolvedValue(true);
vi.mock('@/lib/stock/cartIngress', () => ({
  addItemGated: (...args: unknown[]) => addItemGatedMock(...args),
}));

// ---------------------------------------------------------------------------
// productStore mock — hoisted so state can be mutated per test.
// ---------------------------------------------------------------------------
const productStoreMock = vi.hoisted(() => ({
  state: {
    companyConfig: null as CompanyConfig | null,
    products: [] as POSProduct[],
    getByIds: (_ids: string[]): POSProduct[] => [],
    allow_cross_location_stock_view: false,
  },
}));

vi.mock('@/stores/productStore', () => ({
  useProductStore: vi.fn(
    <T,>(
      selector: (s: typeof productStoreMock.state) => T,
    ): T => selector(productStoreMock.state),
  ),
  hasModule: (
    config: CompanyConfig | null,
    moduleName: string,
  ): boolean => config?.all_enabled_modules?.includes(moduleName) ?? false,
}));

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: vi.fn(() => false),
}));
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: vi.fn(() => null),
}));

// ---------------------------------------------------------------------------
// Fixture data
// ---------------------------------------------------------------------------
const makeParaProduct = (id: string, name: string, overrides: Partial<POSProduct> = {}): POSProduct => ({
  id,
  name,
  sku: `SKU-${id}`,
  sale_price: '10.00',
  stock_quantity: 5,
  parapharmacy_metadata: {
    suitable_skin_types: [],
    equivalent_product_ids: [],
    complement_product_ids: [],
    routine_refs: [],
  },
  ...overrides,
});

/** The "current" product shown in the drawer — in routine R1 at step 2. */
const currentProduct: POSProduct = makeParaProduct('p1', 'Crème Hydratante', {
  parapharmacy_metadata: {
    suitable_skin_types: [],
    equivalent_product_ids: ['p2', 'p3'],
    complement_product_ids: ['p4'],
    routine_refs: [{ routine_id: 'r1', step_order: 2, step_label: 'Hydratation' }],
  },
});

/** Equivalent #1 */
const equivProduct2: POSProduct = makeParaProduct('p2', 'Sérum Hydratant');
/** Equivalent #2 */
const equivProduct3: POSProduct = makeParaProduct('p3', 'Lotion Tonique');
/** Complement */
const complementProduct4: POSProduct = makeParaProduct('p4', 'Contour Yeux');

/** Routine step 1 (comes BEFORE currentProduct) */
const routineStep1: POSProduct = makeParaProduct('p5', 'Nettoyant Doux', {
  parapharmacy_metadata: {
    suitable_skin_types: [],
    equivalent_product_ids: [],
    complement_product_ids: [],
    routine_refs: [{ routine_id: 'r1', step_order: 1, step_label: 'Nettoyage' }],
  },
});

/** Routine step 3 (comes AFTER currentProduct) */
const routineStep3: POSProduct = makeParaProduct('p6', 'Fluide Solaire SPF50', {
  parapharmacy_metadata: {
    suitable_skin_types: [],
    equivalent_product_ids: [],
    complement_product_ids: [],
    routine_refs: [{ routine_id: 'r1', step_order: 3, step_label: 'Protection' }],
  },
});

const configWithMerchandising: CompanyConfig = {
  all_enabled_modules: ['Merchandising'],
};

const configWithoutMerchandising: CompanyConfig = {
  all_enabled_modules: [],
};

// ---------------------------------------------------------------------------
// beforeEach — reset mocks and set up default store state
// ---------------------------------------------------------------------------
beforeEach(() => {
  addItemGatedMock.mockClear();
  const catalog = [currentProduct, equivProduct2, equivProduct3, complementProduct4, routineStep1, routineStep3];
  productStoreMock.state = {
    companyConfig: configWithMerchandising,
    products: catalog,
    getByIds: (ids: string[]) =>
      ids
        .map((id) => catalog.find((p) => p.id === id))
        .filter((p): p is POSProduct => p !== undefined),
    allow_cross_location_stock_view: false,
  };
});

// ---------------------------------------------------------------------------
// Render helper
// ---------------------------------------------------------------------------
function renderDrawer(product: POSProduct | null = currentProduct) {
  return render(
    <ProductDetailDrawer
      isOpen
      product={product}
      onClose={() => {}}
      locationStock={null}
    />,
  );
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
describe('ProductDetailDrawer — Merchandising tabs (Task 28)', () => {
  // (d) Gate: tabs must not appear without the module
  it('hides merchandising tabs when Merchandising module is NOT enabled', () => {
    productStoreMock.state.companyConfig = configWithoutMerchandising;
    renderDrawer();
    expect(screen.queryByRole('tablist', { name: 'productDetail.merchandising.ariaLabel' })).toBeNull();
    expect(screen.queryByText('productDetail.merchandising.equivalents')).toBeNull();
  });

  // Module enabled but product has no parapharmacy_metadata → no tabs
  it('hides merchandising tabs when product has no parapharmacy_metadata', () => {
    const plainProduct = makeParaProduct('px', 'Plain Product', { parapharmacy_metadata: undefined });
    renderDrawer(plainProduct);
    expect(screen.queryByRole('tablist', { name: 'productDetail.merchandising.ariaLabel' })).toBeNull();
  });

  // (a) Équivalents tab
  describe('Équivalents tab', () => {
    it('shows the tab strip when Merchandising is enabled', () => {
      renderDrawer();
      expect(screen.getByRole('tablist', { name: 'productDetail.merchandising.ariaLabel' })).toBeTruthy();
      // Équivalents tab is the default active tab
      expect(screen.getByRole('tab', { name: /productDetail\.merchandising\.equivalents/ })).toBeTruthy();
    });

    it('resolves equivalent_product_ids and renders a row per product', () => {
      renderDrawer();
      // Default tab is Équivalents — p2 and p3 should be visible
      expect(screen.getByText('Sérum Hydratant')).toBeTruthy();
      expect(screen.getByText('Lotion Tonique')).toBeTruthy();
    });

    it('shows an empty state when there are no equivalents', () => {
      const noEquivProduct = makeParaProduct('pX', 'No-Equiv Product', {
        parapharmacy_metadata: {
          suitable_skin_types: [],
          equivalent_product_ids: [],
          complement_product_ids: [],
          routine_refs: [],
        },
      });
      productStoreMock.state.products = [noEquivProduct];
      productStoreMock.state.getByIds = () => [];
      renderDrawer(noEquivProduct);
      expect(screen.getByText('productDetail.merchandising.empty')).toBeTruthy();
    });
  });

  // (a) Compléments tab
  describe('Compléments tab', () => {
    it('resolves complement_product_ids when the Compléments tab is selected', () => {
      renderDrawer();
      // Click Compléments tab
      fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.complements/ }));
      expect(screen.getByText('Contour Yeux')).toBeTruthy();
    });

    it('does NOT show Équivalents rows on the Compléments tab', () => {
      renderDrawer();
      fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.complements/ }));
      expect(screen.queryByText('Sérum Hydratant')).toBeNull();
    });
  });

  // (b) Routine tab
  describe('Routine tab', () => {
    it('reconstructs ordered routine steps from products sharing the same routine_id', () => {
      renderDrawer();
      fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.routine/ }));
      // All three steps (step_order 1, 2, 3) should appear
      const rows = screen.getAllByTestId('routine-step-row');
      expect(rows).toHaveLength(3);
      // They should be in step_order, so p5 (step 1) first, p1 (step 2) second, p6 (step 3) last
      expect(rows[0]).toHaveTextContent('Nettoyant Doux');
      expect(rows[1]).toHaveTextContent('Crème Hydratante');
      expect(rows[2]).toHaveTextContent('Fluide Solaire SPF50');
    });

    it('shows the step_label for each routine row', () => {
      renderDrawer();
      fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.routine/ }));
      expect(screen.getByText('Nettoyage')).toBeTruthy();
      expect(screen.getByText('Hydratation')).toBeTruthy();
      expect(screen.getByText('Protection')).toBeTruthy();
    });

    it('shows an empty state when the product is in no routines', () => {
      const noRoutineProduct = makeParaProduct('pR', 'No-Routine Product', {
        parapharmacy_metadata: {
          suitable_skin_types: [],
          equivalent_product_ids: [],
          complement_product_ids: [],
          routine_refs: [],
        },
      });
      productStoreMock.state.products = [noRoutineProduct];
      productStoreMock.state.getByIds = () => [];
      renderDrawer(noRoutineProduct);
      fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.routine/ }));
      expect(screen.getByText('productDetail.merchandising.empty')).toBeTruthy();
    });
  });

  // (c) Add-to-cart
  describe('Add-to-cart', () => {
    it('calls addItemGated when an Équivalents row is tapped', async () => {
      renderDrawer();
      // Équivalents tab is active by default; find the add button for p2
      const addButtons = screen.getAllByTestId('merch-add-btn');
      fireEvent.click(addButtons[0]!);
      await waitFor(() => expect(addItemGatedMock).toHaveBeenCalledTimes(1));
      expect(addItemGatedMock).toHaveBeenCalledWith(equivProduct2);
    });

    it('calls addItemGated with the correct product from the Compléments tab', async () => {
      renderDrawer();
      fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.complements/ }));
      const addButtons = screen.getAllByTestId('merch-add-btn');
      fireEvent.click(addButtons[0]!);
      await waitFor(() => expect(addItemGatedMock).toHaveBeenCalledWith(complementProduct4));
    });

    it('calls addItemGated with the correct product from the Routine tab', async () => {
      renderDrawer();
      fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.routine/ }));
      // Click the first routine step (Nettoyant Doux = routineStep1)
      const addButtons = screen.getAllByTestId('merch-add-btn');
      fireEvent.click(addButtons[0]!);
      await waitFor(() => expect(addItemGatedMock).toHaveBeenCalledWith(routineStep1));
    });
  });
});
