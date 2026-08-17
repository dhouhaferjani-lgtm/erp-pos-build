import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Clock3 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Input, Select, Spinner, StatusBadge } from '@/components/atoms'
import { DataTable, EmptyState, ListPageLayout, type DataTableColumn } from '@/components/molecules'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { useTableState } from '@/hooks/useTableState'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { calendarDateInTimeZone, formatCurrency, formatDateTime } from '@/lib/format'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { cn } from '@/lib/utils'
import { useCompanyStore } from '@/stores/companyStore'
import {
  fetchReceiptFilterOptions,
  fetchRefundReceipts,
  type ReceiptFilterTerminal,
  type ReceiptListFilters,
  type RefundReceiptListItem,
} from '../../api/receiptApi'
import { ReceiptRegisterTabs } from '../../components/ReceiptRegisterTabs'
import { usePosTenantScope } from '../../hooks/usePosTenantScope'

function stringFilter(filters: Record<string, unknown>, key: string): string {
  return typeof filters[key] === 'string' ? filters[key] : ''
}

function amountMagnitude(value: string): string {
  return value.startsWith('-') ? value.slice(1) : value
}

export function RefundReceiptListPage() {
  const { t } = useTranslation(['pos', 'common'])
  const { hasTenantScope } = usePosTenantScope()
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const activeCompany = useCompanyStore((state) => (
    state.companies.find((company) => company.id === currentCompanyId) ?? null
  ))

  if (!hasTenantScope) {
    return (
      <EmptyState
        title={t('pos:receipts.empty.noScopeTitle')}
        description={t('pos:receipts.empty.noScopeDescription')}
      />
    )
  }

  if (!activeCompany) return <Spinner fullScreen message={t('common:loading')} />

  return <RefundReceiptRegister companyTimezone={activeCompany.timezone} />
}

