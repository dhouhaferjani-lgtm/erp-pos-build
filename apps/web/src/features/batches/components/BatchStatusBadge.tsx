import { useTranslation } from 'react-i18next'
import { AlertCircle, Clock, AlertTriangle, XCircle, CheckCircle } from 'lucide-react'
import type { ExpiryStatus } from '../types'

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
function getStatusConfig(status: ExpiryStatus) {
  switch (status) {
    case 'OK':
      return {
        bgColor: 'bg-green-100',
        textColor: 'text-green-800',
        icon: CheckCircle,
      }
    case 'APPROACHING':
      return {
        bgColor: 'bg-yellow-100',
        textColor: 'text-yellow-800',
        icon: Clock,
      }
    case 'WARNING':
      return {
        bgColor: 'bg-orange-100',
        textColor: 'text-orange-800',
        icon: AlertTriangle,
      }
    case 'CRITICAL':
      return {
        bgColor: 'bg-red-100',
        textColor: 'text-red-800',
        icon: AlertCircle,
      }
    case 'EXPIRED':
      return {
        bgColor: 'bg-gray-100',
        textColor: 'text-gray-800',
        icon: XCircle,
      }
    default:
      return {
        bgColor: 'bg-gray-100',
        textColor: 'text-gray-800',
        icon: AlertCircle,
      }
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
