import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DiscountModal } from '../DiscountModal';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}));

function renderModal(overrides: Partial<Parameters<typeof DiscountModal>[0]> = {}) {
  const defaults = {
    isOpen: true,
    onClose: vi.fn(),
    onApplyTransactionDiscount: vi.fn(),
    canDiscount: true,
    maxDiscountPercent: 100,
    requiresReason: false,
  };
  return render(<DiscountModal {...defaults} {...overrides} />);
}

describe('DiscountModal', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders nothing when closed', () => {
    renderModal({ isOpen: false });
    expect(screen.queryByText('discount.percentage')).toBeNull();
    expect(screen.queryByText('discount.apply')).toBeNull();
  });

  it('renders discount type toggles and apply button when open', () => {
    renderModal();
    expect(screen.getByText('discount.percentage')).toBeInTheDocument();
    expect(screen.getByText('discount.fixed')).toBeInTheDocument();
    expect(screen.getByText('discount.apply')).toBeInTheDocument();
  });

  it('renders as full-screen view, not inside a constrained modal overlay', () => {
    const { container } = renderModal();
    // A constrained Modal renders a max-w-4xl content box; a full-screen view must not
    expect(container.innerHTML).not.toContain('max-w-4xl');
    // A full-screen view has no dark backdrop scrim
    expect(container.innerHTML).not.toContain('bg-black/50');
  });

  it('calls onClose when the cancel button in the fullscreen header is clicked', () => {
    const onClose = vi.fn();
    renderModal({ onClose });
    // The fullscreen header has a "Cancel" button (using discount.cancel i18n key)
    const cancelBtn = screen.getByRole('button', { name: 'discount.cancel' });
    fireEvent.click(cancelBtn);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('pressing Escape calls onClose when not in manager-PIN flow', () => {
    const onClose = vi.fn();
    renderModal({ onClose });
    fireEvent.keyDown(window, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('apply button is disabled when no value has been entered', () => {
    renderModal();
    expect(screen.getByText('discount.apply').closest('button')).toBeDisabled();
  });

  it('calls onApplyTransactionDiscount with correct payload on valid apply', () => {
    const onApply = vi.fn();
    renderModal({ onApplyTransactionDiscount: onApply });
    // Press numpad keys to enter value "20"
    fireEvent.click(screen.getByText('2'));
    fireEvent.click(screen.getByText('0'));
    fireEvent.click(screen.getByText('discount.apply'));
    expect(onApply).toHaveBeenCalledWith({ type: 'percentage', value: '20', reason: '' });
  });
});
