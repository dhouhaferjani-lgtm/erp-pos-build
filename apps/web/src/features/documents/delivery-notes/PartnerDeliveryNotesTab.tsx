import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/atoms/Button'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { FilterTabs } from '@/components/molecules/FilterTabs'
import { QueryError } from '@/components/QueryError'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { useCurrency } from '@/hooks/useCurrency'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import type { DeliveryNote, PartnerDeliveryNoteFilter } from '../api/deliveryNotes'
import { parseDeliveryNoteBillingRefusal, type DeliveryNoteBillingRefusal } from '../deliveryNoteBillingRefusal'
import { useConsolidateDeliveryNotes, usePartnerDeliveryNotes } from '../hooks/useDeliveryNotes'

interface PartnerDeliveryNotesTabProps {
  partnerId: string
  canCreateInvoice: boolean
}

interface PartnerUnbilledBalanceLineProps {
  partnerId: string
}

const laneTones: Record<string, StatusTone> = {
  consolidation: 'info',
  order_conversion: 'success',
  pre_post_delivery: 'info',
  legacy_unknown: 'neutral',
}

function billingLaneKey(lane: string | null): string {
  switch (lane) {
    case 'consolidation':
      return 'consolidation'
    case 'order_conversion':
      return 'orderConversion'
    case 'pre_post_delivery':
      return 'prePostDelivery'
    case 'legacy_unknown':
      return 'legacyUnknown'
    default:
      return 'unknown'
  }
}

export function DeliveryNoteBillingStatus({
  deliveryNote,
}: {
  deliveryNote: Pick<DeliveryNote,
    | 'invoiced_at'
    | 'invoiced_by_document_id'
    | 'invoiced_by_document_number'
    | 'invoiced_via'
  >
}) {
  const { t } = useTranslation('sales')

  if (deliveryNote.invoiced_at === null) {
    return <StatusBadge tone="pending">{t('deliveryNotes.partnerTab.billingState.uninvoiced')}</StatusBadge>
  }

  const lane = deliveryNote.invoiced_via
  const badge = (
    <StatusBadge tone={lane === null ? 'neutral' : (laneTones[lane] ?? 'neutral')}>
      {t(`deliveryNotes.partnerTab.invoicedVia.${billingLaneKey(lane)}`)}
    </StatusBadge>
  )

  if (
    deliveryNote.invoiced_by_document_id === null ||
    deliveryNote.invoiced_by_document_number === null
  ) {
    return badge
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {badge}
      <Link
        to={`/sales/invoices/${deliveryNote.invoiced_by_document_id}`}
        className={`font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrongest}`}
      >
        {deliveryNote.invoiced_by_document_number}
      </Link>
    </div>
  )
}

