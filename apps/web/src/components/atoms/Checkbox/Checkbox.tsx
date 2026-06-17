import { forwardRef, type InputHTMLAttributes } from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

export type CheckboxProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>

/**
 * Checkbox - Boolean input primitive component
 *
 * A flexible checkbox component that follows the atomic design pattern.
 * Uses design tokens for consistent styling across the application, so call
 * sites no longer need to reach for the raw `<input type="checkbox">` element
 * and `tokens.checkbox.base` by hand.
 *
 * @example
 * ```tsx
 * // Basic usage
 * <Checkbox checked={value} onChange={(e) => setValue(e.target.checked)} />
 *
 * // With React Hook Form
 * <Checkbox {...register('is_paid')} id="is_paid" />
 *
 * // With extra layout classes
 * <Checkbox className="mt-0.5" />
 * ```
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(
  ({ className, ...props }, ref) => {
    const classes = cn(tokens.checkbox.base, className)

    return <input ref={ref} type="checkbox" className={classes} {...props} />
  }
)

Checkbox.displayName = 'Checkbox'
