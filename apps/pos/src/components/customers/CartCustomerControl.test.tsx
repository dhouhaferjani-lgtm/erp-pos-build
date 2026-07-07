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
});
