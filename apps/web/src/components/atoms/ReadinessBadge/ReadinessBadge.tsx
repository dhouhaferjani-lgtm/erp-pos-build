import { type ReactNode } from 'react'
import { tokens } from '@/lib/designTokens'

export type ReadinessStatus = 'active' | 'ready' | 'locked' | 'available'

export interface ReadinessBadgeProps {
  status: ReadinessStatus
  children: ReactNode
  className?: string
}

const statusStyles: Record<ReadinessStatus, string> = {
  active: `${tokens.badge.green}`,
  ready: `${tokens.badge.blue}`,
  available: `${tokens.badge.yellow}`,
  locked: `${tokens.badge.gray}`,
}

export function ReadinessBadge({ status, children, className = '' }: ReadinessBadgeProps) {
  return (
    <span
      className={`
        ${tokens.badge.base}
        ${statusStyles[status]}
        ${className}
      `}
    >
      {children}
    </span>
  )
}
