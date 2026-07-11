import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Calendar, FileCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'

import { Input, Select, StatusBadge, statusTone, type StatusTone } from '@/components/atoms'
import {
  DataTable,
  EmptyState,
  ListPageLayout,
  type DataTableColumn,
} from '@/components/molecules'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { api } from '@/lib/api'
import { tokens, textColors } from '@/lib/designTokens'
import { entityRoutes } from '@/lib/entityRoutes'
import { formatCurrency } from '@/lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

type InstrumentStatus =
  | 'received'
  | 'in_transit'
  | 'deposited'
  | 'clearing'
  | 'cleared'
  | 'bounced'
  | 'expired'
  | 'cancelled'
  | 'collected'

type InstrumentKind = 'cheque' | 'effet' | 'other' | null
type InstrumentDirection = 'inbound' | 'outbound'

interface InstrumentRelation {
  id: string
  code?: string
  name: string
}

interface Instrument {
  id: string
  reference: string
  partner_id: string | null
  partner: InstrumentRelation | null
  drawer_name: string | null
  amount: string
  currency: string
  received_date: string
  maturity_date: string | null
  status: InstrumentStatus
  kind: InstrumentKind
  direction: InstrumentDirection
  needs_details: boolean
  repository_id: string | null
  repository: InstrumentRelation | null
}

interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

interface InstrumentsResponse {
  data: Instrument[]
  meta: PaginationMeta
}

type BucketKey = 'overdue' | 'd0_7' | 'd8_30' | 'd31_60' | 'd61_90' | 'd90_plus'

interface BucketTotal {
  count: number
  total_in: string
  total_out: string
}

interface MaturityResponse {
  data: unknown[]
  meta: {
    buckets: Record<BucketKey, BucketTotal>
    grand_total: BucketTotal
  }
}

const instrumentStatusTones: Record<string, StatusTone> = {
  received: 'pending',
  deposited: 'info',
  bounced: 'danger',
}

const kindTones: Record<NonNullable<InstrumentKind>, StatusTone> = {
  cheque: 'info',
  effet: 'warning',
  other: 'neutral',
}

const bucketKeys: BucketKey[] = ['overdue', 'd0_7', 'd8_30', 'd31_60', 'd61_90', 'd90_plus']

