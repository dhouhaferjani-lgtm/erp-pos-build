import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { FileText, Plus } from 'lucide-react'

import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import { PageHeader } from '@/components/molecules/PageHeader/PageHeader'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/decimal'

import { useQuoteRequests } from './api'
import type { QuoteRequestListItem } from './types'

interface QuoteRequestGroupRow {
  groupId: string
  primary: QuoteRequestListItem
  siblings: QuoteRequestListItem[]
}

function groupQuoteRequests(items: QuoteRequestListItem[]): QuoteRequestGroupRow[] {
  const groups = new Map<string, QuoteRequestListItem[]>()

  items.forEach((item) => {
    const groupId = item.group_id ?? item.id
    const siblings = groups.get(groupId) ?? []
    siblings.push(item)
    groups.set(groupId, siblings)
  })

  return Array.from(groups.entries()).map(([groupId, siblings]) => ({
    groupId,
    primary: siblings[0],
    siblings,
  }))
}

function hasResponse(item: QuoteRequestListItem): boolean {
  return item.responded_at !== null && item.responded_at !== undefined
}

function statusLabelKey(item: QuoteRequestListItem): string {
  if (item.closed_reason === 'lost') {
    return 'purchases:quoteRequests.status.lost'
  }
  if (item.status === 'confirmed' && !hasResponse(item)) {
    return 'purchases:quoteRequests.status.sent'
  }
  return `purchases:quoteRequests.status.${item.status}`
}

function statusTone(item: QuoteRequestListItem): StatusTone {
  if (item.closed_reason === 'lost' || item.status === 'cancelled') {
    return 'neutral'
  }
  if (item.status === 'confirmed') {
    return 'success'
  }
  return 'pending'
}

export function QuoteRequestListPage() {
  const { t } = useTranslation(['common', 'purchases'])
  const { data, isLoading } = useQuoteRequests({})
  const groups = groupQuoteRequests(data?.data ?? [])

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('purchases:quoteRequests.title')}
        subtitle={t('purchases:quoteRequests.description')}
        actions={
          <Link
            to="/purchases/quote-requests/new"
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
          >
            <Plus className="me-2 h-4 w-4" />
            {t('purchases:quoteRequests.actions.new')}
          </Link>
        }
        className="mb-0"
      />

      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={textColors.tertiary}>{t('common:status.loading')}</div>
        </div>
      ) : groups.length === 0 ? (
        <div className={`${tokens.card.base} p-12 text-center`}>
          <FileText className={`mx-auto h-12 w-12 ${textColors.disabled}`} />
          <h3 className={`mt-4 text-lg font-medium ${textColors.primary}`}>
            {t('purchases:quoteRequests.empty')}
          </h3>
        </div>
      ) : (
        <div className={`${tokens.card.base} overflow-hidden p-0`}>
          <div className={`${tokens.table.header} grid grid-cols-[1.1fr_1.6fr_1fr_1fr_auto] gap-4 px-4 py-3 text-xs font-medium uppercase ${textColors.tertiary}`}>
            <div>{t('purchases:quoteRequests.columns.number')}</div>
            <div>{t('purchases:quoteRequests.columns.suppliers')}</div>
            <div>{t('purchases:quoteRequests.columns.status')}</div>
            <div>{t('purchases:quoteRequests.columns.total')}</div>
            <div>{t('purchases:quoteRequests.columns.actions')}</div>
          </div>
          <div className={`divide-y ${borderColors.divideLight}`}>
            {groups.map((group) => {
              const responses = group.siblings.filter(hasResponse).length
              const supplierNames = group.siblings.map((sibling) => sibling.partner.name).join(', ')
              const href = group.siblings.length === 1
                ? `/purchases/quote-requests/${group.primary.id}`
                : `/purchases/quote-requests/groups/${group.groupId}`

              return (
                <div
                  key={group.groupId}
                  data-testid={`rfq-group-row-${group.groupId}`}
                  className={`grid grid-cols-[1.1fr_1.6fr_1fr_1fr_auto] items-center gap-4 px-4 py-4 ${tokens.table.rowHover}`}
                >
                  <div>
                    <div className={`font-medium ${textColors.primary}`}>{group.primary.number}</div>
                    <span data-testid={`rfq-group-chip-${group.groupId}`}>
                      <StatusBadge tone="info" className="mt-2">
                        {t('purchases:quoteRequests.list.groupChip', {
                          count: group.siblings.length,
                          responseCount: responses,
                        })}
                      </StatusBadge>
                    </span>
                  </div>
                  <div className={`text-sm ${textColors.secondary}`}>{supplierNames}</div>
                  <div>
                    <StatusBadge tone={statusTone(group.primary)}>
                      {t(statusLabelKey(group.primary))}
                    </StatusBadge>
                  </div>
                  <div className={`text-sm font-medium ${textColors.primary}`}>
                    {formatCurrency(group.primary.total, true, group.primary.currency)}
                  </div>
                  <Link className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`} to={href}>
                    {t('purchases:quoteRequests.actions.open')}
                  </Link>
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
