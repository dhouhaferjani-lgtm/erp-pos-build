import { useState } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, CheckCircle, FileText, AlertTriangle } from 'lucide-react'
import { ReconciliationTable } from '../components/ReconciliationTable'
import { CountingStatusBadge } from '../components/CountingStatusBadge'
import {
  useCountingDetail,
  useFinalizeCounting,
  useReconciliation,
} from '../api/queries'
import { isBlockingFlag, type ReconciliationItem } from '../types'
import { textColors } from '@/lib/designTokens'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

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
      <div className={`p-8 text-center ${colorTokens.text.subtle}`}>
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
            className={`inline-flex items-center justify-center w-10 h-10 rounded-full ${colorTokens.intent.neutral.bgHoverSoft}`}
          >
            <ArrowLeft className="w-5 h-5" />
          </Link>
          <div>
            <PageHeaderTitle className="text-2xl font-bold flex items-center gap-3">
              {t('counting.review.title')} #{counting.id.slice(0, 8)}
              <CountingStatusBadge status={counting.status} />
            </PageHeaderTitle>
            <p className={colorTokens.text.subtle}>{t('counting.review.description')}</p>
          </div>
        </div>

        <div className="flex gap-2">
          <Link
            to={`/inventory/counting/${String(countingId)}/report`}
            className={`inline-flex items-center px-4 py-2 text-sm font-medium border ${colorTokens.border.default} rounded-md ${colorTokens.intent.neutral.bgHover}`}
          >
            <FileText className="w-4 h-4 me-2" />
            {t('counting.actions.viewReport')}
          </Link>

          <button
            type="button"
            onClick={() => { setShowFinalizeConfirm(true); }}
            disabled={!canFinalize || finalize.isPending}
            className={`inline-flex items-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.success.bgStrong} rounded-md ${colorTokens.intent.success.bgStrongHover} disabled:opacity-50 disabled:cursor-not-allowed`}
          >
            <CheckCircle className="w-4 h-4 me-2" />
            {t('counting.actions.finalize')}
          </button>
        </div>
      </div>

      {/* Warning if items need attention */}
      {reconciliation && reconciliation.summary.needs_attention > 0 && (
        <div className={`flex items-start gap-3 p-4 ${colorTokens.intent.caution.bgSubtle} border ${colorTokens.intent.caution.borderSubtle} rounded-lg`}>
          <AlertTriangle className={`w-5 h-5 ${colorTokens.intent.caution.text} flex-shrink-0 mt-0.5`} />
          <div>
            <p className={`font-medium ${colorTokens.intent.caution.textStronger}`}>
              {t('counting.review.itemsNeedAttention', {
                count: reconciliation.summary.needs_attention,
              })}
            </p>
            <p className={`text-sm ${colorTokens.intent.caution.textStrong} mt-1`}>
              {t('counting.review.resolveBeforeFinalize')}
            </p>
          </div>
        </div>
      )}

      {/* Late-sale flags captured during the block window */}
      {lateSalesFlags.length > 0 && (
        <div className={`flex items-start gap-3 p-4 ${colorTokens.intent.primary.bgSubtle} border ${colorTokens.intent.primary.borderSubtle} rounded-lg`}>
          <AlertTriangle className={`w-5 h-5 ${colorTokens.intent.primary.text} flex-shrink-0 mt-0.5`} />
          <div>
            <p className={`font-medium ${colorTokens.intent.primary.textStronger}`}>
              {t('counting.review.lateSalesDetected', {
                count: lateSalesFlags.length,
              })}
            </p>
            <p className={`text-sm ${colorTokens.intent.primary.textStrong} mt-1`}>
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
            className={`fixed inset-0 ${colorTokens.surface.overlay} transition-opacity`}
            onClick={() => { setShowFinalizeConfirm(false); }}
          />
          <div className="flex min-h-full items-center justify-center p-4">
            <div className={`relative ${colorTokens.surface.base} rounded-lg shadow-xl max-w-md w-full p-6`}>
              <h2 className="text-lg font-semibold mb-2">
                {t('counting.review.finalizeConfirm.title')}
              </h2>
              <p className={`${colorTokens.text.muted} mb-2`}>
                {t('counting.review.finalizeConfirm.description')}
              </p>

              <p className={`${textColors.warning} mb-6 text-sm`}>
                {t('counting.review.finalizeConfirm.syncWarning')}
              </p>

              <div className="flex gap-3 justify-end">
                <button
                  type="button"
                  onClick={() => { setShowFinalizeConfirm(false); }}
                  className={`px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-md ${colorTokens.intent.neutral.bgHover}`}
                  disabled={finalize.isPending}
                >
                  {t('cancel')}
                </button>
                <button
                  type="button"
                  onClick={handleFinalize}
                  className={`px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.success.bgStrong} rounded-md ${colorTokens.intent.success.bgStrongHover} disabled:opacity-50`}
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
