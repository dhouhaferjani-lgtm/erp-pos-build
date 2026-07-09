import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import i18n from '@/test/i18n';
import { ProductCard, type ProductCardProps } from '../ProductCard';
import { tokens } from '@/lib/designTokens';
import { makeProduct } from '@/test/helpers';
import type { POSProduct } from '@/types/product';

// Task 16 — optional-field toggle. Mutable so individual tests can flip
// `showSkinTypeOnTiles` without re-mocking the module.
const settingsStoreMock = vi.hoisted(() => ({
  state: { showSkinTypeOnTiles: false },
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: <T,>(selector: (s: typeof settingsStoreMock.state) => T): T =>
    selector(settingsStoreMock.state),
}));

vi.mock('react-i18next', async (importActual) => {
  const actual = await importActual<typeof import('react-i18next')>();
  return {
    ...actual,
    useTranslation: () => ({
      t: (key: string, opts?: Record<string, unknown>) => {
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
    format: (amount: number | string) => {
      const num = typeof amount === 'string' ? parseFloat(amount) : amount;
      return `${num.toFixed(2)} EUR`;
    },
  }),
}));

vi.mock('@/lib/images/useProductImage', () => ({
  useProductImage: () => null,
}));

function renderCard(overrides: Partial<ProductCardProps> = {}) {
  const defaults: ProductCardProps = {
    product: makeProduct({ id: 'p1', name: 'Test Widget', sale_price: '9.99', stock_quantity: 20 }),
    onAddToCart: vi.fn(),
  };
  return render(<ProductCard {...defaults} {...overrides} />);
}

describe('ProductCard', () => {
  it('renders product name', () => {
    renderCard();
    expect(screen.getByText('Test Widget')).toBeInTheDocument();
  });

  it('renders product price', () => {
    renderCard();
    expect(screen.getByText('9.99 EUR')).toBeInTheDocument();
  });

  it('renders in visual mode', () => {
    renderCard({ displayMode: 'visual' });
    expect(screen.getByText('Test Widget')).toBeInTheDocument();
    expect(screen.getByText('9.99 EUR')).toBeInTheDocument();
  });

  it('calls onAddToCart when clicked', () => {
    const onAddToCart = vi.fn();
    const product = makeProduct({ id: 'p1', name: 'Clickable', sale_price: '5.00', stock_quantity: 10 });
    renderCard({ product, onAddToCart });
    fireEvent.click(screen.getByRole('button'));
    expect(onAddToCart).toHaveBeenCalledWith(product);
  });

  it('does not call onAddToCart when out of stock', () => {
    const onAddToCart = vi.fn();
    renderCard({
      product: makeProduct({ stock_quantity: 0 }),
      onAddToCart,
    });
    const btn = screen.getByRole('button');
    // The outer card is now a div[role="button"]; native `disabled` does not apply.
    // Accessibility state is communicated via aria-disabled instead.
    expect(btn).toHaveAttribute('aria-disabled', 'true');
    fireEvent.click(btn);
    expect(onAddToCart).not.toHaveBeenCalled();
  });

  it('renders consistently on rerender', () => {
    const product = makeProduct({ id: 'p2', name: 'Stable Product', sale_price: '15.00', stock_quantity: 5 });
    const onAddToCart = vi.fn();
    const { rerender } = render(<ProductCard product={product} onAddToCart={onAddToCart} />);
    rerender(<ProductCard product={product} onAddToCart={onAddToCart} />);
    expect(screen.getByText('Stable Product')).toBeInTheDocument();
  });

  it('shows in-cart state as border + count chip ONLY — no bg tint, no top accent bar (calm selection)', () => {
    renderCard({ isInCart: true });
    const btn = screen.getByRole('button');
    // The blue border is THE selection signal (Strategy A).
    expect(btn.className).toContain('border-action');
    expect(btn.className).not.toContain('border-l-4');
    // The background tint and the 3px top accent bar are REMOVED — selection
    // must not stack three signals.
    expect(btn.className).not.toContain('bg-action-subtle');
    const spans = Array.from(btn.querySelectorAll('span'));
    expect(spans.some((el) => el.className.includes('h-[3px]'))).toBe(false);
    // The count chip stays — it carries information, not decoration.
    expect(screen.getByTestId('in-cart-badge')).toBeInTheDocument();
  });

  it('in-cart chip shows the cart QUANTITY when provided', () => {
    renderCard({ isInCart: true, cartQuantity: 3 });
    expect(screen.getByTestId('in-cart-badge')).toHaveTextContent('3');
  });

  it('visual-mode in-cart chip is anchored inside the image tile, z-raised above the image', () => {
    const { container } = renderCard({ isInCart: true, cartQuantity: 2, displayMode: 'visual' });
    const tile = container.querySelector('[data-testid="product-visual-tile"]');
    const badge = screen.getByTestId('in-cart-badge');
    expect(tile).toContainElement(badge);
    // Explicit stacking so the chip can never peek out from behind the image.
    expect(badge.className).toContain('z-');
    expect(badge).toHaveTextContent('2');
  });

  it('price is ink, never accent (Task 11)', () => {
    renderCard();
    const price = screen.getByTestId('price-row');
    expect(price.className).toContain('text-ink');
    expect(price.className).not.toContain('accent');
  });

  describe('showSkinTypeOnTiles (Task 16 — optional-field toggle, default off)', () => {
    it('does not render skin-type dots when the flag is off, even with data', () => {
      settingsStoreMock.state.showSkinTypeOnTiles = false;
      renderCard({
        displayMode: 'visual',
        product: makeProduct({
          parapharmacy_metadata: {
            suitable_skin_types: ['oily', 'dry'],
            equivalent_product_ids: [],
            complement_product_ids: [],
            routine_refs: [],
          },
        }),
      });

      expect(screen.queryByTestId('skin-type-dots')).not.toBeInTheDocument();
    });

    it('does not render skin-type dots when the flag is on but suitable_skin_types is empty/absent', () => {
      settingsStoreMock.state.showSkinTypeOnTiles = true;
      renderCard({ displayMode: 'visual', product: makeProduct({}) });

      expect(screen.queryByTestId('skin-type-dots')).not.toBeInTheDocument();
    });

    it('renders one dot per skin type when the flag is on and data is present', () => {
      settingsStoreMock.state.showSkinTypeOnTiles = true;
      renderCard({
        displayMode: 'visual',
        product: makeProduct({
          parapharmacy_metadata: {
            suitable_skin_types: ['oily', 'dry'],
            equivalent_product_ids: [],
            complement_product_ids: [],
            routine_refs: [],
          },
        }),
      });

      const container = screen.getByTestId('skin-type-dots');
      expect(container.querySelectorAll('[data-testid="skin-type-dot"]')).toHaveLength(2);
    });

    it('does not render skin-type dots in grid (compact) mode even with data + flag on', () => {
      settingsStoreMock.state.showSkinTypeOnTiles = true;
      renderCard({
        displayMode: 'grid',
        product: makeProduct({
          parapharmacy_metadata: {
            suitable_skin_types: ['oily'],
            equivalent_product_ids: [],
            complement_product_ids: [],
            routine_refs: [],
          },
        }),
      });

      expect(screen.queryByTestId('skin-type-dots')).not.toBeInTheDocument();
    });
  });
});

const longNameProduct: POSProduct = {
  id: 'p1',
  name: 'Aquarium Water Conditioner — Tropical Edition 250ml',
  sku: 'AWC-250',
  sale_price: '32.000',
  stock_quantity: 5,
  barcode: null,
};

describe('ProductCard — Task 10 unified stock badge', () => {
  it('shows the NUMBER for low stock (legacy stock_quantity path), not the wordless label', () => {
    renderCard({
      product: makeProduct({ id: 'p-low', name: 'Low Item', sale_price: '4.00', stock_quantity: 3 }),
    });

    // Mocked t() appends `:count` when opts.count is passed — the low branch
    // must now route through products.stock (numbered), not products.lowStock.
    expect(screen.getByText('products.stock:3')).toBeInTheDocument();
    expect(screen.queryByText('products.lowStock')).not.toBeInTheDocument();
    const badge = screen.getByTestId('stock-row');
    expect(badge).toHaveAttribute('data-status', 'low');
  });
});

describe('ProductCard layout regressions', () => {
  it('renders the full product name in a `title` attribute for hover tooltip (grid mode)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const heading = screen.getByRole('heading', { level: 3 });
    expect(heading.getAttribute('title')).toBe(longNameProduct.name);
  });

  it('renders the full product name in a `title` attribute for hover tooltip (visual mode)', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="visual" />
      </I18nextProvider>,
    );
    const heading = screen.getByRole('heading', { level: 3 });
    expect(heading.getAttribute('title')).toBe(longNameProduct.name);
  });

  it('marks the price row with shrink-0 so it cannot be squeezed by a long name (grid)', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const priceRow = container.querySelector('[data-testid="price-row"]');
    expect(priceRow).not.toBeNull();
    expect(priceRow?.className).toMatch(/\bshrink-0\b/);
  });

  it('marks the stock row with shrink-0 so it stays anchored at the bottom (grid)', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="grid" />
      </I18nextProvider>,
    );
    const stockRow = container.querySelector('[data-testid="stock-row"]');
    expect(stockRow).not.toBeNull();
    expect(stockRow?.className).toMatch(/\bshrink-0\b/);
  });

  it('stacks visual-mode price and stock so narrow Caisse columns do not clip the badge', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="visual" />
      </I18nextProvider>,
    );
    const priceStockBlock = container.querySelector('[data-testid="price-stock-block"]');
    expect(priceStockBlock).not.toBeNull();
    expect(priceStockBlock?.className).toMatch(/\bflex-col\b/);
  });

  it('vitrine eye: ≥48px hit target anchored inside a shorter (72px) image tile, with a smaller ghost glyph', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard
          product={longNameProduct}
          onAddToCart={vi.fn()}
          onViewDetails={vi.fn()}
          displayMode="visual"
        />
      </I18nextProvider>,
    );

    const eye = screen.getByTestId('view-details-button');
    const tile = container.querySelector('[data-testid="product-visual-tile"]');
    expect(tile).not.toBeNull();
    expect(tile?.className).toContain('w-full');
    // (b) placeholder vertical dominance reduced: 88px → 72px.
    expect(tile?.className).toContain('h-[72px]');
    expect(tile?.className).not.toContain('h-[88px]');
    expect(tile).toContainElement(eye);
    // (c) touch-first: the HIT target stays ≥48px (h-12/w-12) and always
    // visible; only the visible glyph chip inside it got quieter/smaller.
    expect(eye.className).toContain('h-12');
    expect(eye.className).toContain('w-12');
    const glyph = eye.querySelector('[data-testid="view-details-glyph"]');
    expect(glyph).not.toBeNull();
    // Ghost surface: semi-transparent token surface, no border/shadow chrome.
    expect(glyph?.className).toContain('bg-surface-raised/');
    expect(glyph?.className).not.toContain('border');
    expect(glyph?.className).not.toContain('shadow');
  });

  it('left-aligns the visual card text block (scanning axis) — no centered wall', () => {
    render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={vi.fn()} displayMode="visual" />
      </I18nextProvider>,
    );
    const card = screen.getByRole('button');
    expect(card.className).not.toContain('text-center');
    expect(card.className).not.toContain('items-center');
    expect(card.className).toContain('items-start');
  });

  it('uses the mock visual-card typography scale', () => {
    const product = makeProduct({
      id: 'p-visual-scale',
      name: 'Effaclar Gel Moussant 200ml',
      brand_name: 'La Roche-Posay',
      sale_price: '38.500',
      stock_quantity: 18,
    });
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={product} onAddToCart={vi.fn()} displayMode="visual" />
      </I18nextProvider>,
    );

    const brand = screen.getByText('La Roche-Posay');
    const name = screen.getByRole('heading', { level: 3 });
    const price = container.querySelector('[data-testid="price-row"]');
    const stock = container.querySelector('[data-testid="stock-row"]');

    // Task 11 — brand label bumped from 10px to 11px for legibility (contrast fix).
    expect(brand.className).toContain('text-[11px]');
    expect(brand.className).toContain('leading-[1.2]');
    expect(name.className).toContain('text-[13.5px]');
    expect(name.className).toContain('leading-[1.3]');
    expect(price?.className).toContain('text-[15px]');
    expect(price?.className).toContain('font-semibold');
    expect(stock?.className).toContain('text-[10.5px]');
    expect(stock?.className).toContain('px-[7px]');
    expect(stock?.className).toContain('py-[3px]');
  });

  it('positions the compact-mode eye in the header actions, not the price footer', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard
          product={longNameProduct}
          onAddToCart={vi.fn()}
          onViewDetails={vi.fn()}
          displayMode="grid"
        />
      </I18nextProvider>,
    );

    const eye = screen.getByTestId('view-details-button');
    const headerActions = container.querySelector('[data-testid="compact-card-header-actions"]');
    const priceStockBlock = container.querySelector('[data-testid="price-stock-block"]');

    expect(headerActions).not.toBeNull();
    expect(headerActions).toContainElement(eye);
    expect(priceStockBlock).not.toContainElement(eye);
    expect(eye.className).toContain('h-7');
    expect(eye.className).toContain('w-7');
  });

  it('keeps the compact-mode in-cart badge in the header actions so it cannot overlap the name', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard
          product={longNameProduct}
          onAddToCart={vi.fn()}
          onViewDetails={vi.fn()}
          isInCart
          displayMode="grid"
        />
      </I18nextProvider>,
    );

    const badge = screen.getByTestId('in-cart-badge');
    const headerActions = container.querySelector('[data-testid="compact-card-header-actions"]');

    expect(headerActions).not.toBeNull();
    expect(headerActions).toContainElement(badge);
    expect(badge.className).not.toContain('absolute');
  });

  it('uses the mock compact-card typography scale', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard
          product={{ ...longNameProduct, brand_name: 'Avène' }}
          onAddToCart={vi.fn()}
          displayMode="grid"
        />
      </I18nextProvider>,
    );

    const brand = screen.getByText('Avène');
    const name = screen.getByRole('heading', { level: 3 });
    const price = container.querySelector('[data-testid="price-row"]');
    const stock = container.querySelector('[data-testid="stock-row"]');

    // Task 11 — brand label bumped from 10px to 11px for legibility (contrast fix).
    expect(brand.className).toContain('text-[11px]');
    expect(name.className).toContain('text-[13.5px]');
    expect(name.className).toContain('leading-[1.3]');
    expect(price?.className).toContain('text-[15px]');
    expect(price?.className).toContain('font-semibold');
    expect(stock?.className).toContain('text-[10.5px]');
    expect(stock?.className).toContain('px-[7px]');
    expect(stock?.className).toContain('py-[3px]');
  });

  it('renders the outer card as role="button" rather than a <button> so the inner customize button is not nested', () => {
    const productWithModifiers: POSProduct = {
      ...longNameProduct,
      modifier_groups: [{ id: 'g1', name: 'Size', is_required: true, modifiers: [] }] as never,
    };
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard
          product={productWithModifiers}
          onAddToCart={vi.fn()}
          onCustomize={vi.fn()}
          displayMode="grid"
        />
      </I18nextProvider>,
    );
    const cardRoot = container.firstElementChild!;
    expect(cardRoot.tagName).not.toBe('BUTTON');
    expect(cardRoot.getAttribute('role')).toBe('button');
    expect(cardRoot.getAttribute('tabindex')).toBe('0');
    expect(container.querySelector('button button')).toBeNull();
    const customize = container.querySelector('[data-testid="customize-button"]');
    expect(customize?.tagName).toBe('BUTTON');
  });

  it('fires onAddToCart when the outer card is activated via Enter or Space', () => {
    const onAdd = vi.fn();
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <ProductCard product={longNameProduct} onAddToCart={onAdd} displayMode="grid" />
      </I18nextProvider>,
    );
    const card = container.firstElementChild as HTMLElement;
    card.focus();
    card.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(onAdd).toHaveBeenCalledTimes(1);
    card.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
    expect(onAdd).toHaveBeenCalledTimes(2);
  });

  it('renders an eye button when onViewDetails is provided and opens details on click without adding to cart', () => {
    const onViewDetails = vi.fn();
    const onAddToCart = vi.fn();
    const product = makeProduct({ id: 'p1', name: 'Widget', sale_price: '5.00', stock_quantity: 10 });
    renderCard({ product, onAddToCart, onViewDetails });
    fireEvent.click(screen.getByTestId('view-details-button'));
    expect(onViewDetails).toHaveBeenCalledWith(product);
    expect(onAddToCart).not.toHaveBeenCalled();
  });

  it('keyboard-activating the eye button does NOT add to cart (H1)', () => {
    const onViewDetails = vi.fn();
    const onAddToCart = vi.fn();
    const product = makeProduct({ id: 'p1', name: 'Widget', sale_price: '5.00', stock_quantity: 10 });
    renderCard({ product, onAddToCart, onViewDetails });
    const eye = screen.getByTestId('view-details-button');
    fireEvent.keyDown(eye, { key: 'Enter' });
    expect(onAddToCart).not.toHaveBeenCalled();
  });
});

