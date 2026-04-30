import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { ShieldAlert } from 'lucide-react'
import { Spinner } from '@/components/atoms/Spinner/Spinner'
import { Pagination } from '@/components/ui/Pagination'
import { PartnerSearchSelect } from '@/components/ui/PartnerSearchSelect'
import { usePermissions } from '@/hooks/usePermissions'
import { useTerminals } from '@/features/pos/hooks/useTerminals'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { useCustomerHistorySearches } from '../hooks/useCustomerHistorySearches'
import { RejectedBadge } from '../components/RejectedBadge'
import { SummaryChips } from '../components/SummaryChips'
import type { CustomerHistorySearchFilters, RejectedFilter } from '../types/customerHistorySearch'

function isRejectedFilter(value: string): value is RejectedFilter {
  return value === '' || value === 'true' || value === 'false'
}

export function CustomerHistoryAuditPage() {
  const { t } = useTranslation(['customer-history-audit', 'common'])
  const [searchParams, setSearchParams] = useSearchParams()
  const { hasPermission } = usePermissions()

  const canAccess = hasPermission('pos.search_customer_full_history')

  const cashierIdParam = searchParams.get('cashier_id') ?? ''
  const terminalIdParam = searchParams.get('terminal_id') ?? ''
  const partnerIdParam = searchParams.get('partner_id') ?? ''
  const rawRejected = searchParams.get('was_rejected') ?? ''
  const wasRejectedParam: RejectedFilter = isRejectedFilter(rawRejected) ? rawRejected : ''
  const fromDateParam = searchParams.get('from_date') ?? ''
  const toDateParam = searchParams.get('to_date') ?? ''

  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  const filters: CustomerHistorySearchFilters = {
    page,
    per_page: perPage,
    ...(cashierIdParam ? { cashier_id: cashierIdParam } : {}),
    ...(terminalIdParam ? { terminal_id: terminalIdParam } : {}),
    ...(partnerIdParam ? { partner_id: partnerIdParam } : {}),
    ...(wasRejectedParam ? { was_rejected: wasRejectedParam } : {}),
    ...(fromDateParam ? { from_date: fromDateParam } : {}),
    ...(toDateParam ? { to_date: toDateParam } : {}),
  }

  const { data, isLoading, isError } = useCustomerHistorySearches(canAccess ? filters : {})

  const { data: terminalsData } = useTerminals()
  const terminals = terminalsData ?? []

  const rows = data?.data ?? []
  const meta = data?.meta

  const rejectedCount = meta?.rejected_total ?? 0
  const total = meta?.total ?? 0

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(searchParams)
    if (value) {
      next.set(key, value)
    } else {
      next.delete(key)
    }
    setSearchParams(next)
    setPage(1)
  }

  if (!canAccess) {
    return (
      <div className="flex min-h-[400px] flex-col items-center justify-center p-8 text-center">
        <div className={`rounded-full ${tokens.alert.error} p-4 mb-4`}>
          <ShieldAlert className={`h-12 w-12 ${textColors.error}`} aria-hidden="true" />
        </div>
        <h2 className={`text-xl font-semibold ${textColors.primary} mb-2`}>
          {t('customer-history-audit:accessDenied')}
        </h2>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div>
        <h1 className={`text-2xl font-bold ${textColors.primary}`}>
          {t('customer-history-audit:pageTitle')}
        </h1>
        <p className={`text-sm ${textColors.tertiary} mt-1`}>
          {t('customer-history-audit:pageSubtitle')}
        </p>
      </div>

      {/* Filter bar */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        {/* Cashier picker */}
        <div>
          <label
            htmlFor="filter-cashier"
            className={`${tokens.label.base} sr-only`}
          >
            {t('customer-history-audit:filters.cashier')}
          </label>
          <PartnerSearchSelect
            value={cashierIdParam}
            onChange={(id) => { setParam('cashier_id', id) }}
            placeholder={t('customer-history-audit:filters.cashier')}
          />
        </div>

        {/* Terminal select */}
        <div>
          <label
            htmlFor="filter-terminal"
            className={`${tokens.label.base} sr-only`}
          >
            {t('customer-history-audit:filters.terminal')}
          </label>
          <select
            id="filter-terminal"
            value={terminalIdParam}
            onChange={(e) => { setParam('terminal_id', e.target.value) }}
            className={tokens.select.base}
          >
            <option value="">{t('customer-history-audit:filters.terminal')}</option>
            {terminals.map((terminal) => (
              <option key={terminal.id} value={terminal.id}>
                {terminal.name}
              </option>
            ))}
          </select>
        </div>

        {/* Partner picker */}
        <div>
          <label
            htmlFor="filter-partner"
            className={`${tokens.label.base} sr-only`}
          >
            {t('customer-history-audit:filters.partner')}
          </label>
          <PartnerSearchSelect
            value={partnerIdParam}
            onChange={(id) => { setParam('partner_id', id) }}
            placeholder={t('customer-history-audit:filters.partner')}
          />
        </div>

        {/* Was rejected filter */}
        <div>
          <label
            htmlFor="filter-status"
            className={`${tokens.label.base} sr-only`}
          >
            {t('customer-history-audit:filters.status')}
          </label>
          <select
            id="filter-status"
            value={wasRejectedParam}
            onChange={(e) => { setParam('was_rejected', e.target.value) }}
            className={tokens.select.base}
          >
            <option value="">{t('customer-history-audit:filters.all')}</option>
            <option value="true">{t('customer-history-audit:filters.onlyRejected')}</option>
            <option value="false">{t('customer-history-audit:filters.onlySuccessful')}</option>
          </select>
        </div>

        {/* Date range: from */}
        <div>
          <label
            htmlFor="filter-from"
            className={`${tokens.label.base} sr-only`}
          >
            {t('customer-history-audit:filters.from')}
          </label>
          <input
            id="filter-from"
            type="date"
            value={fromDateParam}
            onChange={(e) => { setParam('from_date', e.target.value) }}
            placeholder={t('customer-history-audit:filters.from')}
            className={tokens.input.base}
          />
        </div>

        {/* Date range: to */}
        <div>
          <label
            htmlFor="filter-to"
            className={`${tokens.label.base} sr-only`}
          >
            {t('customer-history-audit:filters.to')}
          </label>
          <input
            id="filter-to"
            type="date"
            value={toDateParam}
            onChange={(e) => { setParam('to_date', e.target.value) }}
            placeholder={t('customer-history-audit:filters.to')}
            className={tokens.input.base}
          />
        </div>
      </div>

      {/* Error state */}
      {isError && (
        <div role="alert" className={`${tokens.alert.base} ${tokens.alert.error}`}>
          {t('customer-history-audit:errorLoading')}
        </div>
      )}

      {/* Summary chips */}
      {!isLoading && !isError && rows.length > 0 && (
        <SummaryChips total={total} rejectedCount={rejectedCount} />
      )}

      {/* Table */}
      <div className={`overflow-hidden rounded-lg border ${borderColors.light} bg-white shadow-sm`}>
        {isLoading ? (
          <div className="flex justify-center py-12">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16 text-center">
            <ShieldAlert className={`w-12 h-12 ${textColors.disabled} mb-4`} aria-hidden="true" />
            <p className={`text-lg font-medium ${textColors.secondary}`}>
              {t('customer-history-audit:noResults')}
            </p>
            <p className={`text-sm ${textColors.tertiary} mt-1 max-w-sm`}>
              {t('customer-history-audit:empty')}
            </p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className={tokens.table.header}>
                  <tr>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('customer-history-audit:columns.createdAt')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('customer-history-audit:columns.cashier')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('customer-history-audit:columns.terminal')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('customer-history-audit:columns.partner')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('customer-history-audit:columns.results')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('customer-history-audit:columns.status')}
                    </th>
                    <th className={`px-4 py-3 text-left text-xs font-medium ${textColors.tertiary} uppercase tracking-wider`}>
                      {t('customer-history-audit:columns.rejectionReason')}
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {rows.map((row) => (
                    <tr key={row.id} className={tokens.table.rowHover}>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary} whitespace-nowrap`}>
                        {new Date(row.created_at).toLocaleString()}
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary}`}>
                        {row.cashier_name ?? '—'}
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary}`}>
                        {row.terminal_name ?? '—'}
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary}`}>
                        {row.partner_name ?? t('customer-history-audit:noPartnerResolved')}
                      </td>
                      <td className={`px-4 py-3 text-sm ${textColors.secondary}`}>
                        {t('customer-history-audit:columns.results', { count: row.result_count })}
                      </td>
                      <td className="px-4 py-3">
                        {row.was_rejected ? (
                          <RejectedBadge reason={row.rejection_reason} />
                        ) : null}
                      </td>
                      <td className={`px-4 py-3 text-sm ${row.was_rejected ? textColors.error : textColors.tertiary}`}>
                        {row.was_rejected && row.rejection_reason ? row.rejection_reason : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {meta && (
              <Pagination
                hasPrev={meta.current_page > 1}
                hasNext={meta.current_page < meta.last_page}
                onPrev={() => { setPage((p) => Math.max(1, p - 1)) }}
                onNext={() => { setPage((p) => p + 1) }}
                perPage={perPage}
                onPerPageChange={(value) => {
                  setPerPage(value)
                  setPage(1)
                }}
              />
            )}
          </>
        )}
      </div>
    </div>
  )
}
