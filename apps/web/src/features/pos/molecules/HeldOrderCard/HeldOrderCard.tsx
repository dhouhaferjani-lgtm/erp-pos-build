import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Clock, ShoppingCart, Trash2, RotateCcw, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { HeldOrderData } from '../../api/heldOrderApi'

export interface HeldOrderCardProps {
  order: HeldOrderData
  onRecall: (id: string) => void
  onDiscard: (id: string) => void
  isRecalling: boolean
  isDiscarding: boolean
}

/**
 * Formats a duration in milliseconds to a human-readable relative time string.
 */
function formatRelativeTime(ms: number): string {
  const seconds = Math.floor(ms / 1000)
  const minutes = Math.floor(seconds / 60)
  const hours = Math.floor(minutes / 60)

  if (hours > 0) {
    return `${String(hours)}h ${String(minutes % 60)}m`
  }
  if (minutes > 0) {
    return `${String(minutes)}m`
  }
  return `${String(seconds)}s`
}

/**
 * HeldOrderCard - Displays a single held (parked) order.
 *
 * Shows the order label, line count, total, time held, and expiry countdown.
 * Provides recall and discard actions with confirmation for discard.
 */
export function HeldOrderCard({
  order,
  onRecall,
  onDiscard,
  isRecalling,
  isDiscarding,
}: HeldOrderCardProps) {
  const { t } = useTranslation(['pos'])
  const [showConfirmDiscard, setShowConfirmDiscard] = useState(false)

  const timeHeld = useMemo(() => {
    const heldAt = new Date(order.held_at).getTime()
    const now = Date.now()
    return formatRelativeTime(now - heldAt)
  }, [order.held_at])

  const expiryInfo = useMemo(() => {
    if (!order.expires_at) {
      return null
    }
    const expiresAt = new Date(order.expires_at).getTime()
    const now = Date.now()
    const remaining = expiresAt - now

    if (remaining <= 0) {
      return { expired: true, text: t('pos:heldOrders.expired') }
    }

    return {
      expired: false,
      text: t('pos:heldOrders.expiresIn', { time: formatRelativeTime(remaining) }),
      isNearExpiry: remaining < 30 * 60 * 1000, // Less than 30 minutes
    }
  }, [order.expires_at, t])

  const handleDiscard = () => {
    if (showConfirmDiscard) {
      onDiscard(order.id)
      setShowConfirmDiscard(false)
    } else {
      setShowConfirmDiscard(true)
    }
  }

  const displayLabel = order.label ?? t('pos:heldOrders.unnamed')

  return (
    <div
      className={cn(
        'rounded-lg border bg-white p-4 shadow-sm transition-all',
        'hover:shadow-md',
        expiryInfo?.isNearExpiry && 'border-amber-300 bg-amber-50/50',
        expiryInfo?.expired && 'border-red-300 bg-red-50/50 opacity-75',
      )}
    >
      {/* Header */}
      <div className="mb-3 flex items-start justify-between">
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-sm font-semibold text-gray-900">
            {displayLabel}
          </h3>
          <div className="mt-1 flex items-center gap-3 text-xs text-gray-500">
            <span className="flex items-center gap-1">
              <ShoppingCart className="h-3 w-3" />
              {t('pos:heldOrders.lineCount', { count: order.line_count })}
            </span>
            {order.total !== null && (
              <span className="font-medium text-gray-700">
                {order.total}
              </span>
            )}
          </div>
        </div>
      </div>

      {/* Time info */}
      <div className="mb-3 flex items-center gap-3 text-xs text-gray-500">
        <span className="flex items-center gap-1">
          <Clock className="h-3 w-3" />
          {t('pos:heldOrders.heldAgo', { time: timeHeld })}
        </span>
        {expiryInfo && (
          <span
            className={cn(
              'flex items-center gap-1',
              expiryInfo.expired && 'font-medium text-red-600',
              expiryInfo.isNearExpiry && !expiryInfo.expired && 'font-medium text-amber-600',
            )}
          >
            {(expiryInfo.expired || expiryInfo.isNearExpiry) && (
              <AlertTriangle className="h-3 w-3" />
            )}
            {expiryInfo.text}
          </span>
        )}
      </div>

      {/* Actions */}
      {showConfirmDiscard ? (
        <div className="flex items-center gap-2">
          <span className="flex-1 text-xs text-red-600">
            {t('pos:heldOrders.confirmDiscard')}
          </span>
          <button
            type="button"
            onClick={() => { setShowConfirmDiscard(false); }}
            className="rounded px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100"
          >
            {t('common:cancel')}
          </button>
          <button
            type="button"
            onClick={handleDiscard}
            disabled={isDiscarding}
            className="rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50"
          >
            {t('pos:heldOrders.discardOrder')}
          </button>
        </div>
      ) : (
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => { onRecall(order.id); }}
            disabled={isRecalling || expiryInfo?.expired === true}
            className={cn(
              'flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-2 text-xs font-medium',
              'bg-blue-600 text-white hover:bg-blue-700',
              'disabled:cursor-not-allowed disabled:opacity-50',
            )}
          >
            <RotateCcw className="h-3.5 w-3.5" />
            {t('pos:heldOrders.recallOrder')}
          </button>
          <button
            type="button"
            onClick={handleDiscard}
            disabled={isDiscarding}
            className={cn(
              'flex items-center justify-center gap-1.5 rounded-md px-3 py-2 text-xs font-medium',
              'border border-gray-300 text-gray-700 hover:bg-gray-50',
              'disabled:cursor-not-allowed disabled:opacity-50',
            )}
          >
            <Trash2 className="h-3.5 w-3.5" />
            {t('pos:heldOrders.discardOrder')}
          </button>
        </div>
      )}
    </div>
  )
}
