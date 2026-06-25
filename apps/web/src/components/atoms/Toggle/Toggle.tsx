import { forwardRef, type InputHTMLAttributes } from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

export type ToggleProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
  /**
   * Optional text label rendered immediately after the track. The caller is
   * responsible for meaningful label text — the atom itself never contains
   * hardcoded user-facing strings (CLAUDE.md §11 / i18n rule).
   *
   * For screen-reader support without a visible label, pass `aria-label` or
   * `aria-labelledby` instead.
   */
  label?: string
}

/**
 * Toggle — on/off switch primitive component
 *
 * Implements `role="switch"` + `aria-checked` for full accessibility.
 * Built on a visually-hidden native `<input type="checkbox">` so that
 * react-hook-form's `register()` ref-forwarding works out-of-the-box
 * (the ref forwards to that input, identical to the Checkbox pattern).
 * The track + knob are styled siblings rendered via CSS.
 *
 * **Design choice — hidden input vs. button:** A hidden `<input type="checkbox">`
 * is preferred over `<button role="switch">` because:
 *   1. RHF `register()` returns a `ref` typed `RefCallback<HTMLInputElement>`,
 *      which maps directly to an input element — no adapter layer needed.
 *   2. Native `<input type="checkbox">` fires `onChange(e)` on Space/Enter by
 *      default, matching the Checkbox atom's `onChange(e)` signature exactly.
 *   3. The hidden input is still accessible: it carries `role="switch"`,
 *      `aria-checked`, and the forwarded `aria-label`/`aria-labelledby`, so
 *      AT software announces it correctly even though it is visually hidden.
 *
 * @example
 * ```tsx
 * // Controlled
 * <Toggle
 *   aria-label="Track batches & expiry"
 *   checked={value}
 *   onChange={(e) => setValue(e.target.checked)}
 * />
 *
 * // With React Hook Form
 * <Toggle {...register('requires_batch_tracking')} aria-label="Track batches" />
 *
 * // With visible label
 * <Toggle aria-label="Universal fit" label="Universal fit" checked={val} onChange={…} />
 * ```
 */
export const Toggle = forwardRef<HTMLInputElement, ToggleProps>(
  ({ className, checked, disabled, label, ...props }, ref) => {
    return (
      <label
        className={cn('inline-flex cursor-pointer items-center gap-2', disabled && 'cursor-not-allowed')}
      >
        {/* Track — the visual switch shell */}
        <span
          data-toggle-track=""
          className={cn(
            tokens.toggle.track,
            checked ? tokens.toggle.trackOn : tokens.toggle.trackOff,
            disabled && tokens.toggle.trackDisabled,
            className,
          )}
        >
          {/* Visually-hidden native input — carries role/aria, receives ref */}
          <input
            ref={ref}
            type="checkbox"
            role="switch"
            aria-checked={checked}
            checked={checked}
            disabled={disabled}
            className="sr-only"
            {...props}
          />
          {/* Knob */}
          <span
            aria-hidden="true"
            className={cn(tokens.toggle.knob, checked && tokens.toggle.knobOn)}
          />
        </span>

        {/* Optional visible label — caller-supplied, never hardcoded */}
        {label !== undefined && <span className="text-sm">{label}</span>}
      </label>
    )
  }
)

Toggle.displayName = 'Toggle'
