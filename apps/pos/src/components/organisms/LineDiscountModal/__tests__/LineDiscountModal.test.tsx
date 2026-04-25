import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { LineDiscountModal } from '../LineDiscountModal';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}));

function renderModal(overrides: Partial<Parameters<typeof LineDiscountModal>[0]> = {}) {
  const defaults = {
    isOpen: true,
    onClose: vi.fn(),
    onApply: vi.fn(),
    itemName: 'Espresso',
    canDiscount: true,
    maxDiscountPercent: 100,
  };
  return render(<LineDiscountModal {...defaults} {...overrides} />);
}

describe('LineDiscountModal', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders nothing when closed', () => {
    renderModal({ isOpen: false });
    expect(screen.queryByText('discount.percentage')).toBeNull();
    expect(screen.queryByText('cart.applyDiscount')).toBeNull();
  });

  it('renders item name and apply button when open', () => {
    renderModal();
    expect(screen.getByText('Espresso')).toBeInTheDocument();
    expect(screen.getByText('cart.applyDiscount')).toBeInTheDocument();
  });

  it('renders as full-screen view, not inside a constrained modal overlay', () => {
    const { container } = renderModal();
    // A constrained Modal renders a max-w-4xl content box; a full-screen view must not
    expect(container.innerHTML).not.toContain('max-w-4xl');
    // A full-screen view has no dark backdrop scrim
    expect(container.innerHTML).not.toContain('bg-black/50');
  });

  it('apply button is disabled when no value has been entered', () => {
    renderModal();
    expect(screen.getByText('cart.applyDiscount').closest('button')).toBeDisabled();
  });

  it('calls onApply with correct payload on valid apply', () => {
    const onApply = vi.fn();
    renderModal({ onApply });
    fireEvent.click(screen.getByText('1'));
    fireEvent.click(screen.getByText('5'));
    fireEvent.click(screen.getByText('cart.applyDiscount'));
    expect(onApply).toHaveBeenCalledWith({ type: 'percentage', value: '15', reason: '' });
  });
});