export function InstrumentListPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const [searchParams] = useSearchParams()
  const [kind, setKind] = useState(() => searchParams.get('kind') ?? '')
  const [direction, setDirection] = useState('')
  const [needsDetails, setNeedsDetails] = useState('')
  const [maturityFrom, setMaturityFrom] = useState(() => searchParams.get('maturity_from') ?? '')
  const [maturityTo, setMaturityTo] = useState(() => searchParams.get('maturity_to') ?? '')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'
  const filterKey = { direction, kind, maturityFrom, maturityTo, needsDetails, page, perPage }

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['instruments', filterKey]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (kind) params.set('kind', kind)
      if (direction) params.set('direction', direction)
      if (needsDetails) params.set('needs_details', needsDetails)
      if (maturityFrom) params.set('maturity_from', maturityFrom)
      if (maturityTo) params.set('maturity_to', maturityTo)
      params.set('page', String(page))
      params.set('per_page', String(perPage))
      const response = await api.get<InstrumentsResponse>(`/payment-instruments?${params.toString()}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const maturityFilterKey = { direction, kind, maturityFrom, maturityTo, needsDetails }
  const { data: maturityData } = useQuery({
    queryKey: tenantScopedKey(['maturing-instruments', maturityFilterKey]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (kind) params.set('kind', kind)
      if (direction) params.set('direction', direction)
      if (needsDetails) params.set('needs_details', needsDetails)
      if (maturityFrom) params.set('from', maturityFrom)
      if (maturityTo) params.set('to', maturityTo)
      const suffix = params.toString()
      const response = await api.get<MaturityResponse>(
        `/treasury/maturing-instruments${suffix ? `?${suffix}` : ''}`,
      )
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const instruments = data?.data ?? []
  const meta = data?.meta ?? { current_page: 1, last_page: 1, per_page: perPage, total: instruments.length }

  function resetPage() {
    setPage(1)
  }

  function formatAmount(amount: string) {
    return formatCurrency(amount, { currency: companyCurrency, locale: companyLocale })
  }

  const columns: DataTableColumn<Instrument>[] = [
    {
      key: 'reference',
      header: t('treasury:instruments.number'),
      render: (instrument) => (
        <Link
          to={`/treasury/instruments/${instrument.id}`}
          className={cn('font-medium hover:underline', textColors.primary)}
        >
          {instrument.reference}
        </Link>
      ),
    },
    {
      key: 'kind',
      header: t('treasury:instruments.kind'),
      render: (instrument) => instrument.kind ? (
        <StatusBadge tone={kindTones[instrument.kind]}>
          {t(`treasury:instruments.kinds.${instrument.kind}`)}
        </StatusBadge>
      ) : <span className={textColors.tertiary}>—</span>,
    },
    {
      key: 'direction',
      header: t('treasury:instruments.direction'),
      render: (instrument) => (
        <StatusBadge tone={instrument.direction === 'inbound' ? 'success' : 'neutral'}>
          {t(`treasury:instruments.directions.${instrument.direction}`)}
        </StatusBadge>
      ),
    },
    {
      key: 'partner',
      header: t('treasury:instruments.partner'),
      render: (instrument) => instrument.partner ? (
        <Link to={entityRoutes.customer(instrument.partner.id)} className={cn(textColors.brand, 'hover:underline')}>
          {instrument.partner.name}
        </Link>
      ) : <span className={textColors.tertiary}>{instrument.drawer_name ?? '—'}</span>,
    },
    {
      key: 'repository',
      header: t('treasury:instruments.repository'),
      render: (instrument) => instrument.repository ? (
        <Link to={`/treasury/repositories/${instrument.repository.id}`} className={cn(textColors.brand, 'hover:underline')}>
          {instrument.repository.name}
        </Link>
      ) : <span className={textColors.tertiary}>—</span>,
    },
    {
      key: 'received',
      header: t('treasury:instruments.receivedDate'),
      render: (instrument) => (
        <span className={textColors.tertiary}>
          {new Date(instrument.received_date).toLocaleDateString(companyLocale)}
        </span>
      ),
    },
    {
      key: 'maturity',
      header: t('treasury:instruments.maturity'),
      render: (instrument) => instrument.maturity_date ? (
        <span className={cn('inline-flex items-center gap-1', textColors.tertiary)}>
          <Calendar className="h-3.5 w-3.5" />
          {new Date(instrument.maturity_date).toLocaleDateString(companyLocale)}
        </span>
      ) : <span className={textColors.tertiary}>—</span>,
    },
    {
      key: 'status',
      header: t('treasury:instruments.status'),
      render: (instrument) => (
        <StatusBadge tone={statusTone(instrument.status, instrumentStatusTones)}>
          {t(`treasury:instruments.statuses.${instrument.status}`)}
        </StatusBadge>
      ),
    },
    {
      key: 'amount',
      header: t('treasury:instruments.amount'),
      numeric: true,
      cellClassName: 'font-medium',
      render: (instrument) => formatAmount(instrument.amount),
    },
  ]

  const filters = (
    <div className="w-full space-y-3">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <label className={tokens.label.base}>
          {t('treasury:instruments.filters.kind')}
          <Select
            aria-label={t('treasury:instruments.filters.kind')}
            value={kind}
            onChange={(event) => { setKind(event.target.value); resetPage() }}
          >
            <option value="">{t('treasury:instruments.filters.all')}</option>
            <option value="cheque">{t('treasury:instruments.kinds.cheque')}</option>
            <option value="effet">{t('treasury:instruments.kinds.effet')}</option>
          </Select>
        </label>
        <label className={tokens.label.base}>
          {t('treasury:instruments.filters.direction')}
          <Select
            aria-label={t('treasury:instruments.filters.direction')}
            value={direction}
            onChange={(event) => { setDirection(event.target.value); resetPage() }}
          >
            <option value="">{t('treasury:instruments.filters.all')}</option>
            <option value="inbound">{t('treasury:instruments.directions.inbound')}</option>
            <option value="outbound">{t('treasury:instruments.directions.outbound')}</option>
          </Select>
        </label>
        <label className={tokens.label.base}>
          {t('treasury:instruments.filters.needsDetails')}
          <Select
            aria-label={t('treasury:instruments.filters.needsDetails')}
            value={needsDetails}
            onChange={(event) => { setNeedsDetails(event.target.value); resetPage() }}
          >
            <option value="">{t('treasury:instruments.filters.all')}</option>
            <option value="true">{t('common:yes')}</option>
            <option value="false">{t('common:no')}</option>
          </Select>
        </label>
        <label className={tokens.label.base}>
          {t('treasury:instruments.filters.maturityFrom')}
          <Input
            aria-label={t('treasury:instruments.filters.maturityFrom')}
            type="date"
            value={maturityFrom}
            onChange={(event) => { setMaturityFrom(event.target.value); resetPage() }}
          />
        </label>
        <label className={tokens.label.base}>
          {t('treasury:instruments.filters.maturityTo')}
          <Input
            aria-label={t('treasury:instruments.filters.maturityTo')}
            type="date"
            value={maturityTo}
            onChange={(event) => { setMaturityTo(event.target.value); resetPage() }}
          />
        </label>
      </div>
      {maturityData ? (
        <div className="flex flex-wrap gap-2" aria-label={t('treasury:instruments.buckets.label')}>
          {bucketKeys.map((bucket) => (
            <StatusBadge key={bucket} tone={bucket === 'overdue' ? 'danger' : 'neutral'}>
              {maturityData.meta.buckets[bucket].count} {t(`treasury:instruments.buckets.${bucket}`)}
            </StatusBadge>
          ))}
        </div>
      ) : null}
    </div>
  )

  return (
    <ListPageLayout
      title={t('treasury:instruments.title')}
      subtitle={t('treasury:instruments.count', { count: meta.total })}
      filters={filters}
      pagination={
        <OffsetPagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          perPage={meta.per_page}
          from={meta.total === 0 ? null : (meta.current_page - 1) * meta.per_page + 1}
          to={meta.total === 0 ? null : Math.min(meta.current_page * meta.per_page, meta.total)}
          onPageChange={setPage}
          onPerPageChange={(value) => { setPerPage(value); setPage(1) }}
        />
      }
    >
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>{t('common:errors.loadingFailed')}</div>
      ) : (
        <DataTable
          columns={columns}
          data={instruments}
          keyExtractor={(instrument) => instrument.id}
          isLoading={isLoading}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<FileCheck className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={t('treasury:instruments.empty.title')}
                description={t('treasury:instruments.empty.description')}
              />
            </div>
          }
        />
      )}
    </ListPageLayout>
  )
}
