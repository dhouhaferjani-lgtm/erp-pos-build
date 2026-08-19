import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Ticket, Plus, MoreHorizontal, Ban, CalendarClock } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { tokens, colors, textColors, borderColors , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { usePermissions } from '@/hooks/usePermissions'
import { useVouchers } from '../hooks/useVouchers'
import { useVoidVoucher, useExtendExpiry } from '../hooks/useVoucherMutations'
import { SourceBadge } from '../components/SourceBadge'
import { StatusBadge } from '../components/StatusBadge'
import { IssueGoodwillVoucherModal } from '../components/IssueGoodwillVoucherModal'
import { VoidVoucherModal } from '../components/VoidVoucherModal'
import { ExtendExpiryModal } from '../components/ExtendExpiryModal'
import type { Voucher, VoucherSource, VoidVoucherPayload, ExtendExpiryPayload } from '../types/voucher'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const SOURCE_FILTERS: Array<{ value: VoucherSource | ''; label: string }> = [
  { value: '', label: 'sources.All' },
  { value: 'refund', label: 'sources.refund' },
  { value: 'exchange_surplus', label: 'sources.exchange_surplus' },
  { value: 'goodwill', label: 'sources.goodwill' },
  { value: 'loyalty_credit', label: 'sources.loyalty_credit' },
  { value: 'gift_card_purchase', label: 'sources.gift_card_purchase' },
  { value: 'promotional', label: 'sources.promotional' },
]

export function VoucherListPage() {
  const { t } = useTranslation(['vouchers', 'common'])
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const { hasPermission } = usePermissions()

  const source = (searchParams.get('source') ?? '') as VoucherSource | ''
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  const { data, isLoading } = useVouchers({ source: source || '', page, per_page: perPage })

  const voidMutation = useVoidVoucher()
  const extendMutation = useExtendExpiry()

  const [isGoodwillOpen, setIsGoodwillOpen] = useState(false)
  const [voidTarget, setVoidTarget] = useState<Voucher | null>(null)
  const [extendTarget, setExtendTarget] = useState<Voucher | null>(null)
  const [openMenuId, setOpenMenuId] = useState<string | null>(null)

  const canVoid = hasPermission('pos.void_voucher')
  const canExtend = hasPermission('pos.extend_voucher_expiry')
  const canIssueGoodwill = hasPermission('pos.issue_goodwill_voucher')

  const handleSourceFilter = (value: VoucherSource | '') => {
    const next = new URLSearchParams(searchParams)
    if (value) {
      next.set('source', value)
    } else {
      next.delete('source')
    }
    setSearchParams(next)
    setPage(1)
  }

  const handleVoidSubmit = (payload: VoidVoucherPayload) => {
    if (!voidTarget) return
    voidMutation.mutate(
      { id: voidTarget.id, payload },
      { onSuccess: () => { setVoidTarget(null) } },
    )
  }

  const handleExtendSubmit = (payload: ExtendExpiryPayload) => {
    if (!extendTarget) return
    extendMutation.mutate(
      { id: extendTarget.id, payload },
      { onSuccess: () => { setExtendTarget(null) } },
    )
  }

  const vouchers = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${textColors.primary}`}>{t('vouchers:pageTitle')}</PageHeaderTitle>
          <p className={`text-sm ${textColors.tertiary} mt-1`}>{t('vouchers:pageSubtitle')}</p>
        </div>
        {canIssueGoodwill && (
          <Button onClick={() => { setIsGoodwillOpen(true) }}>
            <Plus className="w-4 h-4 mr-2" />
            {t('vouchers:actions.issueGoodwill')}
          </Button>
        )}
      </div>

      {/* Source filter chips */}
      <div className="flex flex-wrap gap-2">
        {SOURCE_FILTERS.map((filter) => {
          const isSelected = source === filter.value
          return (
            // Selected/unselected treatment matched to the house filter-chip pattern at
            // features/import/pages/ImportHistoryPage.tsx:90-104 (selected = primary
            // bgStrong + inverse text; unselected = neutral surface). Expressed through
            // the canonical Button variants rather than className overrides so the tokens
            // come from one place and cannot lose a Tailwind class-order fight.
            <Button variant={isSelected ? 'primary' : 'secondary'}
              key={filter.value}
              type="button"
              aria-pressed={isSelected}
              onClick={() => { handleSourceFilter(filter.value) }}
              className="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium transition-colors"
              data-testid={`source-filter-${filter.value || 'all'}`}
            >
              {t(`vouchers:${filter.label}`)}
            </Button>
          )
        })}
      </div>

      {/* Table */}
      <div className={`overflow-hidden rounded-lg border ${borderColors.light} bg-white shadow-sm`}>
        {isLoading ? (
          <div className="flex justify-center py-12">
            <Spinner />
          </div>
        ) : vouchers.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16 text-center">
            <Ticket className={`w-12 h-12 ${textColors.disabled} mb-4`} />
            <p className={`text-lg font-medium ${textColors.secondary}`}>{t('vouchers:empty.title')}</p>
            <p className={`text-sm ${textColors.tertiary} mt-1 max-w-sm`}>{t('vouchers:empty.subtitle')}</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
                <thead className={tokens.table.header}>
                  <tr>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('vouchers:fields.code')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('vouchers:fields.source')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('vouchers:fields.customer')}
                    </th>
                    <th className={`px-4 py-3 text-right text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('vouchers:fields.balance')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('vouchers:fields.status')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('vouchers:fields.expiresAt')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('vouchers:fields.terminal')}
                    </th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className={`bg-white divide-y ${colorTokens.border.divider}`}>
                  {vouchers.map((voucher) => (
                    <tr
                      key={voucher.id}
                      className={`${tokens.table.rowHover} cursor-pointer`}
                      onClick={(e) => {
                        // Don't navigate when clicking the action menu
                        const target = e.target as HTMLElement
                        if (target.closest('[data-action-menu]')) return
                        navigate(`/pos/vouchers/${voucher.id}`)
                      }}
                    >
                      <td className={`px-4 py-3 text-sm font-mono font-medium ${textColors.primary}`}>
                        {voucher.code}
                      </td>
                      <td className="px-4 py-3">
                        <SourceBadge source={voucher.source} />
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary}`}>
                        {voucher.partner_name ?? '—'}
                      </td>
                      <td className={`px-4 py-3 text-sm text-right font-mono ${textColors.primary}`}>
                        {voucher.current_balance} {voucher.currency}
                      </td>
                      <td className="px-4 py-3">
                        <StatusBadge status={voucher.status} />
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary}`}>
                        {voucher.expires_at
                          ? new Date(voucher.expires_at).toLocaleDateString()
                          : '—'}
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary}`}>
                        {voucher.terminal_name ?? voucher.cashier_name ?? '—'}
                      </td>
                      <td className="px-4 py-3" data-action-menu>
                        <div className="relative">
                          <Button
                            type="button"
                            onClick={(e) => {
                              e.stopPropagation()
                              setOpenMenuId(openMenuId === voucher.id ? null : voucher.id)
                            }}
                            className={`p-1.5 rounded ${colors.hover.gray100} ${textColors.tertiary}`}
                            aria-label={t('common:actions')}
                          >
                            <MoreHorizontal className="w-4 h-4" />
                          </Button>

                          {openMenuId === voucher.id && (
                            <div
                              className={`absolute right-0 z-10 mt-1 w-44 rounded-md bg-white shadow-lg ring-1 ${borderColors.light}`}
                              role="menu"
                            >
                              {canVoid && (
                                <Button
                                  type="button"
                                  role="menuitem"
                                  onClick={(e) => {
                                    e.stopPropagation()
                                    setOpenMenuId(null)
                                    setVoidTarget(voucher)
                                  }}
                                  className={`flex w-full items-center gap-2 px-3 py-2 text-sm ${textColors.error} ${colors.hover.red50}`}
                                >
                                  <Ban className="w-4 h-4" />
                                  {t('vouchers:actions.void')}
                                </Button>
                              )}
                              {canExtend && (
                                <Button
                                  type="button"
                                  role="menuitem"
                                  onClick={(e) => {
                                    e.stopPropagation()
                                    setOpenMenuId(null)
                                    setExtendTarget(voucher)
                                  }}
                                  className={`flex w-full items-center gap-2 px-3 py-2 text-sm ${textColors.secondary} ${colors.hover.gray50}`}
                                >
                                  <CalendarClock className="w-4 h-4" />
                                  {t('vouchers:actions.extend')}
                                </Button>
                              )}
                            </div>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            </div>

            {meta && (
              <OffsetPagination
                currentPage={meta.current_page}
                lastPage={meta.last_page}
                total={meta.total}
                perPage={meta.per_page}
                from={null}
                to={null}
                onPageChange={(value) => { setPage(value) }}
                onPerPageChange={(value) => {
                  setPerPage(value)
                  setPage(1)
                }}
              />
            )}
          </>
        )}
      </div>

      {/* Modals */}
      <IssueGoodwillVoucherModal
        isOpen={isGoodwillOpen}
        onClose={() => { setIsGoodwillOpen(false) }}
      />

      <VoidVoucherModal
        isOpen={voidTarget !== null}
        onClose={() => { setVoidTarget(null) }}
        onSubmit={handleVoidSubmit}
        isPending={voidMutation.isPending}
      />

      <ExtendExpiryModal
        isOpen={extendTarget !== null}
        onClose={() => { setExtendTarget(null) }}
        onSubmit={handleExtendSubmit}
        isPending={extendMutation.isPending}
      />
    </div>
  )
}
