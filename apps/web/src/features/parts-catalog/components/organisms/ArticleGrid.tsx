import { useCallback, useEffect, useRef, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Loader2, PackageSearch, LayoutGrid, List } from 'lucide-react'
import { cn } from '@/lib/utils'
import { ArticleCard } from '../molecules/ArticleCard'
import { useCatalogStore } from '../../stores/useCatalogStore'
import type { EnrichedArticle, PaginatedArticles } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface ArticleGridProps {
  pages: PaginatedArticles[] | undefined
  isLoading: boolean
  hasNextPage: boolean
  isFetchingNextPage: boolean
  fetchNextPage: () => void
  onArticleSelected: (article: EnrichedArticle) => void
  className?: string
}

export function ArticleGrid({
  pages,
  isLoading,
  hasNextPage,
  isFetchingNextPage,
  fetchNextPage,
  onArticleSelected,
  className,
}: ArticleGridProps) {
  const { t } = useTranslation(['parts-catalog'])
  const sentinelRef = useRef<HTMLDivElement>(null)
  const { viewMode, setViewMode, filters } = useCatalogStore()

  const allArticles = pages?.flatMap((p) => p.data) ?? []

  // Client-side filtering and sorting
  const filteredArticles = useMemo(() => {
    let result = [...allArticles]

    // Filter by supplier
    if (filters.supplierIds.length > 0) {
      result = result.filter((a) => filters.supplierIds.includes(a.supplier?.id))
    }

    // Filter by in-stock
    if (filters.inStockOnly) {
      result = result.filter((a) => a.local_inventory?.in_stock)
    }

    // Filter by confidence minimum
    if (filters.confidenceMin > 0) {
      result = result.filter((a) => {
        const maxConf = (a.compatible_vehicles ?? []).length > 0
          ? Math.max(...(a.compatible_vehicles ?? []).map((v) => v.fitment_confidence))
          : 0
        return maxConf >= filters.confidenceMin
      })
    }

    // Sort
    switch (filters.sortBy) {
      case 'brand_asc':
        result.sort((a, b) => (a.supplier?.brand ?? '').localeCompare(b.supplier?.brand ?? ''))
        break
      case 'confidence_desc':
        result.sort((a, b) => {
          const confA = (a.compatible_vehicles ?? []).length > 0
            ? Math.max(...(a.compatible_vehicles ?? []).map((v) => v.fitment_confidence))
            : 0
          const confB = (b.compatible_vehicles ?? []).length > 0
            ? Math.max(...(b.compatible_vehicles ?? []).map((v) => v.fitment_confidence))
            : 0
          return confB - confA
        })
        break
      case 'price_asc':
      case 'price_desc': {
        const dir = filters.sortBy === 'price_asc' ? 1 : -1
        result.sort((a, b) => {
          const priceA = (a.prices ?? [])[0] ? parseFloat((a.prices ?? [])[0].price) : 0
          const priceB = (b.prices ?? [])[0] ? parseFloat((b.prices ?? [])[0].price) : 0
          return (priceA - priceB) * dir
        })
        break
      }
      // 'relevance' - keep API order
    }

    return result
  }, [allArticles, filters])

  const totalCount = filteredArticles.length

  // Infinite scroll via IntersectionObserver
  const handleIntersect = useCallback(
    (entries: IntersectionObserverEntry[]) => {
      const entry = entries[0]
      if (entry.isIntersecting && hasNextPage && !isFetchingNextPage) {
        fetchNextPage()
      }
    },
    [hasNextPage, isFetchingNextPage, fetchNextPage]
  )

  useEffect(() => {
    const sentinel = sentinelRef.current
    if (!sentinel) return

    const observer = new IntersectionObserver(handleIntersect, {
      rootMargin: '200px',
    })
    observer.observe(sentinel)

    return () => { observer.disconnect() }
  }, [handleIntersect])

  if (isLoading) {
    return (
      <div className={cn('flex flex-col items-center justify-center py-16', className)}>
        <Loader2 className={`h-8 w-8 animate-spin ${colorTokens.intent.primary.text} mb-3`} />
        <p className={`text-sm ${colorTokens.text.subtle}`}>{t('parts-catalog:results.loadingMore')}</p>
      </div>
    )
  }

  if (totalCount === 0) {
    return (
      <div className={cn('flex flex-col items-center justify-center py-16', className)}>
        <PackageSearch className={`h-16 w-16 ${colorTokens.text.faint} mb-3`} />
        <p className={`text-lg font-medium ${colorTokens.text.subtle}`}>{t('parts-catalog:results.noResults')}</p>
        <p className={`mt-1 text-sm ${colorTokens.text.disabled}`}>{t('parts-catalog:results.noResultsHint')}</p>
      </div>
    )
  }

  return (
    <div className={cn('flex flex-col', className)}>
      {/* Toolbar: count + view toggle */}
      <div className="flex items-center justify-between mb-3">
        <p className={`text-xs ${colorTokens.text.disabled}`}>
          {t('parts-catalog:results.showing', { count: totalCount })}
        </p>
        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={() => { setViewMode('grid') }}
            className={cn(
              'rounded-md p-1.5 transition-colors',
              viewMode === 'grid'
                ? `${colorTokens.surface.subdued} ${colorTokens.text.secondary}`
                : `${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover}`
            )}
            aria-label={t('parts-catalog:filters.viewGrid')}
          >
            <LayoutGrid className="h-4 w-4" />
          </button>
          <button
            type="button"
            onClick={() => { setViewMode('list') }}
            className={cn(
              'rounded-md p-1.5 transition-colors',
              viewMode === 'list'
                ? `${colorTokens.surface.subdued} ${colorTokens.text.secondary}`
                : `${colorTokens.text.disabled} ${colorTokens.intent.neutral.textHover}`
            )}
            aria-label={t('parts-catalog:filters.viewList')}
          >
            <List className="h-4 w-4" />
          </button>
        </div>
      </div>

      {/* Article grid/list */}
      <div className={cn(
        viewMode === 'grid'
          ? 'grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3'
          : 'space-y-2'
      )}>
        {filteredArticles.map((article) => (
          <ArticleCard
            key={article.id}
            article={article}
            onSelect={onArticleSelected}
            viewMode={viewMode}
          />
        ))}
      </div>

      {/* Infinite scroll sentinel */}
      <div ref={sentinelRef} className="h-1" />

      {/* Loading indicator */}
      {isFetchingNextPage && (
        <div className="flex items-center justify-center py-6">
          <Loader2 className={`h-5 w-5 animate-spin ${colorTokens.intent.primary.text}`} />
          <span className={`ms-2 text-sm ${colorTokens.text.subtle}`}>{t('parts-catalog:results.loadingMore')}</span>
        </div>
      )}
    </div>
  )
}
