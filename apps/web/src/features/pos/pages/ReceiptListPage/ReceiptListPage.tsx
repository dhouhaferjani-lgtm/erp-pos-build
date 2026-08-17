import { useMemo } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CalendarDays } from 'lucide-react'
import { Button, Checkbox, Input, Select, StatusBadge } from '@/components/atoms'
import { DataTable, EmptyState, ListPageLayout, type DataTableColumn } from '@/components/molecules'
import { SearchInput } from '@/components/molecules/SearchInput'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useLocation } from '@/hooks/useLocation'
import { useTableState } from '@/hooks/useTableState'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { calendarDateInTimeZone, formatCurrency, formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
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

const NARROWING_FILTER_KEYS = ['receipt_number', 'terminal_id', 'cashier_id', 'fiscal_status'] as const

function stringFilter(filters: Record<string, unknown>, key: string): string {
  return typeof filters[key] === 'string' ? filters[key] : ''
}

export function ReceiptListPage() {
  const { t } = useTranslation(['pos', 'common'])
  const { hasTenantScope } = usePosTenantScope()
  const { scope, effectiveLocationIds } = useViewScope()
  const { hasMultipleLocations } = useLocation()
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const companyTimezone = useCompanyStore((state) => (
    state.companies.find((company) => company.id === currentCompanyId)?.timezone ?? 'UTC'
  ))

  const today = calendarDateInTimeZone(companyTimezone)
  const tableState = useTableState({
    defaultFilters: { from_date: today, to_date: today },
    defaultPerPage: 25,
    syncToURL: true,
  })
  const fromDate = stringFilter(tableState.filters, 'from_date') || today
  const toDate = stringFilter(tableState.filters, 'to_date') || today
  const receiptNumber = stringFilter(tableState.filters, 'receipt_number')
  const terminalId = stringFilter(tableState.filters, 'terminal_id')
  const cashierId = stringFilter(tableState.filters, 'cashier_id')
  const requestedFiscalStatus = stringFilter(tableState.filters, 'fiscal_status')
  const fiscalStatus = FISCAL_STATUSES.find((status) => status === requestedFiscalStatus) ?? ''
  const includeTraining = stringFilter(tableState.filters, 'include_training') === 'true'

  const filters = useMemo<ReceiptListFilters>(() => ({
    location_ids: effectiveLocationIds,
    invoice_type_codes: includeTraining ? ['SALE', 'TRAINING'] : ['SALE'],
    from_date: fromDate,
    to_date: toDate,
    page: tableState.page,
    per_page: tableState.perPage,
    ...(includeTraining ? { include_training: true } : {}),
    ...(receiptNumber ? { receipt_number: receiptNumber } : {}),
    ...(terminalId ? { terminal_id: terminalId } : {}),
    ...(cashierId ? { cashier_id: cashierId } : {}),
    ...(fiscalStatus ? { fiscal_status: fiscalStatus } : {}),
  }), [cashierId, effectiveLocationIds, fiscalStatus, fromDate, includeTraining, receiptNumber, tableState.page, tableState.perPage, terminalId, toDate])

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

  const updateFilter = (key: string, value: string) => {
    if (value) tableState.setFilter(key, value)
    else tableState.removeFilter(key)
  }

  const clearNarrowingFilters = () => {
    NARROWING_FILTER_KEYS.forEach(tableState.removeFilter)
  }

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
      actions={includeTraining ? (
        <StatusBadge tone="warning">{t('pos:receipts.trainingIncluded')}</StatusBadge>
      ) : undefined}
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
              <Input
                type="date"
                value={fromDate}
                onChange={(event) => { updateFilter('from_date', event.target.value) }}
              />
            </label>
            <label className={cn('text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.filters.to')}</span>
              <Input
                type="date"
                value={toDate}
                onChange={(event) => { updateFilter('to_date', event.target.value) }}
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
              onChange={(value) => { updateFilter('receipt_number', value) }}
              placeholder={t('pos:receipts.searchPlaceholder')}
              className="min-w-64 flex-1"
            />
            <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.terminal')}</span>
              <Select value={terminalId} onChange={(event) => { updateFilter('terminal_id', event.target.value) }}>
                <option value="">{t('pos:receipts.filters.allTerminals')}</option>
                {(optionsQuery.data?.terminals ?? []).map((terminal) => (
                  <option key={terminal.id} value={terminal.id}>{terminal.code} — {terminal.name}</option>
                ))}
              </Select>
            </label>
            <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.cashier')}</span>
              <Select value={cashierId} onChange={(event) => { updateFilter('cashier_id', event.target.value) }}>
                <option value="">{t('pos:receipts.filters.allCashiers')}</option>
                {(optionsQuery.data?.cashiers ?? []).map((cashier) => (
                  <option key={cashier.id} value={cashier.id}>{cashier.name}</option>
                ))}
              </Select>
            </label>
            <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
              <span className="mb-1 block">{t('pos:receipts.status')}</span>
              <Select value={fiscalStatus} onChange={(event) => { updateFilter('fiscal_status', event.target.value) }}>
                <option value="">{t('pos:receipts.filters.allStatuses')}</option>
                {FISCAL_STATUSES.map((status) => (
                  <option key={status} value={status}>{t(`pos:receipts.fiscalStatuses.${status}`)}</option>
                ))}
              </Select>
            </label>
            <label className={cn('mb-2 flex items-center gap-2 text-sm font-medium', colorTokens.text.secondary)}>
              <Checkbox
                checked={includeTraining}
                onChange={(event) => { updateFilter('include_training', event.target.checked ? 'true' : '') }}
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
          onPageChange={tableState.setPage}
          onPerPageChange={tableState.setPerPage}
        />
      ) : undefined}
    >
      <DataTable
        columns={columns}
        data={receipts}
        keyExtractor={(receipt) => receipt.id}
        getRowClassName={(receipt) => receipt.training_flag ? colorTokens.surface.page : undefined}
        isLoading={receiptsQuery.isLoading}
        emptyTitle={emptyTitle}
        emptyDescription={emptyDescription}
        emptyState={hasNarrowingFilter ? (
          <EmptyState
            title={emptyTitle}
            description={emptyDescription}
            action={(
              <Button type="button" variant="secondary" onClick={clearNarrowingFilters}>
                {t('common:clearFilters')}
              </Button>
            )}
          />
        ) : undefined}
        ariaLabel={t('pos:receipts.tableLabel')}
      />
    </ListPageLayout>
  )
}
