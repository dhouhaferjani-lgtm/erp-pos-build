import { useMemo, useState } from 'react'
import { ChevronDown, ChevronRight, FileCheck2 } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/atoms/Button'
import { Input } from '@/components/atoms/Input'
import { StatusBadge } from '@/components/atoms/StatusBadge'
import { EmptyState } from '@/components/molecules/EmptyState'
import { PageHeader } from '@/components/molecules/PageHeader'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { QueryError } from '@/components/QueryError'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { useCurrency, formatAmount } from '@/hooks/useCurrency'
import { useLocation } from '@/hooks/useLocation'
import { usePermissions } from '@/hooks/usePermissions'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import { cn } from '@/lib/utils'
import {
  getAllToBillPartnerRows,
  type ToBillAgingBucket,
  type ToBillDeliveryNote,
  type ToBillPartnerGroup,
  type ToBillQueueParams,
} from '../api/deliveryNotes'
import {
  useConsolidateDeliveryNotes,
  useToBillPartnerRows,
  useToBillQueue,
} from '../hooks/useDeliveryNotes'

const bucketOrder: ToBillAgingBucket[] = ['0_30', '31_60', '61_90', '90_plus']

interface PartnerGroupProps {
  group: ToBillPartnerGroup
  params: ToBillQueueParams
  canCreateInvoice: boolean
  onCreateInvoice: (group: ToBillPartnerGroup) => void
}

function ToBillPartnerGroupCard({
  group,
  params,
  canCreateInvoice,
  onCreateInvoice,
}: PartnerGroupProps) {
  const { t, i18n } = useTranslation('sales')
  const [expanded, setExpanded] = useState(false)
  const [rowPage, setRowPage] = useState(1)
  const rowParams = { ...params, page: rowPage, perPage: 25 }
  const rows = useToBillPartnerRows(group.partner_id, rowParams, expanded)

  const columns = useMemo<DataTableColumn<ToBillDeliveryNote>[]>(() => [
    {
      key: 'number',
      header: t('toBill.columns.number'),
      render: (deliveryNote) => (
        <Link
          to={entityRoutes.document(deliveryNote.id, { documentType: 'delivery_note' })}
          className={cn('font-medium', colorTokens.intent.primary.text, colorTokens.intent.primary.textHoverStrongest)}
        >
          {deliveryNote.document_number}
        </Link>
      ),
    },
    {
      key: 'date',
      header: t('toBill.columns.date'),
      render: (deliveryNote) => new Date(deliveryNote.document_date).toLocaleDateString(
        i18n.resolvedLanguage ?? i18n.language,
      ),
    },
    {
      key: 'total',
      header: t('toBill.columns.total'),
      numeric: true,
      render: (deliveryNote) => formatAmount(deliveryNote.total ?? '0', deliveryNote.currency),
    },
  ], [i18n.language, i18n.resolvedLanguage, t])

  return (
    <article
      aria-label={t('toBill.groupLabel', { partner: group.partner_name })}
      className={cn('overflow-hidden rounded-xl border', colorTokens.border.subtle, colorTokens.surface.base)}
    >
      <div className="flex flex-wrap items-center gap-4 p-4">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => { setExpanded((current) => !current) }}
          aria-expanded={expanded}
          aria-label={expanded
            ? t('toBill.collapse', { partner: group.partner_name })
            : t('toBill.expand', { partner: group.partner_name })}
          className="min-w-0 flex-1 !justify-start gap-3 !p-0 text-start"
        >
          {expanded
            ? <ChevronDown className={cn('h-5 w-5 shrink-0', colorTokens.text.muted)} />
            : <ChevronRight className={cn('h-5 w-5 shrink-0', colorTokens.text.muted)} />}
          <span className="min-w-0">
            <span className={cn('block truncate font-semibold', colorTokens.text.primary)}>
              {group.partner_name}
            </span>
            <span className={cn('block text-sm', colorTokens.text.muted)}>
              {group.partner_code ?? t('toBill.noPartnerCode')}
            </span>
          </span>
        </Button>

        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
          {group.is_periodic ? <StatusBadge tone="info">{t('toBill.periodic')}</StatusBadge> : null}
          <span className={colorTokens.text.muted}>
            {t('toBill.groupCount', { count: group.delivery_note_count })}
          </span>
          <span className={colorTokens.text.muted}>
            {t('toBill.oldest', {
              date: new Date(group.oldest_document_date).toLocaleDateString(
                i18n.resolvedLanguage ?? i18n.language,
              ),
            })}
          </span>
          <strong className={cn('tabular-nums', colorTokens.text.primary)}>
            {formatAmount(group.total, group.currency)}
          </strong>
        </div>

        {canCreateInvoice ? (
          <Button size="sm" onClick={() => { onCreateInvoice(group) }}>
            {t('toBill.createInvoice')}
          </Button>
        ) : null}
      </div>

      {expanded ? (
        <div className={cn('border-t', colorTokens.border.subtle)}>
          {rows.error ? (
            <div className="p-4">
              <QueryError
                error={rows.error}
                onRetry={() => { void rows.refetch() }}
                title={t('toBill.rowsLoadError')}
              />
            </div>
          ) : (
            <>
              {rows.data?.summary ? (
                <p className={cn('px-4 pt-4 text-sm font-medium', colorTokens.text.secondary)}>
                  {t('toBill.reconciliation', {
                    count: rows.data.summary.count,
                    total: formatAmount(rows.data.summary.total, rows.data.summary.currency),
                  })}
                </p>
              ) : null}
              <DataTable
                columns={columns}
                data={rows.data?.data ?? []}
                keyExtractor={(deliveryNote) => deliveryNote.id}
                isLoading={rows.isLoading}
                ariaLabel={t('toBill.rowsLabel', { partner: group.partner_name })}
                emptyTitle={t('toBill.empty.title')}
              />
              {rows.data && rows.data.meta.last_page > 1 ? (
                <OffsetPagination
                  currentPage={rows.data.meta.current_page}
                  lastPage={rows.data.meta.last_page}
                  total={rows.data.meta.total}
                  perPage={rows.data.meta.per_page}
                  from={(rows.data.meta.current_page - 1) * rows.data.meta.per_page + 1}
                  to={Math.min(rows.data.meta.current_page * rows.data.meta.per_page, rows.data.meta.total)}
                  onPageChange={setRowPage}
                  onPerPageChange={() => {}}
                  hidePerPage
                />
              ) : null}
            </>
          )}
        </div>
      ) : null}
    </article>
  )
}

