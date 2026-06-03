import { forwardRef, type InputHTMLAttributes } from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

type NativeInputProps = Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'value' | 'onChange' | 'type' | 'step' | 'min' | 'max'
>

export interface QuantityInputProps extends NativeInputProps {
  /**
   * The current value as a canonical decimal string. Never a JS number —
   * keeping quantities out of the IEEE 754 float pipeline preserves precision.
   */
  value: string
  /**
   * Called on every keystroke with the RAW string from the input. The value
   * is passed through verbatim (no parseFloat), so the canonical string is
   * preserved end-to-end.
   */
  onChange: (value: string) => void
  /**
   * Number of decimal places the quantity supports. Drives the numeric step
   * (e.g. 4 → 0.0001, 2 → 0.01).
   */
  decimalPlaces: number
  /**
   * Minimum value (string preferred; number accepted for form ergonomics).
   * Defaults to '0'.
   */
  min?: string | number
  /**
   * Maximum value (string preferred; number accepted for form ergonomics).
   */
  max?: string | number
  /**
   * Error state for validation feedback.
   */
  error?: boolean
}

/**
 * QuantityInput - precision-safe quantity number input.
 *
 * Renders a native number input whose `step` is derived from `decimalPlaces`.
 * The onChange callback ALWAYS receives the raw string value (never a JS
 * number), so quantity precision is never lost to float coercion.
 *
 * @example
 * ```tsx
 * <QuantityInput value={qty} onChange={setQty} decimalPlaces={4} />
 * ```
 */
export const QuantityInput = forwardRef<HTMLInputElement, QuantityInputProps>(
  ({ value, onChange, decimalPlaces, min = '0', max, error, className, ...rest }, ref) => {
    const step = String(1 / 10 ** decimalPlaces)

    const classes = cn(tokens.input.base, error && tokens.input.error, className)

    function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
      // Pass the raw string straight through — no parseFloat, no Number().
      onChange(e.target.value)
    }

    return (
      <input
        ref={ref}
        type="number"
        inputMode="decimal"
        step={step}
        min={min}
        max={max}
        value={value}
        onChange={handleChange}
        className={classes}
        {...rest}
      />
    )
  },
)

QuantityInput.displayName = 'QuantityInput'
