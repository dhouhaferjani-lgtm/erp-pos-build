import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TransactionCart, type TransactionCartProps } from '../TransactionCart';
import { makeCartItem } from '@/test/helpers';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`;
      return key;
    },
  }),
}));

vi.mock('@/components/molecules/CartLineItem', () => ({
  CartLineItem: ({ item }: { item: { id: string; product: { name: string } } }) => (
    <div data-testid={`cart-line-${item.id}`}>{item.product.name}</div>
  ),
}));

vi.mock('@/components/organisms/PaymentSummary', () => ({
  PaymentSummary: () => <div data-testid="payment-summary">Payment Summary</div>,
}));

vi.mock('@/components/molecules/QuickActions', () => ({
  QuickActions: () => <div data-testid="quick-actions">Quick Actions</div>,
}));

function renderCart(overrides: Partial<TransactionCartProps> = {}) {
  const defaults: TransactionCartProps = {
    items: [],
    subtotal: 0,
    taxAmount: 0,
    discountAmount: 0,
    total: 0,
    itemCount: 0,
    hasDiscount: false,
    onUpdateQuantity: vi.fn(),
    onRemoveItem: vi.fn(),
    onClearCart: vi.fn(),
    onPayCash: vi.fn(),
  };
  return render(<TransactionCart {...defaults} {...overrides} />);
}

describe('TransactionCart', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders cart title', () => {
    renderCart();
    expect(screen.getByText('cart.title')).toBeInTheDocument();
  });

  it('shows empty state when no items', () => {
    renderCart();
    expect(screen.getByText('cart.empty')).toBeInTheDocument();
    expect(screen.getByText('cart.addProducts')).toBeInTheDocument();
  });

  it('renders cart items when present', () => {
    const items = [
      makeCartItem({ id: 'item-1', product: { id: 'p1', name: 'Widget', sku: 'W-001', price: '10.00' } }),
      makeCartItem({ id: 'item-2', product: { id: 'p2', name: 'Gadget', sku: 'G-001', price: '20.00' } }),
    ];

    renderCart({ items, itemCount: 2 });

    expect(screen.getByTestId('cart-line-item-1')).toBeInTheDocument();
    expect(screen.getByTestId('cart-line-item-2')).toBeInTheDocument();
  });

  it('shows item count badge when items present', () => {
    renderCart({ items: [makeCartItem()], itemCount: 3 });
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('does not show item count badge when empty', () => {
    renderCart({ itemCount: 0 });
    expect(screen.queryByText('0')).not.toBeInTheDocument();
  });

  it('shows clear button only when items present', () => {
    const { rerender } = render(
      <TransactionCart
        items={[]}
        subtotal={0}
        taxAmount={0}
        discountAmount={0}
        total={0}
        itemCount={0}
        hasDiscount={false}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onClearCart={vi.fn()}
        onPayCash={vi.fn()}
      />,
    );
    expect(screen.queryByText('cart.clear')).not.toBeInTheDocument();

    rerender(
      <TransactionCart
        items={[makeCartItem()]}
        subtotal={10}
        taxAmount={0}
        discountAmount={0}
        total={10}
        itemCount={1}
        hasDiscount={false}
        onUpdateQuantity={vi.fn()}
        onRemoveItem={vi.fn()}
        onClearCart={vi.fn()}
        onPayCash={vi.fn()}
      />,
    );
    expect(screen.getByText('cart.clear')).toBeInTheDocument();
  });

  it('calls onClearCart when clear button clicked', () => {
    const onClearCart = vi.fn();
    renderCart({ items: [makeCartItem()], itemCount: 1, onClearCart });

    fireEvent.click(screen.getByText('cart.clear'));

    expect(onClearCart).toHaveBeenCalledOnce();
  });

  it('shows payment summary when items present', () => {
    renderCart({ items: [makeCartItem()], itemCount: 1 });
    expect(screen.getByTestId('payment-summary')).toBeInTheDocument();
  });

  it('hides payment summary when cart is empty', () => {
    renderCart();
    expect(screen.queryByTestId('payment-summary')).not.toBeInTheDocument();
  });
});

// ── Task 52: Refund / Exchange two-section layout ──────────────────────────

vi.mock('@/lib/currency', () => ({
  useCurrency: () => ({
    format: (val: string | number) => `€${String(parseFloat(String(val)).toFixed(2))}`,
  }),
}));

function makeReturnItem(id: string): import('@/types/cart').CartItem {
  return {
    id,
    product: { id: `prod-${id}`, name: 'Widget', sku: 'WGT', price: '10.00' },
    quantity: -1,
    unit_price: '10.00',
    line_total: '-10.00',
    tax_rate: '20',
    tax_amount: '-1.67',
    kind: 'return',
  };
}

describe('TransactionCart — Task 52 refund/exchange sections', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders "Returning" section header when return items are present', () => {
    renderCart({
      items: [makeReturnItem('r1')],
      itemCount: 1,
    });

    expect(screen.getByText('refundFlow.returningSection')).toBeInTheDocument();
  });

  it('does NOT render "Returning" section header when no return items', () => {
    renderCart({
      items: [makeCartItem({ id: 's1' })],
      itemCount: 1,
    });

    expect(screen.queryByText('refundFlow.returningSection')).not.toBeInTheDocument();
  });

  it('renders "Buying new" section header when both return and sale items exist', () => {
    renderCart({
      items: [makeReturnItem('r1'), makeCartItem({ id: 's1', kind: 'sale' })],
      itemCount: 2,
    });

    expect(screen.getByText('refundFlow.buyingNewSection')).toBeInTheDocument();
  });

  it('does NOT render "Buying new" section header in pure-refund mode (no sale items)', () => {
    renderCart({
      items: [makeReturnItem('r1')],
      itemCount: 1,
    });

    expect(screen.queryByText('refundFlow.buyingNewSection')).not.toBeInTheDocument();
  });

  it('return items show − prefix and red styling on the line total', () => {
    renderCart({
      items: [makeReturnItem('r1')],
      itemCount: 1,
      netTotal: -10,
    });

    // The ReturnLineItem renders the product name in red
    const nameEl = screen.getAllByText('Widget')[0]!;
    expect(nameEl.className).toContain('text-red');

    // At least one element with the minus-prefixed total exists
    const allMinusTotals = screen.getAllByText('−€10.00');
    expect(allMinusTotals.length).toBeGreaterThan(0);
  });

  it('shows "Refund X" confirm label when netTotal < 0', () => {
    renderCart({
      items: [makeReturnItem('r1')],
      itemCount: 1,
      netTotal: -10,
    });

    // t('refundFlow.confirm.refund', { amount: '€10.00' }) → key:{"amount":"€10.00"}
    expect(screen.getByText(/refundFlow.confirm.refund/)).toBeInTheDocument();
  });

  it('shows "Charge X" confirm label when netTotal > 0', () => {
    renderCart({
      items: [makeReturnItem('r1'), makeCartItem({ id: 's1', kind: 'sale' })],
      itemCount: 2,
      netTotal: 5,
    });

    expect(screen.getByText(/refundFlow.confirm.charge/)).toBeInTheDocument();
  });

  it('shows "No payment due" confirm label when netTotal == 0', () => {
    renderCart({
      items: [makeReturnItem('r1'), makeCartItem({ id: 's1', kind: 'sale' })],
      itemCount: 2,
      netTotal: 0,
    });

    expect(screen.getByText('refundFlow.confirm.noPayment')).toBeInTheDocument();
  });

  it('does NOT show net-footer or confirm button when netTotal is undefined', () => {
    renderCart({
      items: [makeCartItem({ id: 's1' })],
      itemCount: 1,
    });

    expect(screen.queryByText(/refundFlow.confirm/)).not.toBeInTheDocument();
    expect(screen.getByTestId('payment-summary')).toBeInTheDocument();
  });
});