export function PartnerDeliveryNotesTab({
  partnerId,
  canCreateInvoice,
}: PartnerDeliveryNotesTabProps) {
  const { t } = useTranslation('sales')
  const { format: formatMoney } = useCurrency()
  const navigate = useNavigate()
  const [filter, setFilter] = useState<PartnerDeliveryNoteFilter>('uninvoiced')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())
  const [billingRefusal, setBillingRefusal] = useState<DeliveryNoteBillingRefusal | null>(null)
  const consolidation = useConsolidateDeliveryNotes()
  const query = usePartnerDeliveryNotes({ partnerId, filter, page, perPage })
  const deliveryNotes = query.data?.data ?? []
  const refusedIds = useMemo(
    () => new Set(billingRefusal?.documents.map((document) => document.id) ?? []),
    [billingRefusal],
  )
  const refusalById = useMemo(
    () => new Map(billingRefusal?.documents.map((document) => [document.id, document]) ?? []),
    [billingRefusal],
  )

  const submit = async (ids: string[]) => {
    try {
      const response = await consolidation.mutateAsync(ids)
      setBillingRefusal(null)
      setSelectedIds(new Set())
      void navigate(`/sales/invoices/${response.data.id}`)
    } catch (error) {
      const refusal = parseDeliveryNoteBillingRefusal(error)
      if (refusal !== null) {
        setBillingRefusal(refusal)
      }
    }
  }

  const columns = useMemo<DataTableColumn<DeliveryNote>[]>(() => [
    {
      key: 'document_number',
      header: t('deliveryNotes.partnerTab.columns.number'),
      render: (deliveryNote) => (
        <Link
          to={`/inventory/delivery-notes/${deliveryNote.id}`}
          className={`font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrongest}`}
        >
          {deliveryNote.document_number}
        </Link>
      ),
    },
    {
      key: 'document_date',
      header: t('deliveryNotes.partnerTab.columns.date'),
      render: (deliveryNote) => new Date(deliveryNote.document_date).toLocaleDateString(),
    },
    {
      key: 'total',
      header: t('deliveryNotes.partnerTab.columns.total'),
      numeric: true,
      render: (deliveryNote) => formatMoney(deliveryNote.total ?? '0'),
    },
    {
      key: 'billing_state',
      header: t('deliveryNotes.partnerTab.columns.billingState'),
      render: (deliveryNote) => {
        const refusal = refusalById.get(deliveryNote.id)
        if (refusal === undefined) {
          return <DeliveryNoteBillingStatus deliveryNote={deliveryNote} />
        }

        return (
          <span
            data-testid={`delivery-note-refused-${deliveryNote.id}`}
            className="flex flex-wrap items-center gap-2"
          >
            <StatusBadge tone="danger">
              {t('deliveryNotes.partnerTab.billingState.refused')}
            </StatusBadge>
            <DeliveryNoteBillingStatus deliveryNote={{
              invoiced_at: refusal.invoice_date ?? 'attributed',
              invoiced_by_document_id: refusal.invoice_id,
              invoiced_by_document_number: refusal.invoice_number,
              invoiced_via: refusal.invoiced_via,
            }} />
          </span>
        )
      },
    },
  ], [formatMoney, refusalById, t])

  const toggle = (id: string) => {
    setSelectedIds((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const selectablePageIds = deliveryNotes
    .filter((deliveryNote) => deliveryNote.invoiced_at === null)
    .map((deliveryNote) => deliveryNote.id)
  const toggleAll = () => {
    setSelectedIds((current) => {
      const next = new Set(current)
      const pageSelected = selectablePageIds.every((id) => next.has(id))
      for (const id of selectablePageIds) {
        if (pageSelected) next.delete(id)
        else next.add(id)
      }
      return next
    })
  }

  const changeFilter = (nextFilter: PartnerDeliveryNoteFilter) => {
    setFilter(nextFilter)
    setPage(1)
    setSelectedIds(new Set())
    setBillingRefusal(null)
  }

  const removeAndRetry = async () => {
    const remaining = Array.from(selectedIds).filter((id) => !refusedIds.has(id))
    setSelectedIds(new Set(remaining))
    setBillingRefusal(null)
    if (remaining.length > 0) await submit(remaining)
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <FilterTabs
          tabs={[
            { value: 'uninvoiced', label: t('deliveryNotes.partnerTab.filters.uninvoiced') },
            { value: 'invoiced', label: t('deliveryNotes.partnerTab.filters.invoiced') },
            { value: 'all', label: t('deliveryNotes.partnerTab.filters.all') },
          ]}
          value={filter}
          onChange={changeFilter}
        />
        {canCreateInvoice ? (
          <Button
            onClick={() => { void submit(Array.from(selectedIds)) }}
            disabled={selectedIds.size === 0 || consolidation.isPending}
          >
            {t('deliveryNotes.partnerTab.createInvoice', { count: selectedIds.size })}
          </Button>
        ) : null}
      </div>

      <p className={`text-sm ${colorTokens.text.muted}`}>
        {t('deliveryNotes.partnerTab.coexistence')}
      </p>

      {query.data?.aggregates !== undefined ? (
        <div className={`flex flex-wrap items-baseline justify-between gap-3 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.intent.primary.bgSubtleAlphaLight} px-4 py-3`}>
          <span className={`text-sm font-medium ${colorTokens.intent.primary.text}`}>
            {filter === 'uninvoiced'
              ? t('deliveryNotes.partnerTab.unbilledLine.label')
              : t('deliveryNotes.partnerTab.summaryLabel')}
            <span className={`ms-1 font-normal ${colorTokens.text.muted}`}>
              {t('deliveryNotes.partnerTab.unbilledLine.count', {
                count: query.data.aggregates.count,
              })}
            </span>
          </span>
          <span className={`font-semibold tabular-nums ${colorTokens.text.primary}`}>
            {formatMoney(query.data.aggregates.total)}
          </span>
        </div>
      ) : null}

      {billingRefusal !== null ? (
        <div
          role="alert"
          className={`rounded-lg border ${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtle} p-4`}
        >
          <p className={`font-medium ${colorTokens.intent.danger.textStrong}`}>
            {t('deliveryNotes.consolidation.billingRefusal.title')}
          </p>
          <p className={`mt-1 text-sm ${colorTokens.intent.danger.text}`}>
            {t('deliveryNotes.consolidation.billingRefusal.guarantee')}
          </p>
          <Button
            variant="dangerOutline"
            size="sm"
            className="mt-3"
            onClick={() => { void removeAndRetry() }}
            disabled={consolidation.isPending}
          >
            {t('deliveryNotes.consolidation.billingRefusal.removeAndRetry', {
              count: billingRefusal.documents.length,
            })}
          </Button>
        </div>
      ) : null}

      {query.isError ? (
        <QueryError error={query.error} onRetry={() => { void query.refetch() }} />
      ) : (
        <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base}`}>
          <DataTable
            ariaLabel={t('deliveryNotes.partnerTab.tableLabel')}
            columns={columns}
            data={deliveryNotes}
            keyExtractor={(deliveryNote) => deliveryNote.id}
            isLoading={query.isLoading}
            emptyTitle={t('deliveryNotes.partnerTab.empty.title')}
            emptyDescription={t('deliveryNotes.partnerTab.empty.description')}
            getRowClassName={(deliveryNote) =>
              deliveryNote.invoiced_at !== null
                ? `opacity-60 ${colorTokens.surface.muted}`
                : refusedIds.has(deliveryNote.id)
                  ? colorTokens.intent.danger.bgSubtle
                  : undefined
            }
            selection={{
              selectedIds,
              onToggle: toggle,
              onToggleAll: toggleAll,
              isRowSelectable: (deliveryNote) => deliveryNote.invoiced_at === null,
              getRowLabel: (deliveryNote) => t('deliveryNotes.partnerTab.selectRow', {
                number: deliveryNote.document_number,
              }),
              selectAllLabel: t('deliveryNotes.partnerTab.selectAll'),
            }}
          />
          {query.data?.meta && query.data.meta.last_page > 1 ? (
            <OffsetPagination
              currentPage={query.data.meta.current_page}
              lastPage={query.data.meta.last_page}
              total={query.data.meta.total}
              perPage={query.data.meta.per_page}
              from={query.data.meta.from}
              to={query.data.meta.to}
              onPageChange={setPage}
              onPerPageChange={(nextPerPage) => {
                setPerPage(nextPerPage)
                setPage(1)
              }}
            />
          ) : null}
        </div>
      )}
    </div>
  )
}

export function PartnerUnbilledBalanceLine({ partnerId }: PartnerUnbilledBalanceLineProps) {
  const { t } = useTranslation('sales')
  const { format: formatMoney } = useCurrency()
  const query = usePartnerDeliveryNotes({
    partnerId,
    filter: 'uninvoiced',
    page: 1,
    perPage: 10,
  })
  const aggregates = query.data?.aggregates

  if (aggregates === undefined) return null

  return (
    <div className={`border-t ${colorTokens.border.hairline} pt-3`}>
      <Link
        to="?tab=delivery-notes"
        className={`flex items-start justify-between gap-4 rounded-md px-2 py-2 ${colorTokens.intent.primary.bgSubtleAlphaLight} ${colorTokens.intent.neutral.bgHover}`}
      >
        <span className={`text-sm font-medium ${colorTokens.intent.primary.text}`}>
          {t('deliveryNotes.partnerTab.unbilledLine.label')}
          <span className={`ms-1 font-normal ${colorTokens.text.muted}`}>
            {t('deliveryNotes.partnerTab.unbilledLine.count', { count: aggregates.count })}
          </span>
        </span>
        <span className={`text-sm font-semibold tabular-nums ${colorTokens.text.primary}`}>
          {formatMoney(aggregates.total)}
        </span>
      </Link>
    </div>
  )
}
