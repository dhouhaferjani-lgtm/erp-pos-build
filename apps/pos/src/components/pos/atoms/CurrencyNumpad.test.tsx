import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CurrencyNumpad } from './CurrencyNumpad';

describe('CurrencyNumpad', () => {
  it('appends a digit when tapped (EUR, scale 2)', () => {
    const onChange = vi.fn();
    render(<CurrencyNumpad value="12" onChange={onChange} currencyCode="EUR" />);
    fireEvent.click(screen.getByTestId('numpad-digit-3'));
    expect(onChange).toHaveBeenCalledWith('123');
  });

  it('rejects extra decimal once scale is reached (EUR scale 2)', () => {
    const onChange = vi.fn();
    render(<CurrencyNumpad value="1.23" onChange={onChange} currencyCode="EUR" />);
    fireEvent.click(screen.getByTestId('numpad-digit-4'));
    expect(onChange).not.toHaveBeenCalled();
  });

  it('allows 3 decimals for TND', () => {
    const onChange = vi.fn();
    render(<CurrencyNumpad value="1.23" onChange={onChange} currencyCode="TND" />);
    fireEvent.click(screen.getByTestId('numpad-digit-4'));
    expect(onChange).toHaveBeenCalledWith('1.234');
  });

  it('disables decimal button for currencies with scale 0', () => {
    render(<CurrencyNumpad value="" onChange={vi.fn()} currencyCode="JPY" />);
    expect(screen.getByTestId('numpad-dot')).toBeDisabled();
  });

  it('disables ALL buttons when disabled prop is true', () => {
    render(<CurrencyNumpad value="" onChange={vi.fn()} currencyCode="EUR" disabled />);
    expect(screen.getByTestId('numpad-digit-1')).toBeDisabled();
    expect(screen.getByTestId('numpad-backspace')).toBeDisabled();
  });

  it('backspace removes last char', () => {
    const onChange = vi.fn();
    render(<CurrencyNumpad value="12.34" onChange={onChange} currencyCode="EUR" />);
    fireEvent.click(screen.getByTestId('numpad-backspace'));
    expect(onChange).toHaveBeenCalledWith('12.3');
  });

  it('replaces leading 0 with digit', () => {
    const onChange = vi.fn();
    render(<CurrencyNumpad value="0" onChange={onChange} currencyCode="EUR" />);
    fireEvent.click(screen.getByTestId('numpad-digit-5'));
    expect(onChange).toHaveBeenCalledWith('5');
  });

  it('rejects second decimal point', () => {
    const onChange = vi.fn();
    render(<CurrencyNumpad value="1.2" onChange={onChange} currencyCode="EUR" />);
    fireEvent.click(screen.getByTestId('numpad-dot'));
    expect(onChange).not.toHaveBeenCalled();
  });
});
