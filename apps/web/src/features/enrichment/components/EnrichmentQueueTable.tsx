import { useTranslation } from 'react-i18next'
import { textColors, colors, borderColors, tokens } from '@/lib/designTokens'
import { Checkbox } from '@/components/atoms'
import { QualityBadge } from './QualityBadge'
import type { EnrichmentResult } from '../types/enrichment'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

interface EnrichmentQueueTableProps {
  results: EnrichmentResult[]
  selectedIds: Set<string>
  onToggleSelect: (id: string) => void
  onToggleSelectAll: () => void
  onRowClick: (id: string) => void
}

interface RelativeTimeLabel {
  key: string
  options?: { count: number }
}

function formatRelativeTime(dateString: string): RelativeTimeLabel {
  const now = Date.now()
  const then = new Date(dateString).getTime()
  const diffMs = now - then
  const diffMinutes = Math.floor(diffMs / 60_000)
  const diffHours = Math.floor(diffMs / 3_600_000)
  const diffDays = Math.floor(diffMs / 86_400_000)

  if (diffMinutes < 1) return { key: 'queue.relative.justNow' }
  if (diffMinutes < 60) return { key: 'queue.relative.minutesAgo', options: { count: diffMinutes } }
  if (diffHours < 24) return { key: 'queue.relative.hoursAgo', options: { count: diffHours } }
  return { key: 'queue.relative.daysAgo', options: { count: diffDays } }
}

export function EnrichmentQueueTable({
  results,
  selectedIds,
  onToggleSelect,
  onToggleSelectAll,
  onRowClick,
}: EnrichmentQueueTableProps) {
  const { t } = useTranslation('enrichment')

  const allSelected = results.length > 0 && results.every((r) => selectedIds.has(r.id))

  return (
    <div className={`overflow-x-auto rounded-lg border ${borderColors.light}`}>
      <DataTable className={`min-w-full divide-y ${borderColors.divideDefault}`}>
        <thead className={colors.neutral[50]}>
          <tr>
            <th className="w-10 px-3 py-3">
              <Checkbox
                checked={allSelected}
                onChange={onToggleSelectAll}
              />
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.secondary} uppercase tracking-wider`}>
              {t('queue.columns.productName')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.secondary} uppercase tracking-wider`}>
              {t('queue.columns.barcode')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.secondary} uppercase tracking-wider`}>
              {t('queue.columns.quality')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.secondary} uppercase tracking-wider`}>
              {t('queue.columns.assignedBarcode')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.secondary} uppercase tracking-wider`}>
              {t('queue.columns.submitted')}
            </th>
          </tr>
        </thead>
        <tbody className={`${colors.white} divide-y ${borderColors.divideDefault}`}>
          {results.map((result) => {
            const relativeTime = formatRelativeTime(result.created_at)

            return (
              <tr
                key={result.id}
                onClick={() => {
                  onRowClick(result.id)
                }}
                className={`cursor-pointer ${colors.hover.gray50} transition-colors`}
              >
              <td
                className="px-3 py-3"
                onClick={(e) => {
                  e.stopPropagation()
                }}
              >
                <Checkbox
                  checked={selectedIds.has(result.id)}
                  onChange={() => {
                    onToggleSelect(result.id)
                  }}
                />
              </td>
              <td className={`px-4 py-3 text-sm ${textColors.primary} font-medium`}>
                <div className="flex flex-col gap-1">
                  <span>{result.product_name}</span>
                  {result.origin === 'curated_update' && (
                    <span className={`${tokens.badge.base} ${tokens.badge.blue} w-fit`}>
                      {t('queue.curatedUpdateBadge')}
                    </span>
                  )}
                </div>
              </td>
              <td className={`px-4 py-3 text-sm font-mono ${textColors.tertiary}`}>
                {result.product_barcode ?? '\u2014'}
              </td>
              <td className="px-4 py-3">
                <QualityBadge quality={result.enrichment_quality} />
              </td>
              <td className={`px-4 py-3 text-sm font-mono ${textColors.tertiary}`}>
                {result.assigned_barcode ?? '\u2014'}
              </td>
                <td className={`px-4 py-3 text-sm ${textColors.tertiary}`}>
                  {relativeTime.options
                    ? t(relativeTime.key, relativeTime.options)
                    : t(relativeTime.key)}
                </td>
              </tr>
            )
          })}
        </tbody>
      </DataTable>
    </div>
  )
}
