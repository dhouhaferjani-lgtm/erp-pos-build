import { forwardRef } from 'react'
import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { colors, textColors, focusRing } from '@/lib/designTokens'

export interface POSButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'success' | 'danger'
  size?: 'sm' | 'md' | 'lg'
  touchOptimized?: boolean
  fullWidth?: boolean
  icon?: ReactNode
  children?: ReactNode
}

export const POSButton = forwardRef<HTMLButtonElement, POSButtonProps>(
  (
    {
      variant = 'primary',
      size = 'md',
      touchOptimized = false,
      fullWidth = false,
      icon,
      children,
      className,
      disabled,
      ...props
    },
    ref
  ) => {
    // Variant styles
    const variantClasses = {
      primary: cn(colors.primary[600], `hover:${colors.primary[700]}`, textColors.inverse),
      secondary: cn(colors.neutral[200], `hover:${colors.neutral[300]}`, textColors.primary),
      success: cn(colors.success[600], `hover:${colors.success[700]}`, textColors.inverse),
      danger: cn(colors.error[600], `hover:${colors.error[700]}`, textColors.inverse),
    }

    // Size styles (different for icon-only vs with text)
    const isIconOnly = icon && !children
    const sizeClasses = isIconOnly
      ? {
          sm: 'p-1.5',
          md: 'p-2',
          lg: 'p-3',
        }
      : {
          sm: 'px-3 py-1.5 text-sm',
          md: 'px-4 py-2 text-base',
          lg: 'px-6 py-3 text-lg',
        }

    return (
      <button
        ref={ref}
        type="button"
        disabled={disabled}
        className={cn(
          // Base styles
          'inline-flex items-center justify-center gap-2',
          'font-medium rounded-lg',
          'transition-colors duration-150',
          focusRing.default,
          focusRing.primary,
          'active:scale-95 transform',

          // Variant
          variantClasses[variant],

          // Size
          sizeClasses[size],

          // Touch optimization
          touchOptimized && 'min-h-[48px] min-w-[48px]',

          // Width
          fullWidth && 'w-full',

          // Disabled state
          disabled && 'opacity-50 cursor-not-allowed hover:bg-current',

          // Custom classes
          className
        )}
        {...props}
      >
        {icon && <span className="inline-flex items-center">{icon}</span>}
        {children && <span>{children}</span>}
      </button>
    )
  }
)

POSButton.displayName = 'POSButton'
