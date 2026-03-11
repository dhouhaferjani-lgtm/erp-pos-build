import { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { Search, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useCriteriaSearch } from '../../hooks/useCriteriaSearch'
import { useCriteriaMetadata } from '../../hooks/useCriteriaMetadata'
import { ArticleCard } from '../molecules/ArticleCard'
import {
  TIRE_WIDTHS,
  TIRE_ASPECT_RATIOS,
  TIRE_RIM_DIAMETERS,
} from '../../types/catalog'
import type { CriteriaFilter, EnrichedArticle } from '../../types/catalog'

interface TireDimensionSearchProps {
  onArticleSelected: (article: EnrichedArticle) => void
  className?: string
}

export function TireDimensionSearch({ onArticleSelected, className }: TireDimensionSearchProps) {
  const { t } = useTranslation(['parts-catalog'])
  const [width, setWidth] = useState('')
  const [aspect, setAspect] = useState('')
  const [rim, setRim] = useState('')
  const [activeFilters, setActiveFilters] = useState<CriteriaFilter[]>([])

  const { data: criteriaMetadata } = useCriteriaMetadata()

  const resolveCriteriaId = useCallback(
    (label: string): string | null => {
      if (!criteriaMetadata) return null
      const criteria = criteriaMetadata.find(
        (c) => c.label.toLowerCase().includes(label.toLowerCase())
      )
      return criteria?.id ?? null
    },
    [criteriaMetadata]
  )

  const handleSearch = useCallback(
    (e: React.FormEvent) => {
      e.preventDefault()
      if (!width || !aspect || !rim) return

      const widthId = resolveCriteriaId('tire width')
        ?? resolveCriteriaId('width')
      const aspectId = resolveCriteriaId('aspect ratio')
        ?? resolveCriteriaId('aspect')
      const rimId = resolveCriteriaId('rim diameter')
        ?? resolveCriteriaId('diameter')

      if (!widthId || !aspectId || !rimId) return

      setActiveFilters([
        { criteria_id: widthId, value: width },
        { criteria_id: aspectId, value: aspect },
        { criteria_id: rimId, value: rim },
      ])
    },
    [width, aspect, rim, resolveCriteriaId]
  )

  const {
    data: searchResults,
    isLoading,
    hasNextPage,
    fetchNextPage,
    isFetchingNextPage,
  } = useCriteriaSearch(activeFilters)

  const allArticles = searchResults?.pages.flatMap((p) => p.data) ?? []

  return (
    <div className={cn('flex flex-col', className)}>
      <form onSubmit={handleSearch} className="space-y-4">
        {/* Tire size visual: WIDTH / ASPECT R RIM */}
        <div className="flex items-end gap-2">
          {/* Width */}
          <div className="flex-1">
            <label htmlFor="tire-width" className="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">
              {t('parts-catalog:tireSize.width')}
            </label>
            <select
              id="tire-width"
              value={width}
              onChange={(e) => { setWidth(e.target.value) }}
              className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors"
            >
              <option value="">{t('parts-catalog:tireSize.selectWidth')}</option>
              {TIRE_WIDTHS.map((w) => (
                <option key={w} value={w}>{w}</option>
              ))}
            </select>
          </div>

          <span className="pb-3 text-lg text-gray-300 font-light">/</span>

          {/* Aspect Ratio */}
          <div className="flex-1">
            <label htmlFor="tire-aspect" className="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">
              {t('parts-catalog:tireSize.aspectRatio')}
            </label>
            <select
              id="tire-aspect"
              value={aspect}
              onChange={(e) => { setAspect(e.target.value) }}
              className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors"
            >
              <option value="">{t('parts-catalog:tireSize.selectAspect')}</option>
              {TIRE_ASPECT_RATIOS.map((a) => (
                <option key={a} value={a}>{a}</option>
              ))}
            </select>
          </div>

          <span className="pb-3 text-lg text-gray-300 font-light">R</span>

          {/* Rim Diameter */}
          <div className="flex-1">
            <label htmlFor="tire-rim" className="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">
              {t('parts-catalog:tireSize.rimDiameter')}
            </label>
            <select
              id="tire-rim"
              value={rim}
              onChange={(e) => { setRim(e.target.value) }}
              className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors"
            >
              <option value="">{t('parts-catalog:tireSize.selectRim')}</option>
              {TIRE_RIM_DIAMETERS.map((r) => (
                <option key={r} value={r}>{r}</option>
              ))}
            </select>
          </div>
        </div>

        {/* Search button */}
        <button
          type="submit"
          disabled={!width || !aspect || !rim || isLoading}
          className="w-full flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
        >
          {isLoading ? (
            <Loader2 className="h-4 w-4 animate-spin" />
          ) : (
            <Search className="h-4 w-4" />
          )}
          {t('parts-catalog:tireSize.searchButton')}
        </button>
      </form>

      {/* Results */}
      {allArticles.length > 0 && (
        <div className="mt-5 space-y-2">
          {allArticles.map((article) => (
            <ArticleCard
              key={article.id}
              article={article}
              onSelect={onArticleSelected}
            />
          ))}
          {hasNextPage && (
            <button
              type="button"
              onClick={() => { void fetchNextPage() }}
              disabled={isFetchingNextPage}
              className="w-full rounded-lg border border-gray-200 py-2.5 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-50 transition-colors"
            >
              {isFetchingNextPage
                ? t('parts-catalog:results.loadingMore')
                : t('parts-catalog:results.loadMore')}
            </button>
          )}
        </div>
      )}

      {activeFilters.length > 0 && !isLoading && allArticles.length === 0 && (
        <div className="mt-8 text-center">
          <p className="text-sm text-gray-500">{t('parts-catalog:results.noResults')}</p>
          <p className="mt-1 text-xs text-gray-400">{t('parts-catalog:results.noResultsHint')}</p>
        </div>
      )}
    </div>
  )
}
