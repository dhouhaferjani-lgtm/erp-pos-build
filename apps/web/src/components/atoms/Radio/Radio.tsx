import { forwardRef, type InputHTMLAttributes } from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

export type RadioProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>

/**
 * Radio - Single-choice input primitive component
 *
 * A flexible radio component that follows the atomic design pattern, mirroring
 * the {@link Checkbox} atom. Uses design tokens for consistent styling across
 * the application, so call sites no longer need to reach for the raw
 * `<input type="radio">` element and `tokens.radio.base` by hand.
 *
 * As with the native control, grouping is driven by a shared `name` and the
 * `checked` / `value` / `onChange` props the caller forwards — the atom simply
 * renders the styled input and passes everything through.
 *
 * @example
 * ```tsx
 * // Basic usage
 * <Radio name="method" value="fifo" checked={value === 'fifo'} onChange={() => setValue('fifo')} />
 *
 * // With React Hook Form
 * <Radio {...register('method')} value="fifo" />
 *
 * // With extra layout classes
 * <Radio className="mt-1" />
 * ```
 */
export const Radio = forwardRef<HTMLInputElement, RadioProps>(
  ({ className, ...props }, ref) => {
    return (
      <input
        ref={ref}
        type="radio"
        className={cn(tokens.radio.base, className)}
        {...props}
      />
    )
  }
)

Radio.displayName = 'Radio'
