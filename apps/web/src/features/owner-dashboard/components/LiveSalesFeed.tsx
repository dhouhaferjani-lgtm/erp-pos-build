import { useEffect, useMemo, useRef, useState } from 'react'
import { Activity, Clock } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { borderColors, colors, spacing, textColors, transitions } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/decimal'
import { useLiveSales } from '../hooks/useOwnerReports'
import type { LiveSaleReceipt } from '../api/ownerReportsApi'

interface LiveSalesFeedProps {
  canFetch?: boolean
}

export function LiveSalesFeed({ canFetch = true }: LiveSalesFeedProps) {
  const { t } = useTranslation(['reports'])
  const { data, isLoading, isError } = useLiveSales(canFetch)
  const receipts = useMemo(() => (data?.recent_receipts ?? []).slice(0, 8), [data?.recent_receipts])
  const updatedAgo = getUpdatedAgo(data?.generated_at)
  const [highlightedIds, setHighlightedIds] = useState<Set<string>>(() => new Set())
  const previousIdsRef = useRef<Set<string> | null>(null)

  useEffect(() => {
    const currentIds = new Set(receipts.map((receipt) => receipt.id))
    const previousIds = previousIdsRef.current

    if (previousIds !== null) {
      setHighlightedIds(new Set(receipts.filter((receipt) => !previousIds.has(receipt.id)).map((receipt) => receipt.id)))
    }

    previousIdsRef.current = currentIds
  }, [receipts])

  return (
    <section className={`rounded-lg border ${borderColors.light} ${colors.white} ${spacing.md}`}>
      <div className="mb-4 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <span className={`relative flex h-2.5 w-2.5 ${colors.success[600]}`}>
            <span className={`absolute inline-flex h-full w-full animate-ping rounded-full ${colors.success[600]} opacity-75`} />
            <span className={`relative inline-flex h-2.5 w-2.5 rounded-full ${colors.success[600]}`} />
          </span>
          <h3 className={`text-lg font-medium ${textColors.primary}`}>{t('reports:ownerDashboard.liveSales.title')}</h3>
        </div>
        <p className={`flex items-center gap-1 text-xs ${textColors.tertiary}`}>
          <Activity className="h-3.5 w-3.5" />
          <span>{t('reports:ownerDashboard.liveSales.live')}</span>
          <span>
            {updatedAgo === null
              ? t('reports:ownerDashboard.liveSales.updatedJustNow')
              : t('reports:ownerDashboard.liveSales.updatedSecondsAgo', { seconds: updatedAgo })}
          </span>
        </p>
      </div>

      {isLoading ? (
        <p className={`py-8 text-center ${textColors.tertiary}`}>{t('reports:ownerDashboard.loading')}</p>
      ) : isError ? (
        <p className={`py-8 text-center ${textColors.error}`}>{t('reports:ownerDashboard.error')}</p>
      ) : receipts.length === 0 ? (
        <p className={`py-8 text-center ${textColors.tertiary}`}>{t('reports:ownerDashboard.noData')}</p>
      ) : (
        <ol className={`divide-y ${borderColors.divideLight}`}>
          {receipts.map((receipt) => (
            <LiveSalesFeedRow
              key={receipt.id}
              receipt={receipt}
              isHighlighted={highlightedIds.has(receipt.id)}
            />
          ))}
        </ol>
      )}
    </section>
  )
}

function LiveSalesFeedRow({ receipt, isHighlighted }: { receipt: LiveSaleReceipt; isHighlighted: boolean }) {
  const { t } = useTranslation(['reports'])

  return (
    <li
      data-testid={`live-sale-${receipt.id}`}
      data-highlighted={String(isHighlighted)}
      className={`flex items-center justify-between gap-3 py-3 ${transitions.all} ${transitions.slow} ${isHighlighted ? colors.success[50] : colors.transparent}`}
    >
      <div className="min-w-0">
        <p className={`truncate text-sm font-medium ${textColors.primary}`}>{receipt.location_name}</p>
        <p className={`mt-1 flex items-center gap-1 text-xs ${textColors.tertiary}`}>
          <Clock className="h-3.5 w-3.5" />
          <span>{formatReceiptTime(receipt.posted_at)}</span>
          <span>{receipt.receipt_number}</span>
          <span>{t('reports:ownerDashboard.liveSales.items', { count: receipt.items_count })}</span>
        </p>
      </div>
      <p className={`shrink-0 text-sm font-semibold ${textColors.primary}`}>
        {formatCurrency(receipt.total, true, receipt.currency)}
      </p>
    </li>
  )
}

function formatReceiptTime(value: string): string {
  return new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(value))
}

function getUpdatedAgo(value: string | undefined): number | null {
  if (value === undefined) {
    return null
  }

  const seconds = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 1000))
  if (seconds < 5) {
    return null
  }

  return seconds
}
