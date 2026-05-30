import { forwardRef, useState, type InputHTMLAttributes } from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'
import { getDecimals } from '../../../hooks/useCurrency'
import { formatCurrency } from '../../../lib/decimal'

type NativeInputProps = Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'value' | 'onChange' | 'type' | 'step' | 'min' | 'max'
>

export interface MoneyInputProps extends NativeInputProps {
  /**
   * The current value as a canonical decimal string. Never a JS number —
   * keeping money out of the IEEE 754 float pipeline preserves precision.
   */
  value: string
  /**
   * Called on every keystroke with the RAW string from the input. The value
   * is passed through verbatim (no parseFloat), so the canonical string is
   * preserved end-to-end.
   */
  onChange: (value: string) => void
  /**
   * ISO currency code (e.g. 'EUR', 'TND'). Drives the numeric step so that
   * 3-decimal currencies (TND → 0.001) and 2-decimal currencies (EUR → 0.01)
   * accept their smallest representable unit.
   */
  currency: string
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
  /**
   * Format the display value via formatCurrency on blur. The emitted onChange
   * value remains the canonical string regardless of this flag.
   */
  formatOnBlur?: boolean
}

/**
 * MoneyInput - precision-safe monetary number input.
 *
 * Renders a native number input whose `step` is derived from the currency's
 * decimal count. The onChange callback ALWAYS receives the raw string value
 * (never a JS number), so monetary precision is never lost to float coercion.
 *
 * @example
 * ```tsx
 * <MoneyInput value={amount} onChange={setAmount} currency="TND" />
 * ```
 */
export const MoneyInput = forwardRef<HTMLInputElement, MoneyInputProps>(
  (
    {
      value,
      onChange,
      currency,
      min = '0',
      max,
      error,
      formatOnBlur = false,
      className,
      onBlur,
      ...rest
    },
    ref,
  ) => {
    // Optional blurred display string layered ON TOP of the canonical value.
    // null means "show the canonical value verbatim".
    const [blurredDisplay, setBlurredDisplay] = useState<string | null>(null)

    const step = String(1 / 10 ** getDecimals(currency))

    const classes = cn(tokens.input.base, error && tokens.input.error, className)

    function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
      const raw = e.target.value
      // Editing clears any blurred-format overlay so the user sees raw input.
      if (formatOnBlur) {
        setBlurredDisplay(null)
      }
      // Pass the raw string straight through — no parseFloat, no Number().
      onChange(raw)
    }

    function handleBlur(e: React.FocusEvent<HTMLInputElement>) {
      if (formatOnBlur && e.target.value !== '') {
        setBlurredDisplay(formatCurrency(e.target.value, false, currency))
      }
      onBlur?.(e)
    }

    return (
      <input
        ref={ref}
        type="number"
        inputMode="decimal"
        step={step}
        min={min}
        max={max}
        value={formatOnBlur && blurredDisplay !== null ? blurredDisplay : value}
        onChange={handleChange}
        onBlur={handleBlur}
        className={classes}
        {...rest}
      />
    )
  },
)

MoneyInput.displayName = 'MoneyInput'
