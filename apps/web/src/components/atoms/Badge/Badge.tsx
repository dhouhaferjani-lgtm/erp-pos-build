import { type ReactNode } from 'react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export type BadgeVariant = 'default' | 'success' | 'warning' | 'danger' | 'info'

export interface BadgeProps {
  variant?: BadgeVariant
  children: ReactNode
  className?: string
}

const variantStyles: Record<BadgeVariant, string> = {
  default: `${colorTokens.surface.muted} ${colorTokens.text.strong}`,
  success: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
  warning: `${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`,
  danger: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
  info: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
}

export function Badge({ variant = 'default', children, className = '' }: BadgeProps) {
  return (
    <span
      className={`
        inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
        ${variantStyles[variant]}
        ${className}
      `}
    >
      {children}
    </span>
  )
}
