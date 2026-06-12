import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/lib/currency', () => ({
  getCurrencyDecimals: (currency: string) => (currency === 'TND' ? 3 : 2),
  useCurrency: () => ({
    currency: 'TND',
    decimals: 3,
    format: (v: number | string) => `${String(v)} DT`,
  }),
}));

import { OpenShiftScreen } from './OpenShiftScreen';

describe('OpenShiftScreen', () => {
  it('shows the currency-scaled zero default (TND → 0.000, not 0.00)', () => {
    render(<OpenShiftScreen terminalName="POS01" isLoading={false} error={null} onOpenShift={vi.fn()} />);

    expect(screen.getByTestId('opening-cash-display')).toHaveTextContent('0.000');
  });

  it('renders the touch numpad and typing digits updates the amount', () => {
    render(<OpenShiftScreen terminalName="POS01" isLoading={false} error={null} onOpenShift={vi.fn()} />);

    fireEvent.click(screen.getByTestId('numpad-digit-1'));
    fireEvent.click(screen.getByTestId('numpad-digit-0'));
    fireEvent.click(screen.getByTestId('numpad-digit-0'));

    expect(screen.getByTestId('opening-cash-display')).toHaveTextContent('100');
  });

  it('caps decimals at the currency scale (TND = 3)', () => {
    render(<OpenShiftScreen terminalName="POS01" isLoading={false} error={null} onOpenShift={vi.fn()} />);

    fireEvent.click(screen.getByTestId('numpad-digit-5'));
    fireEvent.click(screen.getByTestId('numpad-dot'));
    fireEvent.click(screen.getByTestId('numpad-digit-1'));
    fireEvent.click(screen.getByTestId('numpad-digit-2'));
    fireEvent.click(screen.getByTestId('numpad-digit-3'));
    fireEvent.click(screen.getByTestId('numpad-digit-4')); // 4th decimal — rejected

    expect(screen.getByTestId('opening-cash-display')).toHaveTextContent('5.123');
  });

  it('submits the typed amount', () => {
    const onOpenShift = vi.fn();
    render(<OpenShiftScreen terminalName="POS01" isLoading={false} error={null} onOpenShift={onOpenShift} />);

    fireEvent.click(screen.getByTestId('numpad-digit-7'));
    fireEvent.click(screen.getByTestId('open-shift-submit'));

    expect(onOpenShift).toHaveBeenCalledWith('7');
  });

  it('submits the canonical currency-scaled zero when untouched', () => {
    const onOpenShift = vi.fn();
    render(<OpenShiftScreen terminalName="POS01" isLoading={false} error={null} onOpenShift={onOpenShift} />);

    fireEvent.click(screen.getByTestId('open-shift-submit'));

    expect(onOpenShift).toHaveBeenCalledWith('0.000');
  });

  it('strips a trailing decimal point on submit', () => {
    const onOpenShift = vi.fn();
    render(<OpenShiftScreen terminalName="POS01" isLoading={false} error={null} onOpenShift={onOpenShift} />);

    fireEvent.click(screen.getByTestId('numpad-digit-9'));
    fireEvent.click(screen.getByTestId('numpad-dot'));
    fireEvent.click(screen.getByTestId('open-shift-submit'));

    expect(onOpenShift).toHaveBeenCalledWith('9');
  });

  it('shows the error and disables submit while loading', () => {
    const onOpenShift = vi.fn();
    const { rerender } = render(
      <OpenShiftScreen terminalName="POS01" isLoading={false} error="boom" onOpenShift={onOpenShift} />,
    );
    expect(screen.getByText('boom')).toBeInTheDocument();

    rerender(<OpenShiftScreen terminalName="POS01" isLoading error="boom" onOpenShift={onOpenShift} />);
    fireEvent.click(screen.getByTestId('open-shift-submit'));
    expect(onOpenShift).not.toHaveBeenCalled();
  });
});
