import {
  forwardRef,
  useEffect,
  useRef,
  type InputHTMLAttributes,
} from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

export type CheckboxProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
  /**
   * Renders the native "indeterminate" (dash) state. There is no HTML attribute
   * for this — the property is only settable on the DOM element — so we manage
   * it via an internal ref + effect, merged with any forwarded ref.
   */
  indeterminate?: boolean
}

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
  ({ className, indeterminate, ...props }, ref) => {
    const classes = cn(tokens.checkbox.base, className)
    const innerRef = useRef<HTMLInputElement | null>(null)

    // Merge the internal ref (used to drive `indeterminate`) with whatever ref
    // the caller forwarded (object ref or callback ref).
    const setRefs = (node: HTMLInputElement | null) => {
      innerRef.current = node
      if (typeof ref === 'function') {
        ref(node)
      } else if (ref) {
        ref.current = node
      }
    }

    useEffect(() => {
      if (innerRef.current) {
        innerRef.current.indeterminate = Boolean(indeterminate)
      }
    }, [indeterminate])

    return <input ref={setRefs} type="checkbox" className={classes} {...props} />
  }
)

Checkbox.displayName = 'Checkbox'
