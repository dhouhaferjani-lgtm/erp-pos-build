import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CashPaymentScreen, type CashPaymentScreenProps, computeCashTenderState } from '../CashPaymentScreen';

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
  NumPad: ({ value, onChange }: { value: string; onChange: (v: string) => void }) => (
    <div>
      <span data-testid="numpad-value">{value}</span>
      <button data-testid="numpad-clear" onClick={() => onChange('')}>
        NumPad clear
      </button>
      {/* Mirrors real NumPad.handleKey('digit') — append the digit to value */}
      <button data-testid="numpad-press-5" onClick={() => onChange(value + '5')}>
        Press 5
      </button>
      <button data-testid="numpad-backspace" onClick={() => onChange(value.slice(0, -1))}>
        Backspace
      </button>
    </div>
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

  it('calls onConfirm with tendered amount STRING (not float) when confirm clicked', () => {
    const onConfirm = vi.fn();
    renderScreen({ total: 3, onConfirm });
    // Click a denomination — 50 EUR → tenderedStr should be '50.00' (bcformat string)
    fireEvent.click(screen.getByText('50 EUR'));
    fireEvent.click(screen.getByText('cashPayment.complete'));
    expect(onConfirm).toHaveBeenCalledWith('50.00');
  });

  it('error message renders when error prop provided', () => {
    renderScreen({ error: 'Payment failed' });
    expect(screen.getByText('Payment failed')).toBeInTheDocument();
  });

  it('does not render error section when error is not provided', () => {
    renderScreen({ error: null });
    expect(screen.queryByText('Payment failed')).not.toBeInTheDocument();
  });

  it('hides the change-due box until change is positive', () => {
    renderScreen({ total: 25 });
    // No over-tender yet → change is 0 → box not rendered.
    expect(screen.queryByText('cashPayment.changeDue')).not.toBeInTheDocument();
  });

  it('shows change due box once tendered exceeds total', () => {
    // total=3, tender 50 via denomination → change 47 → box shown.
    renderScreen({ total: 3 });
    fireEvent.click(screen.getByText('50 EUR'));
    expect(screen.getByText('cashPayment.changeDue')).toBeInTheDocument();
  });

  it('shows the disabled reason when no valid amount is tendered', () => {
    renderScreen({ total: 25 });
    expect(screen.getByText('cashPayment.enterAmount')).toBeInTheDocument();
  });

  it('digit press after Exact overwrites the preset (does not append)', () => {
    // Regression: cashier taps Exact (sets tendered to total), then taps a digit.
    // Old behavior: digit was appended (e.g. 25 + 5 = "255"), but format() rounded
    // back to 25 EUR so the display looked unchanged and the cashier was stuck.
    // New behavior: the digit overwrites the preset like industry-standard POS.
    renderScreen({ total: 25 });
    fireEvent.click(screen.getByText('cashPayment.exact'));
    expect(screen.getByTestId('numpad-value').textContent).toBe('25.00');
    fireEvent.click(screen.getByTestId('numpad-press-5'));
    // Preset is overwritten by just the new digit, not appended to "25.005"
    expect(screen.getByTestId('numpad-value').textContent).toBe('5');
  });

  it('digit press after a denomination button overwrites the preset', () => {
    renderScreen({ total: 3 });
    fireEvent.click(screen.getByText('50 EUR'));
    expect(screen.getByTestId('numpad-value').textContent).toBe('50.00');
    fireEvent.click(screen.getByTestId('numpad-press-5'));
    expect(screen.getByTestId('numpad-value').textContent).toBe('5');
  });

  it('backspace after Exact behaves normally and does not trigger overwrite', () => {
    renderScreen({ total: 25 });
    fireEvent.click(screen.getByText('cashPayment.exact'));
    expect(screen.getByTestId('numpad-value').textContent).toBe('25.00');
    fireEvent.click(screen.getByTestId('numpad-backspace'));
    // Backspace removes the trailing char from the preset, doesn't reset it
    expect(screen.getByTestId('numpad-value').textContent).toBe('25.0');
  });

  it('typing further digits after preset+overwrite appends normally', () => {
    // Preset → digit (overwrite) → next digit (append, since preset flag cleared)
    renderScreen({ total: 25 });
    fireEvent.click(screen.getByText('cashPayment.exact'));
    fireEvent.click(screen.getByTestId('numpad-press-5'));
    expect(screen.getByTestId('numpad-value').textContent).toBe('5');
    fireEvent.click(screen.getByTestId('numpad-press-5'));
    // Preset flag was cleared after the first overwrite, so this appends
    expect(screen.getByTestId('numpad-value').textContent).toBe('55');
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// computeCashTenderState — pure helper (D0-2 precision sweep)
// These tests fail on the float path and pass on the bcmath path.
// ──────────────────────────────────────────────────────────────────────────────
describe('computeCashTenderState', () => {
  it('exact change: 100.10 tendered vs 99.80 total → "0.30" (not float drift 0.30000000000000027)', () => {
    // Float path: Math.max(0, 100.10 - 99.80) = 0.30000000000000027
    // bcmath path: bcsub('100.10', '99.80', 2) = '0.30'
    const { changeDue, isValid } = computeCashTenderState('100.10', '99.80', 2);
    expect(changeDue).toBe('0.30');
    expect(isValid).toBe(true);
  });

  it('exact tender equals total: isValid=true, changeDue="0.00"', () => {
    const { changeDue, isValid } = computeCashTenderState('99.80', '99.80', 2);
    expect(isValid).toBe(true);
    expect(changeDue).toBe('0.00');
  });

  it('under-tender: isValid=false', () => {
    const { isValid } = computeCashTenderState('50.00', '99.80', 2);
    expect(isValid).toBe(false);
  });

  it('empty tender string: isValid=false, changeDue="0.00"', () => {
    const { changeDue, isValid } = computeCashTenderState('', '99.80', 2);
    expect(isValid).toBe(false);
    expect(changeDue).toBe('0.00');
  });

  it('TND scale-3: 10.100 tendered vs 9.800 total → "0.300"', () => {
    const { changeDue, isValid } = computeCashTenderState('10.100', '9.800', 3);
    expect(changeDue).toBe('0.300');
    expect(isValid).toBe(true);
  });
});
