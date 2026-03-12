import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CashTenderedModal, type CashTenderedModalProps } from '../CashTenderedModal';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/stores/settingsStore', () => ({
  useSettingsStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ touchMode: false, fullscreen: false }),
}));

vi.mock('@/components/molecules/NumPad', () => ({
  NumPad: () => null,
}));

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

vi.mock('@/components/pos/Modal', () => ({
  Modal: ({
    isOpen,
    children,
    title,
  }: {
    isOpen: boolean;
    children: React.ReactNode;
    title: string;
  }) =>
    isOpen ? (
      <div data-testid="modal">
        <h2>{title}</h2>
        {children}
      </div>
    ) : null,
}));

function renderModal(overrides: Partial<CashTenderedModalProps> = {}) {
  const defaults: CashTenderedModalProps = {
    isOpen: true,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
    total: 25,
    isProcessing: false,
  };
  return render(<CashTenderedModal {...defaults} {...overrides} />);
}

describe('CashTenderedModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders nothing when closed', () => {
    renderModal({ isOpen: false });
    expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
  });

  it('renders when open', () => {
    renderModal();
    expect(screen.getByTestId('modal')).toBeInTheDocument();
  });

  it('shows the total amount due', () => {
    renderModal({ total: 42.50 });
    expect(screen.getByText('42.50 EUR')).toBeInTheDocument();
  });

  it('shows denomination buttons as whole numbers', () => {
    // With total=3, all denoms (5,10,20,50) are >= total
    renderModal({ total: 3 });
    expect(screen.getByText('5 EUR')).toBeInTheDocument();
    expect(screen.getByText('10 EUR')).toBeInTheDocument();
    expect(screen.getByText('20 EUR')).toBeInTheDocument();
    expect(screen.getByText('50 EUR')).toBeInTheDocument();
  });

  it('only shows denominations >= total', () => {
    renderModal({ total: 30 });
    // 5, 10, 20 are all < 30, so not shown
    expect(screen.queryByText('5 EUR')).not.toBeInTheDocument();
    expect(screen.queryByText('10 EUR')).not.toBeInTheDocument();
    expect(screen.queryByText('20 EUR')).not.toBeInTheDocument();
    // 50 >= 30, shown
    expect(screen.getByText('50 EUR')).toBeInTheDocument();
  });

  it('sets tendered amount when denomination clicked', () => {
    renderModal({ total: 3 });

    fireEvent.click(screen.getByText('50 EUR'));

    const input = screen.getByRole('spinbutton') as HTMLInputElement;
    expect(input.value).toBe('50.00');
  });

  it('shows exact amount button', () => {
    renderModal();
    expect(screen.getByText('cashTendered.exactAmount')).toBeInTheDocument();
  });

  it('shows change due when tendered exceeds total', () => {
    renderModal({ total: 3 });

    fireEvent.click(screen.getByText('50 EUR'));

    expect(screen.getByText('cashTendered.changeDue')).toBeInTheDocument();
  });

  it('calls onConfirm with tendered amount', () => {
    const onConfirm = vi.fn();
    renderModal({ total: 3, onConfirm });

    fireEvent.click(screen.getByText('50 EUR'));
    fireEvent.click(screen.getByText('cashTendered.confirm'));

    expect(onConfirm).toHaveBeenCalledWith(50);
  });

  it('disables confirm when processing', () => {
    renderModal({ total: 25, isProcessing: true });

    expect(screen.getByText('cashTendered.processing').closest('button')).toBeDisabled();
  });

  it('shows error message when provided', () => {
    renderModal({ error: 'Payment failed' });
    expect(screen.getByText('Payment failed')).toBeInTheDocument();
  });

  it('does not show error when not provided', () => {
    renderModal();
    expect(screen.queryByText('Payment failed')).not.toBeInTheDocument();
  });
});
