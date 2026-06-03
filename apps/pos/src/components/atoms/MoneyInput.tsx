import { forwardRef, type InputHTMLAttributes } from 'react';
import { getCurrencyDecimals } from '@/lib/currency';

type NativeInputProps = Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'value' | 'onChange' | 'type' | 'step'
>;

export interface MoneyInputProps extends NativeInputProps {
  /**
   * The current value as a canonical decimal string. Never a JS number —
   * keeping money out of the IEEE 754 float pipeline preserves precision.
   */
  value: string;
  /**
   * Called on every keystroke with the RAW string from the input. The value
   * is passed through verbatim (no parseFloat), so the canonical string is
   * preserved end-to-end.
   */
  onChange: (value: string) => void;
  /**
   * ISO currency code (e.g. 'EUR', 'TND'). Drives the numeric step so that
   * 3-decimal currencies (TND → 0.001) and 2-decimal currencies (EUR → 0.01)
   * accept their smallest representable unit.
   */
  currency: string;
}

/**
 * MoneyInput — precision-safe monetary number input for apps/pos.
 *
 * Renders a native number input whose `step` is derived from the currency's
 * decimal count (via `getCurrencyDecimals`). The onChange callback ALWAYS
 * receives the raw string value (never a JS number), so monetary precision is
 * never lost to float coercion at the input boundary.
 *
 * apps/pos has no shared design-token system, so styling is passed through
 * `className` per call site to preserve each screen's existing look.
 */
export const MoneyInput = forwardRef<HTMLInputElement, MoneyInputProps>(
  ({ value, onChange, currency, ...rest }, ref) => {
    const step = String(1 / 10 ** getCurrencyDecimals(currency));

    return (
      <input
        ref={ref}
        type="number"
        inputMode="decimal"
        step={step}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        {...rest}
      />
    );
  },
);

MoneyInput.displayName = 'MoneyInput';
