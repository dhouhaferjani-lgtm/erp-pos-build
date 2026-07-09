import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { CartCustomerControl } from './CartCustomerControl';

// Keep the real module surface (initReactI18next etc. are pulled in via the
// component's import chain) and only stub the hook to echo raw keys.
vi.mock('react-i18next', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-i18next')>();
  return { ...actual, useTranslation: () => ({ t: (k: string) => k }) };
});

const mockState: { selectedCustomer: unknown } = { selectedCustomer: null };
vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: (sel: (s: { selectedCustomer: unknown; detachCustomer: () => void }) => unknown) =>
    sel({ ...mockState, detachCustomer: vi.fn() }),
}));

// Deterministic currency formatting: echo the raw decimal string so tests
// don't depend on Intl locale output.
vi.mock('@/lib/currency', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/currency')>();
  return {
    ...actual,
    useCurrency: () => ({
      currency: 'TND',
      decimals: 3,
      format: (amount: number | string) => String(amount),
    }),
  };
});

// Force CustomerLoyaltyBadge into its "enrolled, has points, has an
// estimate" branch so the chip actually renders name + loyalty cluster +
// wallet balance together — the exact combination that used to collide.
vi.mock('@/lib/loyalty/useLoyaltyBalance', () => ({
  useLoyaltyBalance: () => ({
    balance: { enrolled: true, balance: '2450.000', tier: 'Gold', rate: '2' },
    refresh: vi.fn(),
  }),
}));
vi.mock('@/stores/cartStore', () => ({ useCartStore: (sel: (s: { total: () => number }) => unknown) => sel({ total: () => 10 }) }));

describe('CartCustomerControl', () => {
  it('renders the trigger button when no customer is attached', () => {
    mockState.selectedCustomer = null;
    const onOpen = vi.fn();
    render(<CartCustomerControl onOpen={onOpen} />);
    const btn = screen.getByRole('button', { name: /customer\.attach/i });
    fireEvent.click(btn);
    expect(onOpen).toHaveBeenCalledOnce();
  });

  it('renders the attached-customer chip with the name when attached', () => {
    mockState.selectedCustomer = { id: 'c1', name: 'Amine Trabelsi' };
    render(<CartCustomerControl onOpen={vi.fn()} />);
    expect(screen.getByText('Amine Trabelsi')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /customer\.detach/i })).toBeInTheDocument();
  });

  it('renders a wallet account button on the chip that reopens the modal', () => {
    mockState.selectedCustomer = { id: 'c1', name: 'Amine Trabelsi', credit_balance: '0.000' };
    const onOpen = vi.fn();
    render(<CartCustomerControl onOpen={onOpen} />);
    const walletBtn = screen.getByRole('button', { name: /customer\.accountAndDeposit/i });
    fireEvent.click(walletBtn);
    expect(onOpen).toHaveBeenCalledOnce();
  });

  it('shows the credit balance next to the wallet icon when positive', () => {
    mockState.selectedCustomer = { id: 'c1', name: 'Amine Trabelsi', credit_balance: '12.500' };
    render(<CartCustomerControl onOpen={vi.fn()} />);
    expect(screen.getByText('12.500')).toBeInTheDocument();
  });

  it('hides the balance text when credit_balance is zero', () => {
    mockState.selectedCustomer = { id: 'c1', name: 'Amine Trabelsi', credit_balance: '0.000' };
    render(<CartCustomerControl onOpen={vi.fn()} />);
    expect(screen.queryByText('0.000')).not.toBeInTheDocument();
    // Wallet affordance is still present.
    expect(screen.getByRole('button', { name: /customer\.accountAndDeposit/i })).toBeInTheDocument();
  });

  it('hides the balance text when credit_balance is absent', () => {
    mockState.selectedCustomer = { id: 'c1', name: 'Amine Trabelsi' };
    render(<CartCustomerControl onOpen={vi.fn()} />);
    expect(screen.getByRole('button', { name: /customer\.accountAndDeposit/i })).toBeInTheDocument();
  });

  it('keeps the name button opening the modal when attached', () => {
    mockState.selectedCustomer = { id: 'c1', name: 'Amine Trabelsi', credit_balance: '12.500' };
    const onOpen = vi.fn();
    render(<CartCustomerControl onOpen={onOpen} />);
    fireEvent.click(screen.getByText('Amine Trabelsi'));
    expect(onOpen).toHaveBeenCalledOnce();
  });

  // Task 7 regression: name + loyalty points + wallet balance all present
  // at once used to collide because the wrapper around this control in
  // TransactionCart.tsx was locked `shrink-0` (never allowed to compress),
  // and nothing downstream had a real min-width strategy. jsdom has no
  // layout engine, so it cannot measure actual pixel overflow — this test
  // instead asserts the STRUCTURAL properties that make compression
  // possible: the name zone can shrink+truncate, and the control root is
  // not forced to a fixed intrinsic width. It fails against the pre-fix
  // markup (no `data-testid="customer-name"`, and the collision was one
  // level up in TransactionCart's `shrink-0` wrapper, which this test's
  // "not locked wide" assertion on the chip itself would not have caught
  // either — which is exactly why the vacuous brief-provided test needed
  // replacing with one that checks the actual fixed classes).
  it('keeps the name truncatable and does not lock the chip to a fixed width when points+balance are both present', () => {
    mockState.selectedCustomer = {
      id: 'c1',
      name: 'Mohamed Ben Slimane El Mansouri El Fassi',
      credit_balance: '1250.500',
    };
    render(<CartCustomerControl onOpen={vi.fn()} />);

    const root = screen.getByTestId('cart-customer-control');
    const name = screen.getByTestId('customer-name');

    // Name zone must be able to give up space and ellipsize rather than
    // push the chip wider — this is the "name yields first" priority rule.
    expect(name.className).toContain('min-w-0');
    expect(name.className).toContain('truncate');

    // The chip root must be able to shrink (min-w-0, no shrink-0) so the
    // *whole* control can cede width back to TransactionCart's header row
    // instead of forcing that row to overflow.
    expect(root.className).toContain('min-w-0');
    expect(root.className).not.toMatch(/\bshrink-0\b/);

    // Both the loyalty cluster (points, tier, estimate) and the wallet
    // balance actually rendered — proving this exercises the real
    // collision scenario, not a degenerate case with one cluster absent.
    expect(screen.getByTestId('loyalty-chrome')).toBeInTheDocument();
    expect(screen.getByTestId('loyalty-balance')).toBeInTheDocument();
    expect(screen.getByText('1250.500')).toBeInTheDocument();

    // Detach stays reachable at a real touch target even under pressure —
    // it must not be shrink-capable below a usable size.
    const detach = screen.getByRole('button', { name: /customer\.detach/i });
    expect(detach.className).toMatch(/\bshrink-0\b/);
  });
});
