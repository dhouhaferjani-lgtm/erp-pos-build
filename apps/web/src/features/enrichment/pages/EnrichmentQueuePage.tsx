import { useState, useCallback, useEffect, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { tokens, textColors, colors } from '@/lib/designTokens'
import { usePermissions, type Permission } from '@/hooks/usePermissions'
import { useEnrichmentResults, useBulkAcceptEnrichment } from '../api/enrichmentQueries'
import { EnrichmentQueueTable } from '../components/EnrichmentQueueTable'
import { EnrichmentReviewPanel } from '../components/EnrichmentReviewPanel'

type QualityFilter = 'all' | 'high' | 'medium' | 'low'

export function EnrichmentQueuePage() {
  const { t } = useTranslation('enrichment')
  const { hasPermission } = usePermissions()
  const canReview = hasPermission('enrichment.review' as Permission)

  const [searchParams] = useSearchParams()
  const highlightId = searchParams.get('highlight')

  const [selectedResultId, setSelectedResultId] = useState<string | null>(null)
  const [qualityFilter, setQualityFilter] = useState<QualityFilter>('all')
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())

  const queryParams = useMemo(() => {
    const params: { status: string; quality?: string } = { status: 'pending_review' }
    if (qualityFilter !== 'all') {
      params.quality = qualityFilter
    }
    return params
  }, [qualityFilter])
  const { data, isLoading } = useEnrichmentResults(queryParams)
  const bulkAcceptMutation = useBulkAcceptEnrichment()

  const results = data?.data ?? []
  const total = data?.meta?.total ?? 0

  // Auto-open panel from ?highlight= query param
  useEffect(() => {
    if (highlightId) {
      setSelectedResultId(highlightId)
    }
  }, [highlightId])

  const handleRowClick = useCallback((id: string) => {
    setSelectedResultId(id)
  }, [])

  const handleClosePanel = useCallback(() => {
    setSelectedResultId(null)
  }, [])

  const handleToggleSelect = useCallback((id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }, [])

  const handleToggleSelectAll = useCallback(() => {
    setSelectedIds((prev) => {
      if (prev.size === results.length && results.length > 0) {
        return new Set()
      }
      return new Set(results.map((r) => r.id))
    })
  }, [results])

  const handleBulkAccept = useCallback(() => {
    if (selectedIds.size === 0) return
    const confirmed = confirm(
      t('queue.bulkAcceptConfirm', { count: selectedIds.size }),
    )
    if (!confirmed) return

    bulkAcceptMutation.mutate(Array.from(selectedIds), {
      onSuccess: () => {
        toast.success(t('queue.bulkAcceptSuccess', { count: selectedIds.size }))
        setSelectedIds(new Set())
      },
      onError: () => {
        toast.error(t('queue.bulkAcceptError'))
      },
    })
  }, [selectedIds, bulkAcceptMutation, t])

  // Summary stats
  const stats = useMemo(() => {
    const high = results.filter((r) => r.enrichment_quality === 'high').length
    const medium = results.filter((r) => r.enrichment_quality === 'medium').length
    const low = results.filter((r) => r.enrichment_quality === 'low').length
    return { high, medium, low }
  }, [results])

  return (
    <div className="p-6">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className={`text-2xl font-semibold ${textColors.primary}`}>
            {t('queue.title')}
          </h1>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('queue.subtitle', { total })}
            {' \u2014 '}
            <span className={textColors.success}>{stats.high} {t('quality.high')}</span>
            {', '}
            <span className={textColors.warningDark}>{stats.medium} {t('quality.medium')}</span>
            {', '}
            <span className={textColors.error}>{stats.low} {t('quality.low')}</span>
          </p>
        </div>

        <div className="flex items-center gap-3">
          {/* Quality filter */}
          <select
            value={qualityFilter}
            onChange={(e) => setQualityFilter(e.target.value as QualityFilter)}
            className={tokens.select.base}
            style={{ width: 'auto', marginTop: 0 }}
          >
            <option value="all">{t('queue.filterAll')}</option>
            <option value="high">{t('quality.high')}</option>
            <option value="medium">{t('quality.medium')}</option>
            <option value="low">{t('quality.low')}</option>
          </select>

          {/* Bulk accept */}
          {canReview && selectedIds.size > 0 && (
            <button
              onClick={handleBulkAccept}
              disabled={bulkAcceptMutation.isPending}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
            >
              {bulkAcceptMutation.isPending
                ? t('queue.bulkAccepting')
                : t('queue.bulkAccept', { count: selectedIds.size })}
            </button>
          )}
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className={`text-center py-12 ${textColors.tertiary}`}>
          {t('queue.loading')}
        </div>
      ) : results.length === 0 ? (
        <div className={`text-center py-16 ${colors.neutral[50]} rounded-lg`}>
          <p className={`text-lg font-medium ${textColors.secondary}`}>
            {t('queue.emptyTitle')}
          </p>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('queue.emptySubtitle')}
          </p>
        </div>
      ) : (
        <EnrichmentQueueTable
          results={results}
          selectedIds={selectedIds}
          onToggleSelect={handleToggleSelect}
          onToggleSelectAll={handleToggleSelectAll}
          onRowClick={handleRowClick}
        />
      )}

      {/* Slide-over panel */}
      {selectedResultId && (
        <EnrichmentReviewPanel
          resultId={selectedResultId}
          onClose={handleClosePanel}
        />
      )}
    </div>
  )
}
