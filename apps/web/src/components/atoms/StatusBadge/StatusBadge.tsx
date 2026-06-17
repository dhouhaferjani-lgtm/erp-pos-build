import { type ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { tokens } from '@/lib/designTokens'

/**
 * Semantic status tone set.
 *
 * This is the ONE sanctioned palette for status pills across the app. It exists
 * to replace the per-feature `Record<status, 'bg-x-100 text-x-800'>` color maps
 * (CountingStatusBadge, BatchStatusBadge, StatusPill, EmploymentStatusBadge,
 * document/fraud status helpers, ...) — almost all of which reached for
 * off-theme palettes. Callers map their domain status to a tone (often via
 * `statusTone`) and pass the already-translated label as children.
 */
export type StatusTone =
  | 'neutral'
  | 'info'
  | 'success'
  | 'warning'
  | 'danger'
  | 'pending'

export interface StatusBadgeProps {
  /** Semantic tone driving the pill colors. Defaults to `neutral`. */
  tone?: StatusTone
  /** The label — caller passes translated text. */
  children: ReactNode
  className?: string
}

/**
 * Tone → design-token classes. Only theme-bridged/semantic palettes are used;
 * no off-theme colors. Each tone references an EXISTING token from
 * `designTokens.ts` so no new color literals are introduced.
 */
const toneStyles: Record<StatusTone, string> = {
  neutral: tokens.badge.gray,
  info: tokens.alert.info,
  success: tokens.alert.success,
  warning: tokens.alert.warning,
  danger: tokens.alert.error,
  pending: tokens.badge.gray,
}

export function StatusBadge({
  tone = 'neutral',
  children,
  className,
}: StatusBadgeProps) {
  return (
    <span className={cn(tokens.badge.base, toneStyles[tone], className)}>
      {children}
    </span>
  )
}
