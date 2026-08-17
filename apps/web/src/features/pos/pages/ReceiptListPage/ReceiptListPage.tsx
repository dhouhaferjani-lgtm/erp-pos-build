import { useCallback, useMemo } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CalendarDays } from 'lucide-react'
import { Checkbox, Select, StatusBadge } from '@/components/atoms'
import { DataTable, ListPageLayout, type DataTableColumn } from '@/components/molecules'
import { SearchInput } from '@/components/molecules/SearchInput'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useLocation } from '@/hooks/useLocation'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { formatCurrency, formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens, tokens } from '@/lib/designTokens'
import { useCompanyStore } from '@/stores/companyStore'
import {
  fetchReceiptFilterOptions,
  fetchReceipts,
  type ReceiptFiscalStatus,
  type ReceiptListFilters,
  type ReceiptListItem,
} from '../../api/receiptApi'
import { usePosTenantScope } from '../../hooks/usePosTenantScope'

const FISCAL_STATUSES: ReceiptFiscalStatus[] = [
  'pending_seal',
  'fiscalized',
  'voided',
  'pending_sync',
  'synced',
  'sync_failed',
]

export function calendarDateInTimeZone(timeZone: string, date: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date)
  const value = (type: Intl.DateTimeFormatPartTypes) => parts.find((part) => part.type === type)?.value ?? ''
  return `${value('year')}-${value('month')}-${value('day')}`
}

function positiveInteger(value: string | null, fallback: number): number {
  if (!value) return fallback
  const parsed = Number(value)
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback
}

