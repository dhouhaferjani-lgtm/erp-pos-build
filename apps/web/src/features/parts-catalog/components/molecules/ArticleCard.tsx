import { useTranslation } from 'react-i18next'
import { ChevronRight } from 'lucide-react'
import { cn } from '@/lib/utils'
import { InventoryBadge } from '../atoms/InventoryBadge'
import { FitmentConfidenceBadge } from '../atoms/FitmentConfidenceBadge'
import type { EnrichedArticle } from '../../types/catalog'

interface ArticleCardProps {
  article: EnrichedArticle
  onSelect: (article: EnrichedArticle) => void
  viewMode?: 'grid' | 'list'
  className?: string
}

export function ArticleCard({ article, onSelect, viewMode = 'list', className }: ArticleCardProps) {
  const { t } = useTranslation(['parts-catalog'])

  const vehicles = article.compatible_vehicles ?? []
  const maxConfidence = vehicles.length > 0
    ? Math.max(...vehicles.map((v) => v.fitment_confidence))
    : null

  if (viewMode === 'grid') {
    return (
      <button
        type="button"
        onClick={() => { onSelect(article) }}
        className={cn(
          'group w-full text-start rounded-lg border border-gray-200 bg-white p-4',
          'transition-all duration-200 hover:border-blue-300 hover:shadow-md',
          'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1',
          'flex flex-col h-full',
          className
        )}
      >
        {/* Supplier badge row */}
        <div className="flex items-center justify-between gap-2 mb-3">
          <span className="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-600/20 uppercase tracking-wide">
            {article.supplier?.brand ?? 'Unknown'}
          </span>
          {article.local_inventory && <InventoryBadge inventory={article.local_inventory} showQuantity />}
        </div>

        {/* Article number */}
        <p className="text-base font-mono font-bold text-gray-900 mb-1">
          {article.article_number}
        </p>

        {/* Description from first criteria or supplier */}
        {(article.criteria ?? []).length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1.5 flex-1">
            {(article.criteria ?? []).slice(0, 3).map((c) => (
              <span
                key={c.criteria_id}
                className="inline-flex items-center rounded bg-gray-50 px-1.5 py-0.5 text-xs text-gray-600"
              >
                <span className="font-medium text-gray-500">{c.label}:</span>
                <span className="ms-1">{c.value}{c.unit ? ` ${c.unit}` : ''}</span>
              </span>
            ))}
          </div>
        )}

        {/* Bottom row: fitment + stock */}
        <div className="mt-auto pt-3 flex items-center justify-between gap-2">
          {maxConfidence !== null && (
            <FitmentConfidenceBadge confidence={maxConfidence} />
          )}
        </div>
      </button>
    )
  }

  // List view (default)
  return (
    <button
      type="button"
      onClick={() => { onSelect(article) }}
      className={cn(
        'group w-full text-start rounded-lg border border-gray-200 bg-white p-4',
        'transition-all duration-200 hover:border-gray-300 hover:shadow-md',
        'focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1',
        className
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          {/* Supplier brand + badges */}
          <div className="flex items-center gap-2 mb-1.5 flex-wrap">
            <span className="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-600/20 uppercase tracking-wide">
              {article.supplier?.brand ?? 'Unknown'}
            </span>
            {article.local_inventory && <InventoryBadge inventory={article.local_inventory} showQuantity />}
            {maxConfidence !== null && (
              <FitmentConfidenceBadge confidence={maxConfidence} />
            )}
          </div>

          {/* Article number */}
          <p className="text-sm font-semibold text-gray-900 truncate">
            <span className="text-gray-400 font-normal">{t('parts-catalog:article.articleNumber')}</span>{' '}
            <span className="font-mono">{article.article_number}</span>
          </p>

          {/* Key criteria preview (first 3) */}
          {(article.criteria ?? []).length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1.5">
              {(article.criteria ?? []).slice(0, 3).map((c) => (
                <span
                  key={c.criteria_id}
                  className="inline-flex items-center rounded bg-gray-50 px-1.5 py-0.5 text-xs text-gray-600"
                >
                  <span className="font-medium text-gray-500">{c.label}:</span>
                  <span className="ms-1">{c.value}{c.unit ? ` ${c.unit}` : ''}</span>
                </span>
              ))}
              {(article.criteria ?? []).length > 3 && (
                <span className="text-xs text-gray-400">
                  +{(article.criteria ?? []).length - 3}
                </span>
              )}
            </div>
          )}

          {/* Cross-reference preview */}
          {(article.cross_references ?? []).length > 0 && (
            <p className="mt-1.5 text-xs text-gray-400 truncate">
              {(article.cross_references ?? [])
                .slice(0, 2)
                .map((r) => r.reference_number)
                .join(', ')}
              {(article.cross_references ?? []).length > 2 && ` +${String((article.cross_references ?? []).length - 2)}`}
            </p>
          )}
        </div>

        {/* Arrow */}
        <ChevronRight className="h-5 w-5 text-gray-300 group-hover:text-gray-500 transition-colors shrink-0 mt-1" />
      </div>
    </button>
  )
}
