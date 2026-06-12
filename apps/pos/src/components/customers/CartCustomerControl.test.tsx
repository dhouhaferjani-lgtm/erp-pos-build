import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { CartCustomerControl } from './CartCustomerControl';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }));

const mockState: { selectedCustomer: unknown } = { selectedCustomer: null };
vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: (sel: (s: { selectedCustomer: unknown; detachCustomer: () => void }) => unknown) =>
    sel({ ...mockState, detachCustomer: vi.fn() }),
}));

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
});