export function ReceiptListPage() {
  const { t } = useTranslation(['pos', 'common'])
  const [searchParams, setSearchParams] = useSearchParams()
  const { hasTenantScope } = usePosTenantScope()
  const { scope, effectiveLocationIds } = useViewScope()
  const { hasMultipleLocations } = useLocation()
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const companyTimezone = useCompanyStore((state) => (
    state.companies.find((company) => company.id === currentCompanyId)?.timezone ?? 'UTC'
  ))

  const today = calendarDateInTimeZone(companyTimezone)
  const fromDate = searchParams.get('from_date') ?? today
  const toDate = searchParams.get('to_date') ?? today
  const receiptNumber = searchParams.get('receipt_number') ?? ''
  const terminalId = searchParams.get('terminal_id') ?? ''
  const cashierId = searchParams.get('cashier_id') ?? ''
  const fiscalStatus = (searchParams.get('fiscal_status') ?? '') as ReceiptFiscalStatus | ''
  const includeTraining = searchParams.get('include_training') === 'true'
  const page = positiveInteger(searchParams.get('page'), 1)
  const perPage = positiveInteger(searchParams.get('per_page'), 25)

  const filters = useMemo<ReceiptListFilters>(() => ({
    location_ids: effectiveLocationIds,
    invoice_type_codes: includeTraining ? ['SALE', 'TRAINING'] : ['SALE'],
    include_training: includeTraining,
    from_date: fromDate,
    to_date: toDate,
    page,
    per_page: perPage,
    ...(receiptNumber ? { receipt_number: receiptNumber } : {}),
    ...(terminalId ? { terminal_id: terminalId } : {}),
    ...(cashierId ? { cashier_id: cashierId } : {}),
    ...(fiscalStatus ? { fiscal_status: fiscalStatus } : {}),
  }), [cashierId, effectiveLocationIds, fiscalStatus, fromDate, includeTraining, page, perPage, receiptNumber, terminalId, toDate])

  const optionsQuery = useQuery({
    queryKey: locationScopedKey(['pos', 'receipts', 'filter-options', fromDate, toDate], scope),
    queryFn: () => fetchReceiptFilterOptions({
      location_ids: effectiveLocationIds,
      from_date: fromDate,
      to_date: toDate,
    }),
    enabled: hasTenantScope,
  })

  const receiptsQuery = useQuery({
    queryKey: locationScopedKey(['pos', 'receipts', filters], scope),
    queryFn: () => fetchReceipts(filters),
    enabled: hasTenantScope,
  })

  const updateParam = useCallback((key: string, value: string) => {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.set('page', '1')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  const columns = useMemo<DataTableColumn<ReceiptListItem>[]>(() => {
    const result: DataTableColumn<ReceiptListItem>[] = [
      {
        key: 'receipt_number',
        header: t('pos:receipts.receiptNumber'),
        render: (receipt) => (
          <Link
            to={`/pos/receipts/${receipt.id}`}
            className={cn('font-mono font-medium hover:underline', colorTokens.intent.primary.textStrong)}
          >
            {receipt.receipt_number}
          </Link>
        ),
      },
      {
        key: 'posted_at',
        header: t('pos:receipts.date'),
        render: (receipt) => formatDateTime(receipt.posted_at, { timeZone: companyTimezone }),
      },
      {
        key: 'type',
        header: t('pos:receipts.type'),
        render: (receipt) => receipt.invoice_type_code === 'SALE' ? null : (
          <StatusBadge tone="warning">{t(`pos:receipts.types.${receipt.invoice_type_code}`)}</StatusBadge>
        ),
      },
    ]

    if (hasMultipleLocations) {
      result.push({
        key: 'location',
        header: t('pos:receipts.location'),
        render: (receipt) => receipt.location_name ?? t('common:notAvailable'),
      })
    }

    result.push(
      {
        key: 'terminal',
        header: t('pos:receipts.terminal'),
        accessor: (receipt) => receipt.terminal_code,
      },
      {
        key: 'cashier',
        header: t('pos:receipts.cashier'),
        accessor: (receipt) => receipt.cashier_name,
      },
      {
        key: 'total',
        header: t('pos:receipts.total'),
        numeric: true,
        render: (receipt) => formatCurrency(receipt.total, { currency: receipt.currency }),
      },
    )

    return result
  }, [companyTimezone, hasMultipleLocations, t])

  const receipts = receiptsQuery.data?.data ?? []
  const meta = receiptsQuery.data?.meta
  const hasNarrowingFilter = Boolean(receiptNumber || terminalId || cashierId || fiscalStatus)
  const emptyTitle = includeTraining && !hasNarrowingFilter
    ? t('pos:receipts.empty.trainingTitle')
    : hasNarrowingFilter
      ? t('pos:receipts.empty.filteredTitle')
      : t('pos:receipts.empty.dayTitle')
  const emptyDescription = includeTraining && !hasNarrowingFilter
    ? t('pos:receipts.empty.trainingDescription')
    : hasNarrowingFilter
      ? t('pos:receipts.empty.filteredDescription')
      : t('pos:receipts.empty.dayDescription')
  const firstRow = meta && meta.total > 0 ? (meta.current_page - 1) * meta.per_page + 1 : null
  const lastRow = firstRow === null ? null : firstRow + receipts.length - 1

  return (
    <ListPageLayout
      title={t('pos:receipts.title')}
      subtitle={t('pos:receipts.description')}
      filters={(
        <div className="w-full space-y-3">
          <div className={cn(
            'flex flex-wrap items-end gap-3 rounded-lg border p-3',
            colorTokens.intent.primary.bgSubtleAlpha,
            colorTokens.intent.primary.borderSubtle,
          )}>
            <CalendarDays className={cn('mb-2 h-5 w-5', colorTokens.intent.primary.text)} aria-hidden="true" />
            <label className={cn('text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.filters.from')}</span>
              <input
                type="date"
                value={fromDate}
                onChange={(event) => { updateParam('from_date', event.target.value) }}
                className={tokens.input.base}
              />
            </label>
            <label className={cn('text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.filters.to')}</span>
              <input
                type="date"
                value={toDate}
                onChange={(event) => { updateParam('to_date', event.target.value) }}
                className={tokens.input.base}
              />
            </label>
            {meta?.from && meta.to ? (
              <p className={cn('mb-2 text-xs', colorTokens.text.muted)}>
                {t('pos:receipts.resolvedWindow', {
                  from: formatDateTime(meta.from, { timeZone: companyTimezone }),
                  to: formatDateTime(meta.to, { timeZone: companyTimezone }),
                })}
              </p>
            ) : null}
          </div>

          <div className="flex flex-wrap items-end gap-3">
            <SearchInput
              value={receiptNumber}
              onChange={(value) => { updateParam('receipt_number', value) }}
              placeholder={t('pos:receipts.searchPlaceholder')}
              className="min-w-64 flex-1"
            />
            <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.terminal')}</span>
              <Select value={terminalId} onChange={(event) => { updateParam('terminal_id', event.target.value) }}>
                <option value="">{t('pos:receipts.filters.allTerminals')}</option>
                {(optionsQuery.data?.terminals ?? []).map((terminal) => (
                  <option key={terminal.id} value={terminal.id}>{terminal.code} — {terminal.name}</option>
                ))}
              </Select>
            </label>
            <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.cashier')}</span>
              <Select value={cashierId} onChange={(event) => { updateParam('cashier_id', event.target.value) }}>
                <option value="">{t('pos:receipts.filters.allCashiers')}</option>
                {(optionsQuery.data?.cashiers ?? []).map((cashier) => (
                  <option key={cashier.id} value={cashier.id}>{cashier.name}</option>
                ))}
              </Select>
            </label>
            <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.status')}</span>
              <Select value={fiscalStatus} onChange={(event) => { updateParam('fiscal_status', event.target.value) }}>
                <option value="">{t('pos:receipts.filters.allStatuses')}</option>
                {FISCAL_STATUSES.map((status) => (
                  <option key={status} value={status}>{t(`pos:receipts.fiscalStatuses.${status}`)}</option>
                ))}
              </Select>
            </label>
            <label className={cn('mb-2 flex items-center gap-2 text-sm font-medium', colorTokens.text.secondary)}>
              <Checkbox
                checked={includeTraining}
                onChange={(event) => { updateParam('include_training', event.target.checked ? 'true' : '') }}
                aria-label={t('pos:receipts.includeTraining')}
              />
              {t('pos:receipts.includeTraining')}
            </label>
          </div>
        </div>
      )}
      pagination={meta ? (
        <OffsetPagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          perPage={meta.per_page}
          from={firstRow}
          to={lastRow}
          onPageChange={(value) => { updateParam('page', String(value)) }}
          onPerPageChange={(value) => { updateParam('per_page', String(value)) }}
        />
      ) : undefined}
    >
      <DataTable
        columns={columns}
        data={receipts}
        keyExtractor={(receipt) => receipt.id}
        isLoading={receiptsQuery.isLoading}
        emptyTitle={emptyTitle}
        emptyDescription={emptyDescription}
        ariaLabel={t('pos:receipts.tableLabel')}
      />
    </ListPageLayout>
  )
}