function RefundReceiptRegister({ companyTimezone }: { companyTimezone: string }) {
  const { t } = useTranslation(['pos', 'common'])
  const { scope, effectiveLocationIds } = useViewScope()
  const today = calendarDateInTimeZone(companyTimezone)
  const tableState = useTableState({
    defaultFilters: { from_date: today, to_date: today },
    defaultPerPage: 25,
    syncToURL: true,
  })
  const fromDate = stringFilter(tableState.filters, 'from_date') || today
  const toDate = stringFilter(tableState.filters, 'to_date') || today
  const terminalId = stringFilter(tableState.filters, 'terminal_id')
  const cashierId = stringFilter(tableState.filters, 'cashier_id')

  const filters = useMemo<ReceiptListFilters>(() => ({
    location_ids: effectiveLocationIds,
    invoice_type_codes: ['REFUND', 'VOID'],
    from_date: fromDate,
    to_date: toDate,
    page: tableState.page,
    per_page: tableState.perPage,
    ...(terminalId ? { terminal_id: terminalId } : {}),
    ...(cashierId ? { cashier_id: cashierId } : {}),
  }), [cashierId, effectiveLocationIds, fromDate, tableState.page, tableState.perPage, terminalId, toDate])

  const optionsQuery = useQuery({
    queryKey: locationScopedKey(['pos', 'receipts', 'refunds', 'filter-options', fromDate, toDate], scope),
    queryFn: () => fetchReceiptFilterOptions({
      location_ids: effectiveLocationIds,
      from_date: fromDate,
      to_date: toDate,
    }),
  })
  const refundsQuery = useQuery({
    queryKey: locationScopedKey(['pos', 'receipts', 'refunds', filters], scope),
    queryFn: () => fetchRefundReceipts(filters),
  })

  const updateFilter = (key: string, value: string) => {
    if (value) tableState.setFilter(key, value)
    else tableState.removeFilter(key)
  }

  const columns = useMemo<DataTableColumn<RefundReceiptListItem>[]>(() => [
    {
      key: 'receipt',
      header: t('pos:receiptReporting.refunds.refundReceipt'),
      render: (row) => (
        <Link to={`/pos/receipts/${row.id}`} className={cn('font-mono font-medium hover:underline', colorTokens.intent.primary.textStrong)}>
          {row.receipt_number}
        </Link>
      ),
    },
    { key: 'date', header: t('pos:receipts.date'), render: (row) => formatDateTime(row.posted_at, { timeZone: companyTimezone }) },
    { key: 'type', header: t('pos:receipts.type'), render: (row) => <StatusBadge tone="warning">{t(`pos:receipts.types.${row.invoice_type_code}`)}</StatusBadge> },
    {
      key: 'original',
      header: t('pos:receiptReporting.refunds.originalReceipt'),
      render: (row) => row.original_receipt_id && row.original_receipt_number ? (
        <Link to={`/pos/receipts/${row.original_receipt_id}`} className={cn('font-mono hover:underline', colorTokens.intent.primary.textStrong)}>
          {row.original_receipt_number}
        </Link>
      ) : t('common:notAvailable'),
    },
    {
      key: 'reason',
      header: t('pos:receiptReporting.refunds.reason'),
      render: (row) => (
        <span title={t(`pos:receiptReporting.refunds.reasonSources.${row.refund_reason_source}`)}>
          {row.refund_reason ?? t('common:notAvailable')}
        </span>
      ),
    },
    { key: 'destination', header: t('pos:receiptReporting.refunds.destination'), render: (row) => row.refund_destination ? t(`pos:receiptReporting.refunds.destinations.${row.refund_destination}`) : t('common:notAvailable') },
    { key: 'location', header: t('pos:receipts.location'), accessor: (row) => row.location_name ?? t('common:notAvailable') },
    { key: 'terminal', header: t('pos:receipts.terminal'), accessor: (row) => row.terminal_code },
    { key: 'cashier', header: t('pos:receipts.cashier'), accessor: (row) => row.cashier_name },
    { key: 'amount', header: t('pos:receiptReporting.refunds.amount'), numeric: true, render: (row) => formatCurrency(amountMagnitude(row.total), { currency: row.currency }) },
    {
      key: 'alerts',
      header: t('pos:receiptReporting.refunds.alerts'),
      render: (row) => row.refund_policy_alerts.length > 0 ? (
        <details>
          <summary className={cn('cursor-pointer text-sm font-medium', colorTokens.intent.warning.textStrong)}>
            {t('pos:receiptReporting.refunds.alertCount', { count: row.refund_policy_alerts.length })}
          </summary>
          <p className={cn('mt-1 text-xs', colorTokens.text.muted)}>{t('pos:receiptReporting.refunds.alertDetails')}</p>
        </details>
      ) : t('common:notAvailable'),
    },
  ], [companyTimezone, t])

  const terminals = optionsQuery.data?.terminals ?? []
  const hasActiveTerminal = terminals.some((terminal) => terminal.v4_refund_authoring_acknowledged_at !== null)
  const refunds = refundsQuery.data?.data ?? []
  const meta = refundsQuery.data?.meta
  const firstRow = meta && meta.total > 0 ? (meta.current_page - 1) * meta.per_page + 1 : null
  const lastRow = firstRow === null ? null : firstRow + refunds.length - 1

  return (
    <ListPageLayout
      title={t('pos:receiptReporting.refunds.title')}
      subtitle={t('pos:receiptReporting.refunds.description')}
      filters={(
        <div className="flex w-full flex-wrap items-end gap-3">
          <label className={cn('text-sm font-medium', colorTokens.text.secondary)}>
            <span className="mb-1 block">{t('pos:receipts.filters.from')}</span>
            <Input type="date" value={fromDate} onChange={(event) => { updateFilter('from_date', event.target.value) }} />
          </label>
          <label className={cn('text-sm font-medium', colorTokens.text.secondary)}>
            <span className="mb-1 block">{t('pos:receipts.filters.to')}</span>
            <Input type="date" value={toDate} onChange={(event) => { updateFilter('to_date', event.target.value) }} />
          </label>
          <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
            <span className="mb-1 block">{t('pos:receipts.terminal')}</span>
            <Select value={terminalId} onChange={(event) => { updateFilter('terminal_id', event.target.value) }}>
              <option value="">{t('pos:receipts.filters.allTerminals')}</option>
              {terminals.map((terminal) => <option key={terminal.id} value={terminal.id}>{terminal.code} — {terminal.name}</option>)}
            </Select>
          </label>
          <label className={cn('min-w-44 text-sm font-medium', colorTokens.text.secondary)}>
            <span className="mb-1 block">{t('pos:receipts.cashier')}</span>
            <Select value={cashierId} onChange={(event) => { updateFilter('cashier_id', event.target.value) }}>
              <option value="">{t('pos:receipts.filters.allCashiers')}</option>
              {(optionsQuery.data?.cashiers ?? []).map((cashier) => <option key={cashier.id} value={cashier.id}>{cashier.name}</option>)}
            </Select>
          </label>
        </div>
      )}
      pagination={hasActiveTerminal && meta ? (
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
      <ReceiptRegisterTabs active="refunds" />
      <section aria-label={t('pos:receiptReporting.refunds.capabilityTitle')} className="mb-5 space-y-2">
        <h2 className={cn('text-sm font-semibold uppercase tracking-wide', colorTokens.text.subtle)}>
          {t('pos:receiptReporting.refunds.capabilityTitle')}
        </h2>
        {optionsQuery.isLoading ? <Spinner message={t('common:loading')} /> : terminals.length > 0 ? terminals.map((terminal) => (
          <CapabilityBanner key={terminal.id} terminal={terminal} companyTimezone={companyTimezone} />
        )) : (
          <div className={cn('rounded-lg border p-4 text-sm', colorTokens.border.subtle, colorTokens.surface.page, colorTokens.text.secondary)}>
            {t('pos:receiptReporting.refunds.noTerminals')}
          </div>
        )}
      </section>

      {hasActiveTerminal ? (
        <DataTable
          columns={columns}
          data={refunds}
          keyExtractor={(row) => row.id}
          isLoading={refundsQuery.isLoading}
          emptyTitle={t('pos:receiptReporting.refunds.emptyTitle')}
          emptyDescription={t('pos:receiptReporting.refunds.emptyDescription')}
          ariaLabel={t('pos:receiptReporting.refunds.tableLabel')}
        />
      ) : null}
    </ListPageLayout>
  )
}

function CapabilityBanner({ terminal, companyTimezone }: { terminal: ReceiptFilterTerminal; companyTimezone: string }) {
  const { t } = useTranslation('pos')
  if (!terminal.v4_refund_authoring_enabled) {
    return (
      <div className={cn('flex items-start gap-3 rounded-lg border p-4', colorTokens.intent.warning.borderSubtle, colorTokens.intent.warning.bgSubtle, colorTokens.intent.warning.textStrongest)}>
        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <p>{t('receiptReporting.refunds.capability.notEnabled', { terminal: terminal.code })}</p>
      </div>
    )
  }

  if (terminal.v4_refund_authoring_acknowledged_at === null) {
    return (
      <div className={cn('flex items-start gap-3 rounded-lg border p-4', colorTokens.intent.primary.borderSubtle, colorTokens.intent.primary.bgSubtle, colorTokens.intent.primary.textStrongest)}>
        <Clock3 className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <p>{t('receiptReporting.refunds.capability.awaiting', { terminal: terminal.code })}</p>
      </div>
    )
  }

  return (
    <div className={cn('flex items-start gap-3 rounded-lg border p-4', colorTokens.intent.success.borderSubtle, colorTokens.intent.success.bgSubtle, colorTokens.intent.success.textStrongest)}>
      <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
      <p>{t('receiptReporting.refunds.capability.active', {
        terminal: terminal.code,
        date: formatDateTime(terminal.v4_refund_authoring_acknowledged_at, { timeZone: companyTimezone }),
      })}</p>
    </div>
  )
}
