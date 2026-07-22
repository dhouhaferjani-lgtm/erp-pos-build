import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { QuantityInput } from '@/components/atoms/QuantityInput';

describe('QuantityInput', () => {
  it('renders integer-only affordances at zero decimals', () => {
    render(<QuantityInput value="3" onChange={() => {}} decimalPlaces={0} ariaLabel="qty" />);
    const input = screen.getByLabelText('qty');
    expect(input).toHaveAttribute('pattern', '^\\d+$');
    expect(input).toHaveAttribute('inputMode', 'numeric');
    expect(input).toHaveAttribute('step', '1');
  });

  it('renders decimal affordances at three decimals', () => {
    render(<QuantityInput value="1.250" onChange={() => {}} decimalPlaces={3} ariaLabel="qty" />);
    const input = screen.getByLabelText('qty');
    expect(input).toHaveAttribute('pattern', '^\\d+(\\.\\d{1,3})?$');
    expect(input).toHaveAttribute('step', '0.001');
    expect(input).toHaveAttribute('inputMode', 'decimal');
    // pattern must accept a 3-decimal value like 1.250
    expect(new RegExp((input as HTMLInputElement).pattern).test('1.250')).toBe(true);
  });

  it('emits the raw string on change', () => {
    const onChange = vi.fn();
    render(<QuantityInput value="" onChange={onChange} decimalPlaces={0} ariaLabel="qty" />);
    fireEvent.change(screen.getByLabelText('qty'), { target: { value: '5' } });
    expect(onChange).toHaveBeenCalledWith('5');
  });

  it('sets aria-invalid when invalid', () => {
    render(<QuantityInput value="x" onChange={() => {}} decimalPlaces={0} invalid ariaLabel="qty" />);
    expect(screen.getByLabelText('qty')).toHaveAttribute('aria-invalid', 'true');
  });
});
