import { useState } from 'react'
import { ShoppingBag } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { Button, Select } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'
import { useAggregateChannelOrders } from '../hooks/useAggregateChannelOrders'
import type { AggregateChannelOrderRow, ChannelOrderStatus } from '../types'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const PER_PAGE = 25

const ORDER_STATUSES: readonly ChannelOrderStatus[] = ['pending', 'processed', 'failed', 'ignored'] as const

const thClass = cn('px-6 py-3 text-start text-xs font-medium uppercase', textColors.tertiary)
const tdClass = cn('px-6 py-4 text-sm', textColors.secondary)

const orderBadgeClassByState: Record<ChannelOrderStatus, string> = {
  pending: `${tokens.badge.base} ${tokens.badge.yellow}`,
  processed: `${tokens.badge.base} ${tokens.badge.green}`,
  failed: `${tokens.badge.base} ${tokens.badge.red}`,
  ignored: `${tokens.badge.base} ${tokens.badge.gray}`,
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isChannelOrderStatus(value: string): value is ChannelOrderStatus {
  return (ORDER_STATUSES as readonly string[]).includes(value)
}

/**
 * Best-effort scalar lookup inside the adapter-specific (untyped)
 * external order payload. Returns the first non-empty string/number
 * found under the given keys, or null.
 */
function payloadText(payload: Record<string, unknown> | null | undefined, keys: readonly string[]): string | null {
  if (!payload) return null
  for (const key of keys) {
    const value = payload[key]
    if (typeof value === 'string' && value !== '') return value
    if (typeof value === 'number') return String(value)
  }
  return null
}

function customerLabel(row: AggregateChannelOrderRow): string | null {
  const direct = payloadText(row.payload, ['customer_name'])
  if (direct) return direct
  const customer = row.payload?.['customer']
  if (isRecord(customer)) {
    return payloadText(customer, ['name', 'full_name', 'email'])
  }
  return null
}

function totalLabel(row: AggregateChannelOrderRow): string | null {
  // Displayed verbatim (string) — never parsed into a float.
  return payloadText(row.payload, ['total', 'grand_total', 'total_amount'])
}

export function EcommerceOrdersPage() {
  const { t, i18n } = useTranslation(['channels', 'common'])
  const [statusFilter, setStatusFilter] = useState<'' | ChannelOrderStatus>('')
  const [page, setPage] = useState(1)

  const { data, isLoading, error } = useAggregateChannelOrders({
    status: statusFilter === '' ? undefined : statusFilter,
    page,
    per_page: PER_PAGE,
  })

  const orders = data?.data ?? []
  const meta = data?.meta

  const formatReceivedAt = (value: string): string => new Date(value).toLocaleString(i18n.language)

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('channels:aggregateOrders.title')}
        subtitle={t('channels:aggregateOrders.subtitle')}
        actions={
          <Select
            aria-label={t('channels:aggregateOrders.filterStatus')}
            value={statusFilter}
            onChange={(event) => {
              const { value } = event.target
              setStatusFilter(isChannelOrderStatus(value) ? value : '')
              setPage(1)
            }}
          >
            <option value="">{t('channels:aggregateOrders.allStatuses')}</option>
            {ORDER_STATUSES.map((status) => (
              <option key={status} value={status}>
                {t(`channels:orderStatus.${status}`)}
              </option>
            ))}
          </Select>
        }
      />

      {isLoading ? (
        <div className={tokens.card.base}>{t('common:status.loading')}</div>
      ) : error ? (
        <div className={tokens.card.base}>{t('channels:aggregateOrders.loadError')}</div>
      ) : orders.length === 0 ? (
        <div className={tokens.card.base}>
          <ShoppingBag className={cn('mb-3 h-6 w-6', textColors.tertiary)} />
          {t('channels:aggregateOrders.empty')}
        </div>
      ) : (
        <div className={cn('overflow-hidden rounded-lg border bg-white', borderColors.light)}>
          <DataTable className={cn('min-w-full divide-y', borderColors.divideDefault)}>
            <thead>
              <tr>
                <th className={thClass}>{t('channels:orders.externalId')}</th>
                <th className={thClass}>{t('channels:aggregateOrders.channel')}</th>
                <th className={thClass}>{t('channels:aggregateOrders.customer')}</th>
                <th className={thClass}>{t('channels:aggregateOrders.total')}</th>
                <th className={thClass}>{t('common:fields.status')}</th>
                <th className={thClass}>{t('channels:orders.receivedAt')}</th>
              </tr>
            </thead>
            <tbody>
              {orders.map((order) => (
                <tr key={order.id}>
                  <td className={tdClass}>{order.external_order_id}</td>
                  <td className={tdClass}>{order.channel.name}</td>
                  <td className={tdClass}>{customerLabel(order) ?? '—'}</td>
                  <td className={tdClass}>{totalLabel(order) ?? '—'}</td>
                  <td className={tdClass}>
                    <span className={orderBadgeClassByState[order.status]}>
                      {t(`channels:orderStatus.${order.status}`)}
                    </span>
                  </td>
                  <td className={tdClass}>{formatReceivedAt(order.received_at)}</td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className={cn('text-sm', textColors.tertiary)}>
            {t('channels:aggregateOrders.pageOf', {
              current: meta.current_page,
              last: meta.last_page,
              total: meta.total,
            })}
          </p>
          <div className="flex gap-2">
            <Button
              type="button"
              variant="secondary"
              disabled={page <= 1}
              onClick={() => {
                setPage((current) => Math.max(1, current - 1))
              }}
            >
              {t('common:previous')}
            </Button>
            <Button
              type="button"
              variant="secondary"
              disabled={page >= meta.last_page}
              onClick={() => {
                setPage((current) => Math.min(meta.last_page, current + 1))
              }}
            >
              {t('common:next')}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
