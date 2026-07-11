import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost'
type ButtonSize = 'sm' | 'md' | 'lg'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  children: ReactNode
}

const variantStyles: Record<ButtonVariant, string> = {
  primary:
    `${colorTokens.intent.primary.bgStrong} ${colorTokens.text.inverse} ${colorTokens.variants.hoverBgBlue700} ${colorTokens.focus.primaryRing} ${colorTokens.variants.disabledBgBlue400}`,
  secondary:
    `${colorTokens.surface.base} ${colorTokens.text.secondary} border ${colorTokens.border.default} ${colorTokens.variants.hoverBgGray50} ${colorTokens.focus.neutralRing}`,
  danger:
    `${colorTokens.intent.danger.bgStrong} ${colorTokens.text.inverse} ${colorTokens.variants.hoverBgRed700} ${colorTokens.focus.dangerRing} ${colorTokens.variants.disabledBgRed400}`,
  ghost: `bg-transparent ${colorTokens.text.muted} ${colorTokens.variants.hoverBgGray100} ${colorTokens.focus.neutralRing}`,
}

const sizeStyles: Record<ButtonSize, string> = {
  sm: 'px-3 py-1.5 text-sm',
  md: 'px-4 py-2 text-sm',
  lg: 'px-6 py-3 text-base',
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  (
    {
      variant = 'primary',
      size = 'md',
      className = '',
      disabled,
      children,
      ...props
    },
    ref
  ) => {
    return (
      <button
        ref={ref}
        disabled={disabled}
        className={`
          inline-flex items-center justify-center rounded-[var(--radius-button)] font-medium
          transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2
          disabled:cursor-not-allowed disabled:opacity-50
          ${variantStyles[variant]}
          ${sizeStyles[size]}
          ${className}
        `}
        {...props}
      >
        {children}
      </button>
    )
  }
)

Button.displayName = 'Button'
