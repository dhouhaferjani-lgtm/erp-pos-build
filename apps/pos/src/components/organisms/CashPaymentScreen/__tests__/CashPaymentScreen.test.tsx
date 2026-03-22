import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CashPaymentScreen, type CashPaymentScreenProps } from '../CashPaymentScreen';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
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

vi.mock('@/components/molecules/NumPad', () => ({
  NumPad: ({ onChange }: { value: string; onChange: (v: string) => void }) => (
    <button data-testid="numpad-clear" onClick={() => onChange('')}>
      NumPad
    </button>
  ),
}));

vi.mock('@/lib/denominations', () => ({
  getDenominations: (_currency: string, total: number) => {
    const all = [5, 10, 20, 50, 100];
    return all.filter((d) => d >= total);
  },
}));

function renderScreen(overrides: Partial<CashPaymentScreenProps> = {}) {
  const defaults: CashPaymentScreenProps = {
    isOpen: true,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
    total: 25,
    isProcessing: false,
  };
  return render(<CashPaymentScreen {...defaults} {...overrides} />);
}

describe('CashPaymentScreen', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders nothing when isOpen is false', () => {
    renderScreen({ isOpen: false });
    expect(screen.queryByText('cashPayment.amountDue')).not.toBeInTheDocument();
    expect(screen.queryByText('cashPayment.title')).not.toBeInTheDocument();
  });

  it('shows amount due when open', () => {
    renderScreen({ total: 42.5 });
    expect(screen.getByText('42.50 EUR')).toBeInTheDocument();
    expect(screen.getByText('cashPayment.amountDue')).toBeInTheDocument();
  });

  it('shows denomination buttons', () => {
    // total=3, denominations >= 3 are: 5, 10, 20, 50, 100
    renderScreen({ total: 3 });
    expect(screen.getByText('5 EUR')).toBeInTheDocument();
    expect(screen.getByText('10 EUR')).toBeInTheDocument();
    expect(screen.getByText('50 EUR')).toBeInTheDocument();
  });

  it('"Exact" button enables the confirm button', () => {
    renderScreen({ total: 25 });
    // Before clicking exact, confirm is disabled
    expect(screen.getByText('cashPayment.complete').closest('button')).toBeDisabled();
    fireEvent.click(screen.getByText('cashPayment.exact'));
    // After clicking exact, tendered == total so confirm should be enabled
    expect(screen.getByText('cashPayment.complete').closest('button')).not.toBeDisabled();
  });

  it('confirm button is disabled when tendered is less than total', () => {
    renderScreen({ total: 25 });
    // No amount tendered yet, confirm should be disabled
    const confirmBtn = screen.getByText('cashPayment.complete').closest('button');
    expect(confirmBtn).toBeDisabled();
  });

  it('confirm button is disabled when processing', () => {
    renderScreen({ total: 25, isProcessing: true });
    // When processing and no tendered, button is disabled
    const confirmBtn = screen.getByText('cashPayment.processing').closest('button');
    expect(confirmBtn).toBeDisabled();
  });

  it('calls onConfirm with tendered amount when confirm clicked', () => {
    const onConfirm = vi.fn();
    renderScreen({ total: 3, onConfirm });
    // Click a denomination
    fireEvent.click(screen.getByText('50 EUR'));
    fireEvent.click(screen.getByText('cashPayment.complete'));
    expect(onConfirm).toHaveBeenCalledWith(50);
  });

  it('error message renders when error prop provided', () => {
    renderScreen({ error: 'Payment failed' });
    expect(screen.getByText('Payment failed')).toBeInTheDocument();
  });

  it('does not render error section when error is not provided', () => {
    renderScreen({ error: null });
    expect(screen.queryByText('Payment failed')).not.toBeInTheDocument();
  });

  it('shows change due label', () => {
    renderScreen({ total: 25 });
    expect(screen.getByText('cashPayment.changeDue')).toBeInTheDocument();
  });
});
