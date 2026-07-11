import type { InputHTMLAttributes, ChangeEvent } from 'react'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors, focusRing, semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { useCurrency } from '@/hooks/useCurrency'

export interface MoneyInputProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'type'> {
  label?: string | undefined
  value: string
  onChange: (value: string) => void
  currency?: string | undefined
  touchOptimized?: boolean | undefined
  error?: string | undefined
}

export function MoneyInput({
  label,
  value,
  onChange,
  currency: currencyProp,
  touchOptimized = false,
  error,
  placeholder: placeholderProp,
  disabled,
  className,
  ...props
}: MoneyInputProps) {
  const { currency: companyCurrency, decimals } = useCurrency()
  const currency = currencyProp ?? companyCurrency
  const placeholder = placeholderProp ?? `0.${'0'.repeat(decimals)}`

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const inputValue = e.target.value

    // Allow empty value
    if (inputValue === '') {
      onChange('')
      return
    }

    // Validate: only numbers and one decimal point
    const regex = /^\d*\.?\d*$/
    if (!regex.test(inputValue)) {
      return
    }

    // Check if there's more than one decimal point
    const decimalCount = (inputValue.match(/\./g) || []).length
    if (decimalCount > 1) {
      return
    }

    // Limit decimal places based on currency
    if (inputValue.includes('.')) {
      const [integer, decimal] = inputValue.split('.')
      if (decimal && decimal.length > decimals) {
        onChange(`${integer}.${decimal.slice(0, decimals)}`)
        return
      }
    }

    onChange(inputValue)
  }

  return (
    <div className={cn('flex flex-col gap-1', className)}>
      {label && (
        <label className={cn('text-sm font-medium', textColors.secondary)}>{label}</label>
      )}
      <div className="relative">
        <input
          type="text"
          inputMode="decimal"
          value={value}
          onChange={handleChange}
          placeholder={placeholder}
          disabled={disabled}
          className={cn(
            // Base styles
            'w-full rounded-lg border',
            'focus:outline-none focus:ring-2',
            focusRing.primary,
            'transition-colors duration-150',

            // Normal size
            !touchOptimized && 'px-4 py-2 text-base',

            // Touch optimized
            touchOptimized && 'px-6 py-4 text-xl',

            // Padding for currency symbol
            'pe-16',

            // Error state
            error
              ? cn(borderColors.error, focusRing.error)
              : cn(borderColors.default, `${colorTokens.variants.focusBorderBlue500}`),

            // Disabled state
            disabled && cn(colors.neutral[100], 'cursor-not-allowed opacity-50')
          )}
          {...props}
        />
        <div
          className={cn(
            'absolute end-0 top-0 bottom-0',
            'flex items-center pe-4',
            'font-medium',
            textColors.tertiary,
            touchOptimized && 'text-xl'
          )}
        >
          {currency}
        </div>
      </div>
      {error && <span className={cn('text-sm', textColors.error)}>{error}</span>}
    </div>
  )
}
