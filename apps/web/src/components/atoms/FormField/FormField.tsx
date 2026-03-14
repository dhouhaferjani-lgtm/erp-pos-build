import { type ReactNode } from 'react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

export interface FormFieldProps {
  /**
   * Label text for the form field
   */
  label?: string | undefined

  /**
   * Whether the field is required (shows asterisk)
   */
  required?: boolean | undefined

  /**
   * Error message to display below the field
   */
  error?: string | undefined

  /**
   * Helper text to display below the field
   */
  helperText?: string | undefined

  /**
   * The input/select/textarea component
   */
  children: ReactNode

  /**
   * HTML id for the input (links label to input)
   */
  htmlFor?: string | undefined

  /**
   * Additional CSS classes for the wrapper
   */
  className?: string | undefined
}

/**
 * FormField - Label + Input + Error wrapper component
 *
 * A composition component that wraps form inputs with labels, error messages, and helper text.
 * Follows the atomic design pattern and uses design tokens for consistent styling.
 *
 * @example
 * ```tsx
 * // Basic usage
 * <FormField label="Name" htmlFor="name">
 *   <Input id="name" />
 * </FormField>
 *
 * // With React Hook Form
 * <FormField
 *   label="Email"
 *   htmlFor="email"
 *   required
 *   error={errors.email?.message}
 * >
 *   <Input id="email" {...register('email')} />
 * </FormField>
 *
 * // With helper text
 * <FormField
 *   label="Password"
 *   htmlFor="password"
 *   helperText="Must be at least 8 characters"
 * >
 *   <Input id="password" type="password" />
 * </FormField>
 * ```
 */
export function FormField({
  label,
  required,
  error,
  helperText,
  children,
  htmlFor,
  className,
}: FormFieldProps) {
  return (
    <div className={cn('space-y-1', className)}>
      {/* Label */}
      {label && (
        <label htmlFor={htmlFor} className={tokens.label.base}>
          {label}
          {required && <span className={tokens.label.required}> *</span>}
        </label>
      )}

      {/* Input/Select/Textarea */}
      {children}

      {/* Error message */}
      {error && <p className={tokens.helperText.error}>{error}</p>}

      {/* Helper text (only shown if no error) */}
      {!error && helperText && (
        <p className={tokens.helperText.base}>{helperText}</p>
      )}
    </div>
  )
}
