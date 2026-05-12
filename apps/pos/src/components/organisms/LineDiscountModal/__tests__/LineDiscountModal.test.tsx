import { useState } from 'react';
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
    terminalMaxDiscountPercent: 100,
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

describe('LineDiscountModal — focus management (PR #97 follow-up)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders with role="dialog", aria-modal, and aria-labelledby pointing at the title', () => {
    renderModal();
    const dialog = screen.getByTestId('line-discount-modal-dialog');
    expect(dialog.getAttribute('role')).toBe('dialog');
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(dialog.getAttribute('aria-labelledby')).toBe('line-discount-modal-title');
    expect(document.getElementById('line-discount-modal-title')).not.toBeNull();
  });

  it('restores focus to the opener when the modal closes', () => {
    function Harness() {
      const [isOpen, setIsOpen] = useState(false);
      return (
        <>
          <button data-testid="opener" onClick={() => setIsOpen(true)}>
            open
          </button>
          <LineDiscountModal
            isOpen={isOpen}
            onClose={() => setIsOpen(false)}
            onApply={vi.fn()}
            itemName="Espresso"
            canDiscount
            maxDiscountPercent={100}
            terminalMaxDiscountPercent={100}
          />
        </>
      );
    }

    render(<Harness />);
    const opener = screen.getByTestId('opener');
    opener.focus();
    expect(document.activeElement).toBe(opener);

    fireEvent.click(opener);
    const dialog = screen.getByTestId('line-discount-modal-dialog');
    expect(dialog).toBeInTheDocument();
    expect(document.activeElement).not.toBe(opener);
    expect(dialog.contains(document.activeElement)).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'discount.cancel' }));

    expect(screen.queryByTestId('line-discount-modal-dialog')).not.toBeInTheDocument();
    expect(document.activeElement).toBe(opener);
  });
});
