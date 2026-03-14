import { forwardRef, type SelectHTMLAttributes } from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  /**
   * Error state for validation feedback
   */
  error?: boolean | undefined
}

/**
 * Select - Dropdown/select primitive component
 *
 * A flexible select/dropdown component that follows the atomic design pattern.
 * Uses design tokens for consistent styling across the application.
 *
 * @example
 * ```tsx
 * // Basic usage
 * <Select>
 *   <option value="">Select option</option>
 *   <option value="1">Option 1</option>
 * </Select>
 *
 * // With React Hook Form
 * <Select {...register('type')} error={!!errors.type}>
 *   <option value="">Select type</option>
 *   <option value="customer">Customer</option>
 * </Select>
 *
 * // With custom className
 * <Select className="w-full">
 *   <option>Choose...</option>
 * </Select>
 * ```
 */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  ({ className, error, children, ...props }, ref) => {
    // Compose classes: base + error state + custom
    const classes = cn(
      tokens.select.base,
      error && tokens.select.error,
      className
    )

    return (
      <select ref={ref} className={classes} {...props}>
        {children}
      </select>
    )
  }
)

Select.displayName = 'Select'
