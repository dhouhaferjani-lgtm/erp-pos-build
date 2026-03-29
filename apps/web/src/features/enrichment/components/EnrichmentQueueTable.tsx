import { useTranslation } from 'react-i18next'
import { tokens, textColors, colors, borderColors } from '@/lib/designTokens'
import { QualityBadge } from './QualityBadge'
import type { EnrichmentResult } from '../types/enrichment'

interface EnrichmentQueueTableProps {
  results: EnrichmentResult[]
  selectedIds: Set<string>
  onToggleSelect: (id: string) => void
  onToggleSelectAll: () => void
  onRowClick: (id: string) => void
}

function formatRelativeTime(dateString: string): string {
  const now = Date.now()
  const then = new Date(dateString).getTime()
  const diffMs = now - then
  const diffMinutes = Math.floor(diffMs / 60_000)
  const diffHours = Math.floor(diffMs / 3_600_000)
  const diffDays = Math.floor(diffMs / 86_400_000)

  if (diffMinutes < 1) return 'just now'
  if (diffMinutes < 60) return `${diffMinutes}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  return `${diffDays}d ago`
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
      <table className="min-w-full divide-y divide-gray-200">
        <thead className={colors.neutral[50]}>
          <tr>
            <th className="w-10 px-3 py-3">
              <input
                type="checkbox"
                checked={allSelected}
                onChange={onToggleSelectAll}
                className={tokens.checkbox.base}
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
        <tbody className={`${colors.white} divide-y divide-gray-200`}>
          {results.map((result) => (
            <tr
              key={result.id}
              onClick={() => onRowClick(result.id)}
              className="cursor-pointer hover:bg-gray-50 transition-colors"
            >
              <td className="px-3 py-3" onClick={(e) => e.stopPropagation()}>
                <input
                  type="checkbox"
                  checked={selectedIds.has(result.id)}
                  onChange={() => onToggleSelect(result.id)}
                  className={tokens.checkbox.base}
                />
              </td>
              <td className={`px-4 py-3 text-sm ${textColors.primary} font-medium`}>
                {result.product_name}
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
                {formatRelativeTime(result.created_at)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
