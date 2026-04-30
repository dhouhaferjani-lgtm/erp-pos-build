import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { RefundDestination } from '../RefundDestinationPicker';
import { defaultDestination } from '../RefundDestinationPicker';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: { defaultValue?: string }) => opts?.defaultValue ?? key,
    i18n: { language: 'en' },
  }),
}));

// Import after mock is hoisted
import { RefundDestinationPicker, RefundDestinationPickerStateful } from '../RefundDestinationPicker';

describe('defaultDestination()', () => {
  it('returns "original" when allowedDestinations is undefined', () => {
    expect(defaultDestination(undefined)).toBe('original');
  });

  it('returns "original" when allowedDestinations includes it', () => {
    expect(defaultDestination(['original', 'cash', 'store_voucher'])).toBe('original');
  });

  it('falls back to "store_voucher" when original is not allowed', () => {
    expect(defaultDestination(['cash', 'store_voucher'])).toBe('store_voucher');
  });

  it('falls back to "cash" when only cash is allowed', () => {
    expect(defaultDestination(['cash'])).toBe('cash');
  });

  it('falls back to "original" (default) when allowedDestinations is empty', () => {
    // Spec fallback: returns 'original' even when not in allowed list (nothing allowed = server bug)
    expect(defaultDestination([])).toBe('original');
  });
});

describe('RefundDestinationPicker', () => {
  const noop = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders all three radios when allowedDestinations is undefined', () => {
    render(
      <RefundDestinationPicker value="original" onChange={noop} />,
    );

    expect(screen.getByTestId('refund-destination-option-original')).toBeInTheDocument();
    expect(screen.getByTestId('refund-destination-option-cash')).toBeInTheDocument();
    expect(screen.getByTestId('refund-destination-option-store_voucher')).toBeInTheDocument();
  });

  it('hides destinations not in allowedDestinations when provided', () => {
    render(
      <RefundDestinationPicker
        value="cash"
        onChange={noop}
        allowedDestinations={['cash']}
      />,
    );

    expect(screen.queryByTestId('refund-destination-option-original')).not.toBeInTheDocument();
    expect(screen.getByTestId('refund-destination-option-cash')).toBeInTheDocument();
    expect(screen.queryByTestId('refund-destination-option-store_voucher')).not.toBeInTheDocument();
  });

  it('shows all three when all are in allowedDestinations', () => {
    render(
      <RefundDestinationPicker
        value="original"
        onChange={noop}
        allowedDestinations={['original', 'cash', 'store_voucher']}
      />,
    );

    expect(screen.getByTestId('refund-destination-option-original')).toBeInTheDocument();
    expect(screen.getByTestId('refund-destination-option-cash')).toBeInTheDocument();
    expect(screen.getByTestId('refund-destination-option-store_voucher')).toBeInTheDocument();
  });

  it('marks the current value as checked', () => {
    render(
      <RefundDestinationPicker value="cash" onChange={noop} />,
    );

    const cashRadio = screen.getByTestId('refund-destination-radio-cash') as HTMLInputElement;
    const originalRadio = screen.getByTestId('refund-destination-radio-original') as HTMLInputElement;
    expect(cashRadio.checked).toBe(true);
    expect(originalRadio.checked).toBe(false);
  });

  it('calls onChange when a different radio is selected', () => {
    const onChange = vi.fn();
    render(
      <RefundDestinationPicker value="original" onChange={onChange} />,
    );

    fireEvent.click(screen.getByTestId('refund-destination-radio-cash'));
    expect(onChange).toHaveBeenCalledWith('cash');
  });

  it('does NOT render proration breakdown when prop is absent', () => {
    render(
      <RefundDestinationPicker value="original" onChange={noop} />,
    );

    expect(screen.queryByTestId('proration-breakdown')).not.toBeInTheDocument();
  });

  it('does NOT render proration breakdown when value is not "original"', () => {
    render(
      <RefundDestinationPicker
        value="cash"
        onChange={noop}
        prorationBreakdown={[{ instrument: 'Card', amount: '10.00 EUR' }]}
      />,
    );

    expect(screen.queryByTestId('proration-breakdown')).not.toBeInTheDocument();
  });

  it('renders proration breakdown rows when prop is provided and value is "original"', () => {
    const breakdown = [
      { instrument: 'Card •••• 1234', amount: '8.00 EUR' },
      { instrument: 'Cash', amount: '2.00 EUR' },
    ];

    render(
      <RefundDestinationPicker value="original" onChange={noop} prorationBreakdown={breakdown} />,
    );

    expect(screen.getByTestId('proration-breakdown')).toBeInTheDocument();
    expect(screen.getByTestId('proration-row-0')).toHaveTextContent('Card •••• 1234');
    expect(screen.getByTestId('proration-row-0')).toHaveTextContent('8.00 EUR');
    expect(screen.getByTestId('proration-row-1')).toHaveTextContent('Cash');
    expect(screen.getByTestId('proration-row-1')).toHaveTextContent('2.00 EUR');
  });
});

describe('RefundDestinationPickerStateful', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('defaults selection to OriginalPayment when allowedDestinations is undefined', () => {
    render(
      <RefundDestinationPickerStateful onConfirm={vi.fn()} />,
    );

    const originalRadio = screen.getByTestId('refund-destination-radio-original') as HTMLInputElement;
    expect(originalRadio.checked).toBe(true);
  });

  it('defaults to store_voucher when original is not allowed', () => {
    render(
      <RefundDestinationPickerStateful
        allowedDestinations={['cash', 'store_voucher']}
        onConfirm={vi.fn()}
      />,
    );

    const svRadio = screen.getByTestId('refund-destination-radio-store_voucher') as HTMLInputElement;
    expect(svRadio.checked).toBe(true);
  });

  it('calls onConfirm with selected destination when confirm button clicked', () => {
    const onConfirm = vi.fn();
    render(
      <RefundDestinationPickerStateful
        allowedDestinations={['cash']}
        onConfirm={onConfirm}
      />,
    );

    fireEvent.click(screen.getByTestId('refund-destination-confirm'));
    expect(onConfirm).toHaveBeenCalledWith('cash');
  });
});
