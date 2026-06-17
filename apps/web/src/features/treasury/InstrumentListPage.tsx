import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { FileCheck, Calendar } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { tokens, textColors } from '../../lib/designTokens'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { StatusBadge, statusTone, type StatusTone } from '../../components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '../../components/molecules'

interface Instrument {
  id: string
  instrument_number: string
  type: 'check' | 'promissory_note' | 'voucher'
  amount: number
  issue_date: string
  maturity_date: string | null
  partner_id: string
  partner_name: string
  status: 'received' | 'deposited' | 'cleared' | 'bounced' | 'cancelled'
  repository_id: string
  repository_name: string
  created_at: string
}

interface InstrumentsResponse {
  data: Instrument[]
  meta?: { total: number }
}

/**
 * Instrument lifecycle statuses that aren't in the shared `statusTone` built-in
 * map get a semantic tone here, so every status renders through the one
 * sanctioned StatusBadge palette instead of a bespoke off-theme map.
 * (`cleared` → success, `cancelled` → danger are already built in.)
 */
const instrumentStatusTones: Record<string, StatusTone> = {
  received: 'pending',
  deposited: 'info',
  bounced: 'danger',
}

export function InstrumentListPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  // Get translated status label
  const getStatusLabel = (status: Instrument['status']) => {
    return t(`treasury:instruments.statuses.${status}`, status)
  }

  // Get translated type label
  const getTypeLabel = (type: Instrument['type']) => {
    return t(`treasury:instruments.types.${type}`, type)
  }

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['instruments']),
    queryFn: async () => {
      const response = await api.get<InstrumentsResponse>('/payment-instruments')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const instruments = data?.data ?? []
  const total = data?.meta?.total ?? instruments.length

  // Format currency using company settings
  const formatAmount = (amount: number) => {
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  const columns: DataTableColumn<Instrument>[] = [
    {
      key: 'number',
      header: t('treasury:instruments.number'),
      render: (instrument) => (
        <Link
          to={`/treasury/instruments/${instrument.id}`}
          className={cn('font-medium', textColors.primary, 'hover:underline')}
        >
          {instrument.instrument_number}
        </Link>
      ),
    },
    {
      key: 'type',
      header: t('treasury:instruments.type'),
      render: (instrument) => (
        <span className={textColors.tertiary}>{getTypeLabel(instrument.type)}</span>
      ),
    },
    {
      key: 'partner',
      header: t('treasury:instruments.partner'),
      render: (instrument) =>
        instrument.partner_id ? (
          <Link
            to={`/sales/customers/${instrument.partner_id}`}
            className={cn(textColors.brand, 'hover:underline')}
          >
            {instrument.partner_name}
          </Link>
        ) : (
          <span className={textColors.tertiary}>
            {instrument.partner_name ?? t('status.unknown')}
          </span>
        ),
    },
    {
      key: 'maturity',
      header: t('treasury:instruments.maturity'),
      render: (instrument) =>
        instrument.maturity_date ? (
          <div className={cn('flex items-center gap-1', textColors.tertiary)}>
            <Calendar className="h-3.5 w-3.5" />
            {new Date(instrument.maturity_date).toLocaleDateString()}
          </div>
        ) : (
          <span className={textColors.tertiary}>-</span>
        ),
    },
    {
      key: 'location',
      header: t('treasury:instruments.location'),
      render: (instrument) =>
        instrument.repository_id ? (
          <Link
            to={`/treasury/repositories/${instrument.repository_id}`}
            className={cn(textColors.brand, 'hover:underline')}
          >
            {instrument.repository_name}
          </Link>
        ) : (
          <span className={textColors.tertiary}>
            {instrument.repository_name ?? t('status.unknown')}
          </span>
        ),
    },
    {
      key: 'status',
      header: t('treasury:instruments.status'),
      render: (instrument) => (
        <StatusBadge tone={statusTone(instrument.status, instrumentStatusTones)}>
          {getStatusLabel(instrument.status)}
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
    {
      key: 'actions',
      header: <span className="sr-only">{t('actions.actions')}</span>,
      align: 'right',
      render: (instrument) => (
        <Link to={`/treasury/instruments/${instrument.id}`} className={textColors.brand}>
          {t('actions.view')}
        </Link>
      ),
    },
  ]

  return (
    <ListPageLayout
      title={t('treasury:instruments.title')}
      subtitle={`${String(total)} ${
        total === 1
          ? t('treasury:instruments.singular')
          : t('treasury:instruments.plural')
      } ${t('total')}`}
    >
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errors.loadingFailed')}
        </div>
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
