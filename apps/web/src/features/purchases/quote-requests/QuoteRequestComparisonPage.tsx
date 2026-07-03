import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { RotateCcw } from 'lucide-react'
import { toast } from 'sonner'

import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { formatCurrency, formatQuantity } from '@/lib/decimal'

import {
  useAwardQuoteRequest,
  useQuoteRequestGroup,
  useReopenQuoteRequestGroup,
} from './api'
import { correlateGroupLines } from './comparisonLogic'
import { mutationErrorMessage } from './errorMessage'

interface ApiErrorLike {
  response?: {
    status?: number
  }
}

function isValidationError(error: unknown): boolean {
  return (error as ApiErrorLike).response?.status === 422
}

export function QuoteRequestComparisonPage() {
  const { t } = useTranslation(['common', 'purchases'])
  const { groupId = '' } = useParams<{ groupId: string }>()
  const navigate = useNavigate()
  const { data: group, isLoading, refetch } = useQuoteRequestGroup(groupId)
  const reopenGroup = useReopenQuoteRequestGroup(groupId)
  const [pendingAwardId, setPendingAwardId] = useState<string | null>(null)
  const awardQuoteRequest = useAwardQuoteRequest(pendingAwardId ?? '', groupId)

  if (isLoading || group === undefined) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('common:status.loading')}</div>
      </div>
    )
  }

  const rows = correlateGroupLines(group.siblings)
  const groupAllowsChanges = group.has_live_purchase_order === false
  const canReopen = groupAllowsChanges && group.siblings.some((sibling) => sibling.closed_reason === 'lost')
  const siblingColumnCount = String(group.siblings.length)

  async function confirmAward() {
    if (pendingAwardId === null) return
    try {
      const purchaseOrder = await awardQuoteRequest.mutateAsync()
      void navigate(`/purchases/orders/${purchaseOrder.id}`)
    } catch (error) {
      toast.error(mutationErrorMessage(error, t('common:errors.unexpected')))
      if (isValidationError(error)) {
        void refetch()
      }
    } finally {
      setPendingAwardId(null)
    }
  }

  async function reopen() {
    try {
      await reopenGroup.mutateAsync()
    } catch (error) {
      toast.error(mutationErrorMessage(error, t('common:errors.unexpected')))
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className={`text-2xl font-bold ${textColors.primary}`}>
            {t('purchases:quoteRequests.comparison.title')}
          </h1>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('purchases:quoteRequests.comparison.description')}
          </p>
        </div>
        {canReopen && (
          <button
            type="button"
            data-testid="reopen-group"
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
            onClick={() => { void reopen() }}
          >
            <RotateCcw className="me-2 h-4 w-4" />
            {t('purchases:quoteRequests.actions.reopen')}
          </button>
        )}
      </div>

      <section className={`${tokens.card.base} overflow-x-auto`}>
        <div
          className="grid min-w-[900px] gap-3"
          style={{ gridTemplateColumns: `minmax(220px, 1fr) repeat(${siblingColumnCount}, minmax(180px, 1fr))` }}
        >
          <div className={`border-b ${borderColors.light} pb-3 text-xs font-medium uppercase ${textColors.tertiary}`}>
            {t('purchases:quoteRequests.fields.product')}
          </div>
          {group.siblings.map((sibling) => (
            <div key={sibling.id} className={`border-b ${borderColors.light} pb-3`}>
              <div className={`font-semibold ${textColors.primary}`}>{sibling.partner.name}</div>
              <Link
                className={`mt-1 block text-xs underline-offset-2 hover:underline ${textColors.tertiary}`}
                data-testid={`open-sibling-${sibling.id}`}
                to={`/purchases/quote-requests/${sibling.id}`}
              >
                {sibling.number}
              </Link>
              <div className={`mt-2 text-sm ${textColors.secondary}`}>
                {formatCurrency(sibling.total, true, sibling.currency)}
              </div>
              <div className={`mt-1 text-xs ${textColors.tertiary}`}>
                {t('purchases:quoteRequests.fields.validityDate')}: {sibling.validity_date ?? '-'}
              </div>
              <div className={`mt-1 text-xs ${textColors.tertiary}`}>
                {t('purchases:quoteRequests.fields.leadTimeDays')}: {sibling.lead_time_days ?? '-'}
              </div>
              {groupAllowsChanges && sibling.responded_at && sibling.status === 'confirmed' && (
                <button
                  type="button"
                  data-testid={`award-${sibling.id}`}
                  className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm} mt-3`}
                  onClick={() => { setPendingAwardId(sibling.id) }}
                >
                  {t('purchases:quoteRequests.actions.award')}
                </button>
              )}
            </div>
          ))}

          {rows.map((row) => (
            <div key={row.key} className="contents">
              <div className={`border-b ${borderColors.light} py-3 text-sm font-medium ${textColors.primary}`}>
                {row.label}
              </div>
              {group.siblings.map((sibling) => {
                const cell = row.cells[sibling.id]
                const isBest = row.bestSiblingIds.includes(sibling.id)
                const hasResponse = sibling.responded_at !== null && sibling.responded_at !== undefined

                return (
                  <div key={`${row.key}-${sibling.id}`} className={`border-b ${borderColors.light} py-3`}>
                    {cell === undefined ? (
                      <span className={`text-sm ${textColors.disabled}`}>
                        {t('purchases:quoteRequests.comparison.unmatched')}
                      </span>
                    ) : !hasResponse ? (
                      <span className={`text-sm ${textColors.disabled}`}>
                        {t('purchases:quoteRequests.comparison.awaitingResponse')}
                      </span>
                    ) : (
                      <div
                        data-testid={isBest ? `best-price-${sibling.id}-${row.key}` : undefined}
                        className={isBest ? `${tokens.badge.base} ${tokens.badge.green}` : `text-sm ${textColors.secondary}`}
                      >
                        <span>{formatCurrency(cell.unit_price, true, sibling.currency)}</span>
                        <span className="ms-2">{formatQuantity(cell.quantity)}</span>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          ))}
        </div>
      </section>

      {pendingAwardId !== null && (
        <div className={tokens.modal.backdrop}>
          <div className={tokens.modal.container} role="dialog" aria-modal="true">
            <h2 className={tokens.modal.title}>{t('purchases:quoteRequests.confirm.awardTitle')}</h2>
            <p className={`mt-3 text-sm ${textColors.secondary}`}>
              {t('purchases:quoteRequests.confirm.awardMessage')}
            </p>
            <div className={tokens.modal.footer}>
              <button
                type="button"
                className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
                onClick={() => { setPendingAwardId(null) }}
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="button"
                data-testid="confirm-award"
                className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
                onClick={() => { void confirmAward() }}
              >
                {t('purchases:quoteRequests.actions.award')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
