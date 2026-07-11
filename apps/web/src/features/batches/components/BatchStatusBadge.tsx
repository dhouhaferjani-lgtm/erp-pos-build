import { useTranslation } from 'react-i18next'
import { AlertCircle, Clock, AlertTriangle, XCircle, CheckCircle } from 'lucide-react'
import type { ExpiryStatus } from '../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface BatchStatusBadgeProps {
  status: ExpiryStatus
  daysUntilExpiry?: number
  showIcon?: boolean
  showDays?: boolean
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

/**
 * Badge component to display batch expiry status with color coding
 *
 * Status colors:
 * - OK: Green (>90 days)
 * - APPROACHING: Yellow (30-90 days)
 * - WARNING: Orange (7-30 days)
 * - CRITICAL: Red (<7 days)
 * - EXPIRED: Gray (past expiry)
 */
export function BatchStatusBadge({
  status,
  daysUntilExpiry,
  showIcon = true,
  showDays = true,
  size = 'md',
  className = '',
}: BatchStatusBadgeProps) {
  const { t } = useTranslation(['batches'])

  const config = getStatusConfig(status)
  const sizeClasses = getSizeClasses(size)
  const iconSize = getIconSize(size)

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full ${config.bgColor} ${config.textColor} ${sizeClasses} ${className}`}
      title={t(`batches:expiryStatus.${status.toLowerCase()}`)}
    >
      {showIcon && config.icon && (
        <config.icon className={iconSize} />
      )}
      <span className="font-medium">
        {t(`batches:expiryStatus.${status.toLowerCase()}`)}
      </span>
      {showDays && daysUntilExpiry !== undefined && status !== 'EXPIRED' && (
        <span className="font-normal opacity-90">
          ({daysUntilExpiry} {t('batches:daysRemaining', 'days')})
        </span>
      )}
    </span>
  )
}

/**
 * Get status configuration (colors, icon)
 */
const expiryBadgeMeta: Record<ExpiryStatus, {
  bgColor: string
  textColor: string
  icon: typeof AlertCircle
}> = {
  OK: {
    bgColor: `${colorTokens.intent.success.bgSoft}`,
    textColor: `${colorTokens.intent.success.textStronger}`,
    icon: CheckCircle,
  },
  APPROACHING: {
    bgColor: `${colorTokens.intent.warning.bgSoft}`,
    textColor: `${colorTokens.intent.warning.textStronger}`,
    icon: Clock,
  },
  WARNING: {
    bgColor: `${colorTokens.intent.notice.bgSoft}`,
    textColor: `${colorTokens.intent.notice.textStronger}`,
    icon: AlertTriangle,
  },
  CRITICAL: {
    bgColor: `${colorTokens.intent.danger.bgSoft}`,
    textColor: `${colorTokens.intent.danger.textStronger}`,
    icon: AlertCircle,
  },
  EXPIRED: {
    bgColor: `${colorTokens.surface.muted}`,
    textColor: `${colorTokens.text.strong}`,
    icon: XCircle,
  },
}

function getStatusConfig(status: ExpiryStatus) {
  return expiryBadgeMeta[status] ?? {
    bgColor: `${colorTokens.surface.muted}`,
    textColor: `${colorTokens.text.strong}`,
    icon: AlertCircle,
  }
}

/**
 * Get size classes for badge
 */
function getSizeClasses(size: 'sm' | 'md' | 'lg'): string {
  switch (size) {
    case 'sm':
      return 'px-2 py-0.5 text-xs'
    case 'md':
      return 'px-3 py-1 text-sm'
    case 'lg':
      return 'px-4 py-1.5 text-base'
  }
}

/**
 * Get icon size classes
 */
function getIconSize(size: 'sm' | 'md' | 'lg'): string {
  switch (size) {
    case 'sm':
      return 'h-3 w-3'
    case 'md':
      return 'h-4 w-4'
    case 'lg':
      return 'h-5 w-5'
  }
}