export function ToBillPage() {
  const { t } = useTranslation('sales')
  const { format: formatMoney } = useCurrency()
  const { currentLocationId, isLoading: locationLoading } = useLocation()
  const { hasPermission } = usePermissions()
  const navigate = useNavigate()
  const [partnerSearch, setPartnerSearch] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [periodicOnly, setPeriodicOnly] = useState(false)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [confirmation, setConfirmation] = useState<ToBillPartnerGroup | null>(null)
  const [isPreparingInvoice, setIsPreparingInvoice] = useState(false)
  const consolidation = useConsolidateDeliveryNotes()
  const canCreateInvoice = hasPermission('invoices.create')
  const params: ToBillQueueParams = {
    locationId: currentLocationId,
    partnerSearch,
    dateFrom,
    dateTo,
    periodicOnly,
    page,
    perPage,
  }
  const query = useToBillQueue(params)

  const changeFilter = (setter: (value: string) => void, value: string) => {
    setter(value)
    setPage(1)
  }

  const confirmCreate = async () => {
    if (confirmation === null) return

    setIsPreparingInvoice(true)
    try {
      const deliveryNotes = await getAllToBillPartnerRows(confirmation.partner_id, params)
      const response = await consolidation.mutateAsync(deliveryNotes.map((deliveryNote) => deliveryNote.id))
      setConfirmation(null)
      void navigate(entityRoutes.document(response.data.id, { documentType: 'invoice' }))
    } finally {
      setIsPreparingInvoice(false)
    }
  }

  return (
    <div className="space-y-6 p-6">
      <PageHeader title={t('toBill.title')} subtitle={t('toBill.subtitle')} />

      <section
        aria-label={t('toBill.agingLabel')}
        className={cn('rounded-xl border p-4', colorTokens.border.subtle, colorTokens.surface.base)}
      >
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          {bucketOrder.map((bucket) => {
            const summary = query.data?.summary.buckets.find((item) => item.bucket === bucket)
            return (
              <div key={bucket} className={cn('rounded-lg p-3', colorTokens.surface.muted)}>
                <p className={cn('text-xs font-medium uppercase tracking-wide', colorTokens.text.muted)}>
                  {t(`toBill.aging.${bucket}`)}
                </p>
                <p className={cn('mt-1 text-lg font-semibold tabular-nums', colorTokens.text.primary)}>
                  {formatMoney(summary?.total ?? '0')}
                </p>
                <p className={cn('text-xs', colorTokens.text.muted)}>
                  {t('toBill.groupCount', { count: summary?.count ?? 0 })}
                </p>
              </div>
            )
          })}
          <div className={cn('rounded-lg p-3', colorTokens.intent.primary.bgSubtleAlphaLight)}>
            <p className={cn('text-xs font-medium uppercase tracking-wide', colorTokens.intent.primary.text)}>
              {t('toBill.grandTotal')}
            </p>
            <p className={cn('mt-1 text-lg font-semibold tabular-nums', colorTokens.intent.primary.text)}>
              {formatMoney(query.data?.summary.grand_total ?? '0')}
            </p>
            <p className={cn('text-xs', colorTokens.text.muted)}>
              {t('toBill.groupCount', { count: query.data?.summary.grand_count ?? 0 })}
            </p>
          </div>
        </div>
      </section>

      <section className={cn('rounded-xl border p-4', colorTokens.border.subtle, colorTokens.surface.base)}>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          <label className={cn('space-y-1 text-sm font-medium', colorTokens.text.secondary)}>
            <span>{t('toBill.filters.search')}</span>
            <Input
              type="search"
              aria-label={t('toBill.filters.search')}
              value={partnerSearch}
              onChange={(event) => { changeFilter(setPartnerSearch, event.target.value) }}
            />
          </label>
          <label className={cn('space-y-1 text-sm font-medium', colorTokens.text.secondary)}>
            <span>{t('toBill.filters.dateFrom')}</span>
            <Input
              type="date"
              value={dateFrom}
              onChange={(event) => { changeFilter(setDateFrom, event.target.value) }}
            />
          </label>
          <label className={cn('space-y-1 text-sm font-medium', colorTokens.text.secondary)}>
            <span>{t('toBill.filters.dateTo')}</span>
            <Input
              type="date"
              value={dateTo}
              onChange={(event) => { changeFilter(setDateTo, event.target.value) }}
            />
          </label>
          <div className="flex items-end">
            <Button
              variant={periodicOnly ? 'primary' : 'secondary'}
              aria-pressed={periodicOnly}
              onClick={() => {
                setPeriodicOnly((current) => !current)
                setPage(1)
              }}
            >
              {t('toBill.filters.periodicOnly')}
            </Button>
          </div>
        </div>
      </section>

      <p className={cn('whitespace-pre-line text-sm', colorTokens.text.muted)}>
        {t('deliveryNotes.partnerTab.coexistence')}
      </p>

      {query.error ? (
        <QueryError error={query.error} onRetry={() => { void query.refetch() }} title={t('toBill.loadError')} />
      ) : query.isLoading || locationLoading ? (
        <div className={cn('h-40 animate-pulse rounded-xl', colorTokens.surface.subdued)} />
      ) : query.data?.data.length === 0 ? (
        <EmptyState
          title={t('toBill.empty.title')}
          description={t('toBill.empty.description')}
          icon={<FileCheck2 className={cn('h-16 w-16', colorTokens.intent.success.text)} />}
        />
      ) : (
        <div className="space-y-3">
          {query.data?.data.map((group) => (
            <ToBillPartnerGroupCard
              key={group.partner_id}
              group={group}
              params={params}
              canCreateInvoice={canCreateInvoice}
              onCreateInvoice={setConfirmation}
            />
          ))}
        </div>
      )}

      {query.data && query.data.meta.last_page > 1 ? (
        <OffsetPagination
          currentPage={query.data.meta.current_page}
          lastPage={query.data.meta.last_page}
          total={query.data.meta.total}
          perPage={query.data.meta.per_page}
          from={(query.data.meta.current_page - 1) * query.data.meta.per_page + 1}
          to={Math.min(query.data.meta.current_page * query.data.meta.per_page, query.data.meta.total)}
          onPageChange={setPage}
          onPerPageChange={(nextPerPage) => { setPerPage(nextPerPage); setPage(1) }}
        />
      ) : null}

      <ConfirmDialog
        isOpen={confirmation !== null}
        onClose={() => { setConfirmation(null) }}
        onConfirm={() => { void confirmCreate() }}
        title={t('toBill.confirm.title', { partner: confirmation?.partner_name ?? '' })}
        message={t('toBill.confirm.message', {
          count: confirmation?.delivery_note_count ?? 0,
          total: confirmation ? formatAmount(confirmation.total, confirmation.currency) : '',
        })}
        confirmText={t('toBill.createInvoice')}
        variant="info"
        isLoading={isPreparingInvoice || consolidation.isPending}
      />
    </div>
  )
}
