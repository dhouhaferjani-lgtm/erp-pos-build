import { useState } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, CheckCircle, FileText, AlertTriangle } from 'lucide-react'
import {
  ReconciliationTable,
  CountingStatusBadge,
} from '../components'
import {
  useCountingDetail,
  useFinalizeCounting,
  useReconciliation,
} from '../api/queries'
import { isBlockingFlag, type ReconciliationItem } from '../types'
import { textColors } from '@/lib/designTokens'

// A line that must be resolved before the session can finalize: still pending,
// an onboarding opening awaiting its cost, or carrying an unresolved blocking
// flag (basket_window / negative_at_apply / clock_skew) with no final qty yet.
//
// The opening-cost gate keys off the PRE-finalize `opening_cost_missing` signal
// (computed server-side by OpeningCostGate — the same computation the server's
// finalize gate enforces), NOT the post-finalize `pending_opening_cost` flag,
// which is only stamped after finalize and is inert on this pending_review page.
function itemBlocksFinalize(item: ReconciliationItem): boolean {
  if (item.resolution_method === 'pending') {
    return true
  }

  if (item.opening_cost_missing) {
    return true
  }

  return (item.flag_reasons ?? []).some(isBlockingFlag) && item.final_qty === null
}

export function CountingReviewPage() {
  const { t } = useTranslation('inventory')
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const countingId = id ?? ''

  const { data: counting, isLoading } = useCountingDetail(countingId)
  const { data: reconciliation } = useReconciliation(countingId)
  const finalize = useFinalizeCounting()

  const [showFinalizeConfirm, setShowFinalizeConfirm] = useState(false)

  if (isLoading || !counting) {
    return (
      <div className="p-8 text-center text-gray-500">
        {t('loading')}...
      </div>
    )
  }

  const lateSalesFlags = reconciliation?.late_sales_flags ?? []
  const hasBlockingItem = (reconciliation?.items ?? []).some(itemBlocksFinalize)

  const canFinalize =
    counting.status === 'pending_review' &&
    reconciliation?.summary.needs_attention === 0 &&
    !hasBlockingItem

  const handleFinalize = () => {
    finalize.mutate(countingId, {
      onSuccess: () => {
        void navigate(`/inventory/counting/${String(countingId)}`)
      },
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to={`/inventory/counting/${String(countingId)}`}
            className="inline-flex items-center justify-center w-10 h-10 rounded-full hover:bg-gray-100"
          >
            <ArrowLeft className="w-5 h-5" />
          </Link>
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-3">
              {t('counting.review.title')} #{counting.uuid.slice(0, 8)}
              <CountingStatusBadge status={counting.status} />
            </h1>
            <p className="text-gray-500">{t('counting.review.description')}</p>
          </div>
        </div>

        <div className="flex gap-2">
          <Link
            to={`/inventory/counting/${String(countingId)}/report`}
            className="inline-flex items-center px-4 py-2 text-sm font-medium border border-gray-300 rounded-md hover:bg-gray-50"
          >
            <FileText className="w-4 h-4 me-2" />
            {t('counting.actions.viewReport')}
          </Link>

          <button
            type="button"
            onClick={() => { setShowFinalizeConfirm(true); }}
            disabled={!canFinalize || finalize.isPending}
            className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <CheckCircle className="w-4 h-4 me-2" />
            {t('counting.actions.finalize')}
          </button>
        </div>
      </div>

      {/* Warning if items need attention */}
      {reconciliation && reconciliation.summary.needs_attention > 0 && (
        <div className="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg">
          <AlertTriangle className="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
          <div>
            <p className="font-medium text-amber-800">
              {t('counting.review.itemsNeedAttention', {
                count: reconciliation.summary.needs_attention,
              })}
            </p>
            <p className="text-sm text-amber-700 mt-1">
              {t('counting.review.resolveBeforeFinalize')}
            </p>
          </div>
        </div>
      )}

      {/* Late-sale flags captured during the block window */}
      {lateSalesFlags.length > 0 && (
        <div className="flex items-start gap-3 p-4 bg-blue-50 border border-blue-200 rounded-lg">
          <AlertTriangle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
          <div>
            <p className="font-medium text-blue-800">
              {t('counting.review.lateSalesDetected', {
                count: lateSalesFlags.length,
              })}
            </p>
            <p className="text-sm text-blue-700 mt-1">
              {t('counting.review.lateSalesDescription')}
            </p>
          </div>
        </div>
      )}

      {/* Reconciliation Table */}
      <ReconciliationTable countingId={countingId} />

      {/* Finalize Confirmation Dialog */}
      {showFinalizeConfirm && (
        <div className="fixed inset-0 z-50 overflow-y-auto">
          <div
            className="fixed inset-0 bg-black/50 transition-opacity"
            onClick={() => { setShowFinalizeConfirm(false); }}
          />
          <div className="flex min-h-full items-center justify-center p-4">
            <div className="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
              <h2 className="text-lg font-semibold mb-2">
                {t('counting.review.finalizeConfirm.title')}
              </h2>
              <p className="text-gray-600 mb-2">
                {t('counting.review.finalizeConfirm.description')}
              </p>

              <p className={`${textColors.warning} mb-6 text-sm`}>
                {t('counting.review.finalizeConfirm.syncWarning')}
              </p>

              <div className="flex gap-3 justify-end">
                <button
                  type="button"
                  onClick={() => { setShowFinalizeConfirm(false); }}
                  className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                  disabled={finalize.isPending}
                >
                  {t('cancel')}
                </button>
                <button
                  type="button"
                  onClick={handleFinalize}
                  className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50"
                  disabled={finalize.isPending}
                >
                  {finalize.isPending
                    ? t('processing')
                    : t('counting.actions.finalize')}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