// ── Task 24 restyle assertions ────────────────────────────────────────────────
describe('ProductCard — Task 24 restyle', () => {
  it('(a) preserved data-testids all render on a standard in-stock product', () => {
    const product = makeProduct({ id: 'p1', name: 'Widget', sale_price: '5.00', stock_quantity: 10 });
    const { container } = renderCard({ product, onViewDetails: vi.fn() });

    // price-row and stock-row always present for a tracked product
    expect(container.querySelector('[data-testid="price-row"]')).toBeInTheDocument();
    expect(container.querySelector('[data-testid="stock-row"]')).toBeInTheDocument();
    // incoming-badge absent when no incoming stock (undefined locationStock = legacy path)
    expect(container.querySelector('[data-testid="incoming-badge"]')).not.toBeInTheDocument();
    // view-details-button present when handler provided
    expect(container.querySelector('[data-testid="view-details-button"]')).toBeInTheDocument();
    // in-cart-badge absent when not in cart
    expect(container.querySelector('[data-testid="in-cart-badge"]')).not.toBeInTheDocument();
  });

  it('(b) brand_name renders in caps above the name only when present', () => {
    const withBrand = makeProduct({ name: 'Crème Hydratante', brand_name: 'Avène' });
    const withoutBrand = makeProduct({ name: 'Crème Hydratante', brand_name: undefined });

    const { container: withBrandContainer } = render(
      <ProductCard product={withBrand} onAddToCart={vi.fn()} />,
    );
    const { container: withoutBrandContainer } = render(
      <ProductCard product={withoutBrand} onAddToCart={vi.fn()} />,
    );

    // Brand renders in an element with the uppercase utility class when present
    const brandEl = withBrandContainer.querySelector('.uppercase');
    expect(brandEl).toBeInTheDocument();
    expect(brandEl?.textContent).toBe('Avène');

    // Brand row is absent when brand_name is not provided
    expect(withoutBrandContainer.querySelector('.uppercase')).not.toBeInTheDocument();
  });

  it('(c) in-cart treatment is calm: action border ONLY, action-family count chip, no bar/tint (selection is blue, never accent/green)', () => {
    renderCard({ isInCart: true, cartQuantity: 2 });
    const btn = screen.getByRole('button');

    // Action border — the single selection signal; no accent anywhere.
    expect(btn.className).toContain('border-action');
    expect(btn.className).not.toContain('bg-action-subtle');
    expect(btn.className).not.toContain('accent');

    // No 3px top bar remains.
    const bars = Array.from(btn.querySelectorAll('span[aria-hidden]'));
    expect(bars.find((el) => el.className.includes('h-[3px]'))).toBeUndefined();

    // Count chip: action (blue) family, carries the quantity, never accent.
    const badge = screen.getByTestId('in-cart-badge');
    expect(badge).toBeInTheDocument();
    expect(badge.className).toContain('bg-action');
    expect(badge.className).not.toContain('accent');
    expect(badge).toHaveTextContent('2');
  });
});

// ── Unified product-name typography (owner polish 2026-07-09, sub-task d) ────
describe('ProductCard — unified product-name recipe', () => {
  it('renders the name with the shared tokens.productName recipe (2-line clamp on cards)', () => {
    renderCard({ displayMode: 'visual' });
    const name = screen.getByRole('heading', { level: 3 });
    expect(name.className).toContain(tokens.productName.base);
    expect(name.className).toContain('line-clamp-2');
    expect(name.className).toContain('text-ink');
  });

  it('compact (grid) card uses the same shared recipe', () => {
    renderCard({ displayMode: 'grid' });
    const name = screen.getByRole('heading', { level: 3 });
    expect(name.className).toContain(tokens.productName.base);
    expect(name.className).toContain('line-clamp-2');
  });
});
