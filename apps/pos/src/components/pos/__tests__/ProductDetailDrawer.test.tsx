import { describe, it, expect, vi, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { ProductDetailSheet, type DetailTab } from '@/components/pos/ProductDetailDrawer';
import type { POSProduct } from '@/types/product';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    // Interpolation-aware stub: the unified stock badge goes through
    // t('products.stock', { count }) (useStockDisplay), so the mock must
    // surface the count or the "Stock N" assertions can't distinguish the
    // unified recipe from a bare number.
    t: (k: string, opts?: Record<string, unknown>) =>
      opts && 'count' in opts ? `${k}:${String(opts.count)}` : k,
  }),
}));
// Configurable currency stub: defaults to the identity formatter (legacy
// fixture behavior); the hero-price suite swaps in real formatter shapes
// ("38,50 €" / "9,990 DT") to pin that the drawer renders formatter output
// verbatim.
const currencyState = vi.hoisted(() => ({
  format: (n: string | number): string => `${n}`,
}));
vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({ format: (n: string | number) => currencyState.format(n) }),
}));
// F8 — the drawer now renders CrossLocationStockSection, which pulls two network
// hooks (one wraps useQuery and needs a QueryClientProvider). Stub them so this
// own-location test stays isolated; the gate (canView=false from the default
// null operator/company stores) keeps the section empty anyway.
vi.mock('@/hooks/useCrossLocationStock', () => ({
  useCrossLocationStock: () => ({
    data: null, source: null, fetchedAt: null, isStale: false,
    isLoading: false, error: null, refresh: () => {},
  }),
}));
vi.mock('@/hooks/useProductVariants', () => ({
  useProductVariants: () => ({ variants: [], isLoading: false, status: 'idle' }),
}));

const product = {
  id: 'p1',
  name: 'Widget',
  sku: 'W1',
  barcode: '6191234567890',
  category: 'Visage',
  brand_name: 'Avene',
  sale_price: '9.990',
  stock_quantity: 999,
  description: 'Hydrates and protects sensitive skin.',
  parapharmacy_metadata: {
    suitable_skin_types: ['sensitive'],
    equivalent_product_ids: [],
    complement_product_ids: [],
    routine_refs: [],
  },
} as POSProduct;

/**
 * The overlay host owned tab state; post-deletion HomePage owns it. This
 * harness reproduces that ownership for the suites that click tabs.
 */
function SheetHarness({
  product: harnessProduct,
  locationStock,
  hardBlockOutOfStock,
  onClose = () => {},
}: {
  product: POSProduct | null;
  locationStock?: Parameters<typeof ProductDetailSheet>[0]['locationStock'];
  hardBlockOutOfStock?: boolean;
  onClose?: () => void;
}) {
  const [tab, setTab] = useState<DetailTab>('details');
  if (!harnessProduct) return null;
  return (
    <ProductDetailSheet
      product={harnessProduct}
      onClose={onClose}
      locationStock={locationStock}
      hardBlockOutOfStock={hardBlockOutOfStock}
      activeTab={tab}
      onTabChange={setTab}
    />
  );
}

