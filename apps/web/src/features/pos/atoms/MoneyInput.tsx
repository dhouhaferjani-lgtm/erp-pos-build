import type { InputHTMLAttributes, ChangeEvent } from 'react'
import { cn } from '@/lib/utils'
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
        <label className="text-sm font-medium text-gray-700">{label}</label>
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
            'focus:outline-none focus:ring-2 focus:ring-blue-500',
            'transition-colors duration-150',

            // Normal size
            !touchOptimized && 'px-4 py-2 text-base',

            // Touch optimized
            touchOptimized && 'px-6 py-4 text-xl',

            // Padding for currency symbol
            'pe-16',

            // Error state
            error
              ? 'border-red-500 focus:ring-red-500'
              : 'border-gray-300 focus:border-blue-500',

            // Disabled state
            disabled && 'bg-gray-100 cursor-not-allowed opacity-50'
          )}
          {...props}
        />
        <div
          className={cn(
            'absolute end-0 top-0 bottom-0',
            'flex items-center pe-4',
            'text-gray-500 font-medium',
            touchOptimized && 'text-xl'
          )}
        >
          {currency}
        </div>
      </div>
      {error && <span className="text-sm text-red-600">{error}</span>}
    </div>
  )
}
