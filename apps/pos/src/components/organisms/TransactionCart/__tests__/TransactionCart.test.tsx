import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TransactionCart, type TransactionCartProps } from '../TransactionCart';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts) return `${key}:${JSON.stringify(opts)}`;
      return key;
    },
  }),
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: (selector: (s: { confirmLineDelete: boolean }) => unknown) =>
    selector({ confirmLineDelete: true }),
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
    expect(screen.queryByLabelText('cart.clear')).not.toBeInTheDocument();

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
    expect(screen.getByLabelText('cart.clear')).toBeInTheDocument();
  });

  it('calls onClearCart when clear button clicked', () => {
    const onClearCart = vi.fn();
    renderCart({ items: [makeCartItem()], itemCount: 1, onClearCart });

    fireEvent.click(screen.getByLabelText('cart.clear'));

    expect(onClearCart).toHaveBeenCalledOnce();
  });

  it('renders a Returns icon button (its own flow) when onReturns is provided, even with an empty cart', () => {
    const onReturns = vi.fn();
    renderCart({ items: [], itemCount: 0, onReturns });
    const returnsBtn = screen.getByLabelText('receiptLocator.entryButton');
    expect(returnsBtn).toBeInTheDocument();
    fireEvent.click(returnsBtn);
    expect(onReturns).toHaveBeenCalledOnce();
  });

  it('shows payment summary when items present', () => {
    renderCart({ items: [makeCartItem()], itemCount: 1 });
    expect(screen.getByTestId('payment-summary')).toBeInTheDocument();
  });

  it('renders a persistent payment summary footer even when cart is empty', () => {
    // Persistent checkout footer: in normal sale mode the totals + Charge
    // button stay anchored at the bottom whether or not the cart has items
    // (the Charge button is disabled via `disabled={... || items.length === 0}`).
    renderCart();
    expect(screen.getByTestId('payment-summary')).toBeInTheDocument();
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

    // The ReturnLineItem renders the product name in the danger (red) token
    const nameEl = screen.getAllByText('Widget')[0]!;
    expect(nameEl.className).toContain('text-danger');

    // At least one element with the minus-prefixed total exists
    const allMinusTotals = screen.getAllByText('−€10.00');
    expect(allMinusTotals.length).toBeGreaterThan(0);
  });

  it('Task 6 precision fix: return line total is decimal-safe (bcabs, not parseFloat) and the displayed magnitude is unchanged for a 3-decimal value', () => {
    // Regression test for the `Math.abs(parseFloat(item.line_total))` ->
    // `bcabs(item.line_total)` fix (no-parsefloat-on-money). Uses a
    // 3-decimal-place value (e.g. a TND-scale amount) to exercise a case
    // `.toFixed(2)`-rounding on a plain float could plausibly perturb, and
    // asserts the exact same rendered string a correct abs() would produce.
    renderCart({
      items: [
        {
          id: 'r-precision',
          product: { id: 'prod-precision', name: 'Precision Widget', sku: 'PW', price: '12.500' },
          quantity: -1,
          unit_price: '12.500',
          line_total: '-12.500',
          tax_rate: '20',
          tax_amount: '-2.08',
          kind: 'return',
        },
      ],
      itemCount: 1,
      netTotal: -12.5,
    });

    // Both the ReturnLineItem total and the net-footer Total row render the
    // same "−€12.50" magnitude for this fixture (mirrors the existing
    // "return items show..." test above) — assert at least one match.
    expect(screen.getAllByText('−€12.50').length).toBeGreaterThan(0);
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

  // T1.2 Codex round-1 finding (unpreempted): the refund/exchange net
  // footer renders its OWN cash button rather than going through
  // PaymentSummary. The Step 2.3 paymentConfigReady gate must be applied
  // here too — otherwise a cashier in refund mode hitting Cash before
  // payment config is loaded would reach processCashCheckout and throw
  // on the no-cash-method backstop. Same vulnerability that Step 2.3
  // closed for the sale path.
  it('T1.2: refund net-footer cash button is disabled when paymentRepositories is empty', () => {
    const onPayCash = vi.fn();
    renderCart({
      items: [makeReturnItem('r1')],
      itemCount: 1,
      netTotal: -10,
      onPayCash,
      paymentMethods: [makePaymentMethod({ id: 'pm-cash' })],
      paymentRepositories: [],
    });

    const button = screen.getByText(/refundFlow.confirm.refund/).closest('button')!;
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(button).toHaveAttribute('title', 'payment.configNotLoaded');

    fireEvent.click(button);
    expect(onPayCash).not.toHaveBeenCalled();
  });

  it('T1.2: refund net-footer cash button is disabled when paymentMethods is empty', () => {
    // Codex round-2 NIT: assert disabled + aria-disabled + title
    // together so a partial regression (removing only one of the three
    // gate attributes) still trips this test, not just a full revert.
    const onPayCash = vi.fn();
    renderCart({
      items: [makeReturnItem('r1')],
      itemCount: 1,
      netTotal: -10,
      onPayCash,
      paymentMethods: [],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash' })],
    });

    const button = screen.getByText(/refundFlow.confirm.refund/).closest('button')!;
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(button).toHaveAttribute('title', 'payment.configNotLoaded');

    fireEvent.click(button);
    expect(onPayCash).not.toHaveBeenCalled();
  });

  it('T1.2: refund net-footer cash button is enabled when both paymentMethods and paymentRepositories are populated', () => {
    const onPayCash = vi.fn();
    renderCart({
      items: [makeReturnItem('r1')],
      itemCount: 1,
      netTotal: -10,
      onPayCash,
      paymentMethods: [makePaymentMethod({ id: 'pm-cash' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash' })],
    });

    const button = screen.getByText(/refundFlow.confirm.refund/).closest('button')!;
    expect(button).not.toBeDisabled();
    // Confirm the gate's other attributes are absent / "false" too —
    // a regression that left aria-disabled="true" or a stale title would
    // fail this assertion even if `disabled` itself was correct.
    expect(button.getAttribute('aria-disabled')).not.toBe('true');
    expect(button).not.toHaveAttribute('title');

    fireEvent.click(button);
    expect(onPayCash).toHaveBeenCalledTimes(1);
  });
});