describe('ProductDetailDrawer own-location stock', () => {
  it('renders a centered two-column modal instead of a right drawer', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);

    const modal = screen.getByTestId('product-detail-modal');
    expect(modal).toHaveClass('w-[1080px]');
    expect(modal).toHaveClass('max-w-[96vw]');
    expect(screen.getByTestId('product-detail-left-column')).toBeInTheDocument();
    expect(screen.getByTestId('product-detail-right-column')).toBeInTheDocument();
  });

  it('opens on the Details tab and shows price, barcode, benefits, and the primary add action', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);

    expect(screen.getByRole('tab', { name: /productDetail\.tabs\.details/ })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByText('productDetail.priceTtc')).toBeInTheDocument();
    expect(screen.getByText('6191234567890')).toBeInTheDocument();
    expect(screen.getByText('skin_type.sensitive')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'productDetail.addToCart' })).toHaveClass('h-[54px]');
  });

  it('renders available from locationStock slice, not legacy stock_quantity', () => {
    render(<SheetHarness product={product} onClose={() => {}}
      locationStock={{ available: '8.0000', incoming_transfer: '2.0000', incoming_po: '0.0000' }} />);
    expect(screen.queryByText('999')).toBeNull();
    expect(screen.getByTestId('drawer-stock-row')).toHaveTextContent('8');
  });

  it('renders no stock chrome when slice is null (exempt)', () => {
    render(<SheetHarness product={product} onClose={() => {}} locationStock={null} />);
    expect(screen.queryByTestId('drawer-stock-row')).toBeNull();
  });

  it('falls back to legacy stock_quantity when slice is undefined', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    expect(screen.getByTestId('drawer-stock-row')).toHaveTextContent('999');
  });

  // Owner review defect 3 — the bare "• 14" chip: the drawer must use the
  // SAME unified stock language as the grid surfaces (useStockDisplay →
  // t('products.stock', { count }) / t('products.outOfStock')), not a bare
  // unlabelled number.
  it('renders the unified grid stock language (products.stock + count) for the slice path', () => {
    render(<SheetHarness product={product} onClose={() => {}}
      locationStock={{ available: '14.0000', incoming_transfer: '0.0000', incoming_po: '0.0000' }} />);
    expect(screen.getByTestId('drawer-stock-row')).toHaveTextContent('products.stock:14');
  });

  it('renders the unified grid stock language for the legacy stock_quantity path', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    expect(screen.getByTestId('drawer-stock-row')).toHaveTextContent('products.stock:999');
  });

  it('renders products.outOfStock (unified language) when the slice is depleted', () => {
    render(<SheetHarness product={product} onClose={() => {}}
      locationStock={{ available: '0.0000', incoming_transfer: '0.0000', incoming_po: '0.0000' }} />);
    expect(screen.getByTestId('drawer-stock-row')).toHaveTextContent('products.outOfStock');
  });
});

// Owner review defect 2 — zero-count tabs must not announce emptiness: the
// tab stays tappable but the count badge only renders when > 0.
describe('ProductDetailDrawer — zero-count tab badges', () => {
  it('hides the count badge when the count is 0 (tab label stays, no "0")', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    const equivalents = screen.getByRole('tab', { name: /productDetail\.merchandising\.equivalents/ });
    expect(equivalents.textContent).toBe('productDetail.merchandising.equivalents');
    const complements = screen.getByRole('tab', { name: /productDetail\.merchandising\.complements/ });
    expect(complements.textContent).toBe('productDetail.merchandising.complements');
    const routine = screen.getByRole('tab', { name: /productDetail\.merchandising\.routine/ });
    expect(routine.textContent).toBe('productDetail.merchandising.routine');
  });

  it('keeps zero-count tabs tappable (panel still opens)', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    fireEvent.click(screen.getByRole('tab', { name: /productDetail\.merchandising\.equivalents/ }));
    expect(screen.getByRole('tab', { name: /productDetail\.merchandising\.equivalents/ }))
      .toHaveAttribute('aria-selected', 'true');
  });
});

// Owner review defect 1 — the close X must never overlap the tab strip: it is
// a flex sibling reserving its own gutter (not absolutely positioned over the
// tabs), and the tab strip scrolls horizontally instead of wrapping.
describe('ProductDetailDrawer — close button / tab strip structure', () => {
  it('renders the close X as a non-absolute flex sibling of the tablist (reserved gutter)', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    const closeBtn = screen.getByRole('button', { name: 'products.filtersClose' });
    expect(closeBtn.className).not.toContain('absolute');
    const tablist = screen.getByRole('tablist');
    expect(closeBtn.parentElement).toBe(tablist.parentElement);
    expect(closeBtn.className).toContain('shrink-0');
  });

  it('lets the tab strip overflow horizontally with no mid-label wrapping', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    const tablist = screen.getByRole('tablist');
    expect(tablist.className).toContain('overflow-x-auto');
    expect(tablist.className).toContain('min-w-0');
    for (const tab of screen.getAllByRole('tab')) {
      expect(tab.className).toContain('whitespace-nowrap');
      expect(tab.className).toContain('shrink-0');
      expect(tab.className).toContain('min-h-12');
    }
  });
});

