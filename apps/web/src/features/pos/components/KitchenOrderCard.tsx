import { useTranslation } from 'react-i18next'
import { KitchenTimer } from '../atoms/KitchenTimer'
import { OrderStatusBadge } from '../molecules/OrderStatusBadge'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import { statusTone } from '@/components/atoms/StatusBadge/statusTone'
import { colors, textColors, borderColors } from '@/lib/designTokens'
import type { OrderData, OrderLineData } from '../api/orderApi'
import { Button } from '@/components/atoms'

interface KitchenOrderCardProps {
  order: OrderData
  onLineStatusChange: (orderId: string, lineId: string, newStatus: string) => void
  onBump: (orderId: string) => void
  isBumping: boolean
}

/**
 * Kitchen line states are intentionally color-coded for at-a-glance triage.
 * Map each to a semantic StatusBadge tone (preserves the visual coding without
 * raw color literals): sent/preparing → warning (in-flight), ready → success,
 * cancelled → danger.
 */
const LINE_STATUS_TONES: Record<string, StatusTone> = {
  sent: 'warning',
  preparing: 'warning',
  ready: 'success',
  cancelled: 'danger',
}

function getNextStatus(current: string): string | null {
  if (current === 'sent') return 'preparing'
  if (current === 'preparing') return 'ready'
  return null
}

/**
 * A single order card on the Kitchen Display, showing lines with tap-to-advance.
 */
export function KitchenOrderCard({
  order,
  onLineStatusChange,
  onBump,
  isBumping,
}: KitchenOrderCardProps) {
  const { t } = useTranslation('pos')

  const handleLineTap = (line: OrderLineData) => {
    const nextStatus = getNextStatus(line.status)
    if (nextStatus) {
      onLineStatusChange(order.id, line.id, nextStatus)
    }
  }

  const tableInfo = order.table as { table_number: string; label: string | null; floor_name: string | null } | undefined
  const hasPendingLines = order.lines.some(
    (l) => l.status === 'sent' || l.status === 'preparing'
  )

  return (
    <div className={`flex flex-col rounded-xl border ${borderColors.light} ${colors.white} shadow-sm`}>
      {/* Header */}
      <div className={`flex items-center justify-between border-b ${borderColors.light} px-4 py-3`}>
        <div className="flex items-center gap-3">
          <span className={`text-lg font-bold ${textColors.primary}`}>
            {order.order_number}
          </span>
          <OrderStatusBadge status={order.status} />
        </div>
        {order.sent_at && <KitchenTimer startTime={order.sent_at} />}
      </div>

      {/* Table + Mode info */}
      <div className={`flex flex-wrap items-center gap-2 px-4 py-2 text-xs ${textColors.tertiary}`}>
        {tableInfo && (
          <StatusBadge tone="info">
            {tableInfo.table_number}
            {tableInfo.label ? ` - ${tableInfo.label}` : ''}
          </StatusBadge>
        )}
        {order.consumption_mode && (
          <StatusBadge tone="neutral">
            {order.consumption_mode === 'SUR_PLACE'
              ? t('consumptionMode.dineIn')
              : t('consumptionMode.takeaway')}
          </StatusBadge>
        )}
        {order.customer_name && (
          <span>{order.customer_name}</span>
        )}
      </div>

      {/* Lines */}
      <div className="flex-1 space-y-1 px-4 py-2">
        {order.lines.map((line) => {
          const nextStatus = getNextStatus(line.status)
          const canTap = nextStatus !== null
          const lineTone = statusTone(line.status, LINE_STATUS_TONES)

          return (
            <Button
              key={line.id}
              type="button"
              disabled={!canTap}
              onClick={() => { handleLineTap(line) }}
              className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left transition-colors ${
                canTap ? `${colors.hover.gray50} active:${colors.neutral[100]}` : ''
              }`}
            >
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <span className={`font-medium ${textColors.primary}`}>
                    {line.quantity}x
                  </span>
                  <span className={`text-sm ${textColors.secondary}`}>
                    {line.product_name}
                  </span>
                </div>
                {line.special_instructions && (
                  <p className={`mt-0.5 text-xs italic ${textColors.tertiary}`}>
                    {line.special_instructions}
                  </p>
                )}
              </div>
              <StatusBadge
                tone={lineTone}
                className={line.status === 'cancelled' ? 'line-through' : ''}
              >
                {t(`kitchen.lineStatus.${line.status}`)}
              </StatusBadge>
            </Button>
          )
        })}
      </div>

      {/* Actions */}
      {hasPendingLines && (
        <div className={`border-t ${borderColors.light} px-4 py-3`}>
          <Button
            type="button"
            onClick={() => { onBump(order.id) }}
            disabled={isBumping}
            className={`w-full rounded-lg  ${colors.success[600]} px-4 py-2 text-sm font-semibold ${textColors.inverse} hover:${colors.success[700]} disabled:opacity-50`}
          >
            {t('kitchen.bump')}
          </Button>
        </div>
      )}
    </div>
  )
}
