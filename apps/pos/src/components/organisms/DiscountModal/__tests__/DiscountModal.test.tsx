import { useState } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { DiscountModal } from '../DiscountModal';
import { apiPost } from '@/lib/api';
import { authorPosOverride } from '@/lib/operatorApproval/posOverrideAuthoring';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}));

vi.mock('@/lib/operatorApproval/posOverrideAuthoring', () => ({
  authorPosOverride: vi.fn(),
}));

function renderModal(overrides: Partial<Parameters<typeof DiscountModal>[0]> = {}) {
  const defaults = {
    isOpen: true,
    onClose: vi.fn(),
    onApplyTransactionDiscount: vi.fn(),
    canDiscount: true,
    maxDiscountPercent: 100,
    terminalMaxDiscountPercent: 100,
    requiresReason: false,
    approvalContext: {
      tenantId: 'tenant-1',
      companyId: 'company-1',
      terminalId: 'terminal-1',
      cashierUserId: 'cashier-1',
      businessDate: '2026-05-21',
      isTraining: false,
    },
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

  it('requires fiscal approval evidence before applying a manager-approved discount override', async () => {
    const evidence = {
      approval_id: 'approval-1',
      approval_event_id: 'approval-event-1',
      approval_scope: 'discount_limit_override',
      override_event_id: 'override-event-1',
      policy_version: 'pos-discount-policy-v1',
      supervisor_user_id: 'manager-1',
      target_reference_id: 'target-1',
    } as const;
    vi.mocked(apiPost).mockResolvedValue({
      id: 'manager-1',
      name: 'Manager',
      roles: ['manager'],
      can_discount: true,
      max_discount_percent: 50,
    });
    vi.mocked(authorPosOverride).mockResolvedValue(evidence);
    const onApply = vi.fn();
    renderModal({
      onApplyTransactionDiscount: onApply,
      maxDiscountPercent: 10,
      terminalMaxDiscountPercent: 50,
    });

    fireEvent.click(screen.getByText('2'));
    fireEvent.click(screen.getByText('0'));
    fireEvent.click(screen.getByText('discount.apply'));
    fireEvent.click(screen.getByText('1'));
    fireEvent.click(screen.getByText('2'));
    fireEvent.click(screen.getByText('3'));
    fireEvent.click(screen.getByText('4'));
    fireEvent.click(screen.getByText('discount.authorize'));

    await waitFor(() => expect(onApply).toHaveBeenCalledOnce());
    expect(authorPosOverride).toHaveBeenCalledWith(expect.objectContaining({
      approvalScope: 'discount_limit_override',
      supervisor: expect.objectContaining({ id: 'manager-1' }),
    }));
    expect(onApply).toHaveBeenCalledWith({
      type: 'percentage',
      value: '20',
      reason: '',
      approvalEvidence: evidence,
    });
  });

  it('does not apply a manager-approved discount when evidence authoring fails', async () => {
    vi.mocked(apiPost).mockResolvedValue({
      id: 'manager-1',
      name: 'Manager',
      roles: ['manager'],
      can_discount: true,
      max_discount_percent: 50,
    });
    vi.mocked(authorPosOverride).mockRejectedValue(new Error('chain unavailable'));
    const onApply = vi.fn();
    renderModal({
      onApplyTransactionDiscount: onApply,
      maxDiscountPercent: 10,
      terminalMaxDiscountPercent: 50,
    });

    fireEvent.click(screen.getByText('2'));
    fireEvent.click(screen.getByText('0'));
    fireEvent.click(screen.getByText('discount.apply'));
    fireEvent.click(screen.getByText('1'));
    fireEvent.click(screen.getByText('2'));
    fireEvent.click(screen.getByText('3'));
    fireEvent.click(screen.getByText('4'));
    fireEvent.click(screen.getByText('discount.authorize'));

    await screen.findByText('discount.invalidPin');
    expect(onApply).not.toHaveBeenCalled();
  });
});

describe('DiscountModal — focus management (PR #97 follow-up)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders with role="dialog", aria-modal, and aria-labelledby pointing at the title', () => {
    renderModal();
    const dialog = screen.getByTestId('discount-modal-dialog');
    expect(dialog.getAttribute('role')).toBe('dialog');
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(dialog.getAttribute('aria-labelledby')).toBe('discount-modal-title');
    // The labelled element exists.
    expect(document.getElementById('discount-modal-title')).not.toBeNull();
  });

  it('restores focus to the opener when the modal closes', () => {
    function Harness() {
      const [isOpen, setIsOpen] = useState(false);
      return (
        <>
          <button
            data-testid="opener"
            onClick={() => setIsOpen(true)}
          >
            open
          </button>
          <DiscountModal
            isOpen={isOpen}
            onClose={() => setIsOpen(false)}
            onApplyTransactionDiscount={vi.fn()}
            canDiscount
            maxDiscountPercent={100}
            terminalMaxDiscountPercent={100}
            requiresReason={false}
          />
        </>
      );
    }

    render(<Harness />);
    const opener = screen.getByTestId('opener');
    opener.focus();
    expect(document.activeElement).toBe(opener);

    // Open the modal — useFocusTrap's render-phase capture pins the opener.
    fireEvent.click(opener);
    const dialog = screen.getByTestId('discount-modal-dialog');
    expect(dialog).toBeInTheDocument();
    // Focus has moved off the opener (into the dialog's first focusable).
    expect(document.activeElement).not.toBe(opener);
    expect(dialog.contains(document.activeElement)).toBe(true);

    // Close via the cancel button (header).
    fireEvent.click(screen.getByRole('button', { name: 'discount.cancel' }));

    // Dialog gone, focus restored.
    expect(screen.queryByTestId('discount-modal-dialog')).not.toBeInTheDocument();
    expect(document.activeElement).toBe(opener);
  });
});