// Owner review defect 4 — one coherent sheet: single container surface +
// radius + shadow with an INSET internal divider between the panes, never a
// gap exposing the backdrop or per-pane card chrome.
describe('ProductDetailDrawer — one-sheet container', () => {
  it('keeps the single-surface contract on the modal container', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    const modal = screen.getByTestId('product-detail-modal');
    expect(modal.className).toContain('bg-surface-overlay');
    expect(modal.className).toContain('shadow-2xl');
    expect(modal.className).toContain('overflow-hidden');
    expect(modal.className).toMatch(/rounded-/);
    expect(modal.className).not.toMatch(/\bgap-/);
  });

  it('separates the panes with an inset internal divider, not an edge-to-edge pane border', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    const divider = screen.getByTestId('drawer-pane-divider');
    expect(divider.className).toContain('w-px');
    expect(divider.className).toContain('bg-border-subtle');
    expect(divider.className).toMatch(/\bmy-/);
    const left = screen.getByTestId('product-detail-left-column');
    expect(left.className).not.toContain('border-r');
    expect(left.className).not.toMatch(/\bshadow/);
  });
});

// Task 18 — restyle + new tab shells + OOS-policy alignment
describe('ProductDetailDrawer — tab shells (Task 18)', () => {
  it('shows all five tabs, including the Stock & lots and Autres officines shells', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    expect(screen.getByRole('tab', { name: 'productDetail.tabs.details' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /productDetail\.merchandising\.routine/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /productDetail\.merchandising\.equivalents/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /productDetail\.merchandising\.complements/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'productDetail.tabs.stockLots' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'productDetail.tabs.otherBranches' })).toBeInTheDocument();
  });

  it('renders a placeholder (not batch data) on the Stock & lots tab', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    fireEvent.click(screen.getByRole('tab', { name: 'productDetail.tabs.stockLots' }));
    expect(screen.getByText('productDetail.tabs.stockLotsComingSoon')).toBeInTheDocument();
    expect(screen.queryByTestId('routine-step-row')).toBeNull();
    expect(screen.queryByTestId('merch-add-btn')).toBeNull();
  });

  it('renders a placeholder (not branch data) on the Autres officines tab', () => {
    render(<SheetHarness product={product} onClose={() => {}} />);
    fireEvent.click(screen.getByRole('tab', { name: 'productDetail.tabs.otherBranches' }));
    expect(screen.getByText('productDetail.tabs.otherBranchesComingSoon')).toBeInTheDocument();
  });
});

describe('ProductDetailDrawer — OOS-policy alignment (Task 18)', () => {
  const outOfStockProduct = { ...product, stock_quantity: 0 } as POSProduct;

  it('disables add-to-cart when out of stock and hardBlockOutOfStock is true (default, block policy)', () => {
    render(<SheetHarness product={outOfStockProduct} onClose={() => {}} />);
    expect(screen.getByRole('button', { name: 'productDetail.addToCart' })).toBeDisabled();
  });

  it('enables add-to-cart when out of stock but hardBlockOutOfStock is false (warn/off policy) so the tap reaches the stock gate', () => {
    render(
      <SheetHarness
        product={outOfStockProduct}
        onClose={() => {}}
        hardBlockOutOfStock={false}
      />,
    );
    expect(screen.getByRole('button', { name: 'productDetail.addToCart' })).not.toBeDisabled();
  });

  it('never disables when stock-exempt (locationStock null), regardless of policy', () => {
    render(
      <SheetHarness
        product={outOfStockProduct}
        onClose={() => {}}
        locationStock={null}
      />,
    );
    expect(screen.getByRole('button', { name: 'productDetail.addToCart' })).not.toBeDisabled();
  });
});

// The hero price must render EXACTLY what the currency formatter emits — no
// hardcoded 'DT' suffix (repo rule 11). The formatter already emits the
// marker for every currency (TND/fr-TN → "9,990 DT", EUR/fr-FR → "38,50 €");
// the old suffix hack double-rendered "38,50 € DT" under any non-TND config.
describe('ProductDetailDrawer — hero price trusts the currency formatter', () => {
  afterEach(() => {
    currencyState.format = (n: string | number): string => `${n}`;
  });

  it('renders a non-DT formatter output (EUR) verbatim with no appended DT', () => {
    currencyState.format = () => '38,50 €';
    render(<SheetHarness product={product} onClose={() => {}} />);
    const price = screen.getByText('38,50 €');
    expect(price.textContent).toBe('38,50 €');
    expect(price.textContent).not.toMatch(/DT/);
  });

  it('renders a TND formatter output with exactly one DT marker', () => {
    currencyState.format = () => '9,990 DT';
    render(<SheetHarness product={product} onClose={() => {}} />);
    const price = screen.getByText('9,990 DT');
    expect(price.textContent).toBe('9,990 DT');
    expect((price.textContent ?? '').match(/DT/g)).toHaveLength(1);
  });
});

describe('ProductDetailDrawer — Strategy A accent repoint (Task 18)', () => {
  it('contains no bg-accent/text-accent/border-accent Tailwind classes', () => {
    const sourcePath = join(process.cwd(), 'src/components/pos/ProductDetailDrawer.tsx');
    const source = readFileSync(sourcePath, 'utf-8');
    expect(source).not.toMatch(/\b(?:bg|text|border)-accent(?:-\w+)?\b/);
  });
});

// Cart-always-foreground v1 (spec §2.1) — the sheet gains a 'pane' variant that
// fills the product pane fluidly with region (not dialog) semantics. The
// overlay variant stays the default and byte-identical during the transition.
describe('ProductDetailSheet — pane variant (cart-always-foreground v1)', () => {
  function renderPane() {
    return render(
      <ProductDetailSheet
        variant="pane"
        product={product}
        onClose={() => {}}
        activeTab="details"
        onTabChange={() => {}}
      />,
    );
  }

  it('renders as a non-modal region labelled by the product name', () => {
    renderPane();
    const pane = screen.getByTestId('product-detail-modal');
    expect(pane).toHaveAttribute('role', 'region');
    expect(pane).toHaveAttribute('aria-label', 'Widget');
    expect(pane).not.toHaveAttribute('aria-modal');
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('fills its host fluidly with a min-w floor and no overlay geometry/animation', () => {
    renderPane();
    const pane = screen.getByTestId('product-detail-modal');
    expect(pane).toHaveClass('h-full');
    expect(pane).toHaveClass('w-full');
    expect(pane).toHaveClass('min-w-[680px]');
    expect(pane.className).not.toContain('w-[1080px]');
    expect(pane.className).not.toContain('h-[680px]');
    expect(pane.className).not.toContain('max-w-[96vw]');
    expect(pane.className).not.toContain('max-h-[92vh]');
    expect(pane.className).not.toContain('ez-sheet-rise');
    expect(pane.className).not.toContain('fixed');
  });

  it('keeps the overlay variant as the default (dialog semantics + fixed geometry)', () => {
    render(
      <ProductDetailSheet
        product={product}
        onClose={() => {}}
        activeTab="details"
        onTabChange={() => {}}
      />,
    );
    const sheet = screen.getByTestId('product-detail-modal');
    expect(sheet).toHaveAttribute('role', 'dialog');
    expect(sheet).toHaveAttribute('aria-modal', 'true');
    expect(sheet).toHaveClass('w-[1080px]');
    expect(sheet).toHaveClass('ez-sheet-rise');
  });
});
