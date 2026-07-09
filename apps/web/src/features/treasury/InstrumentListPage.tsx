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

interface InstrumentPartner {
  id: string
  name: string
}

interface InstrumentRepository {
  id: string
  code: string
  name: string
}

/**
 * Mirrors `PaymentInstrumentController::formatInstrument` (apps/api
 * `App\Modules\Treasury\Presentation\Controllers\PaymentInstrumentController`).
 * There is no `instrument_number`, `type`, or `partner_name` — those were
 * phantom fields the API never sent (audit finding G3).
 */
interface Instrument {
  id: string
  reference: string
  partner_id: string | null
  partner: InstrumentPartner | null
  drawer_name: string | null
  amount: string
  currency: string
  received_date: string
  maturity_date: string | null
  status:
    | 'received'
    | 'in_transit'
    | 'deposited'
    | 'clearing'
    | 'cleared'
    | 'bounced'
    | 'expired'
    | 'cancelled'
    | 'collected'
  repository_id: string | null
  repository: InstrumentRepository | null
  created_at: string | null
}

/**
 * `GET /payment-instruments` returns a plain `{ data: [...] }` — it is NOT
 * paginated (the controller calls `->get()`, never `->paginate()`), so there
 * is no `meta` to read.
 */
interface InstrumentsResponse {
  data: Instrument[]
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
  // The API never returns pagination metadata for this endpoint (see
  // `InstrumentsResponse` above) — the count is always the fetched page.
  const total = instruments.length

  // Format currency using company settings. `amount` is a `numeric-string`
  // (decimal:3 cast) — never coerce it to a float before formatting.
  const formatAmount = (amount: string) => {
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  const columns: DataTableColumn<Instrument>[] = [
    {
      key: 'reference',
      header: t('treasury:instruments.number'),
      render: (instrument) => (
        <Link
          to={`/treasury/instruments/${instrument.id}`}
          className={cn('font-medium', textColors.primary, 'hover:underline')}
        >
          {instrument.reference}
        </Link>
      ),
    },
    {
      key: 'partner',
      header: t('treasury:instruments.partner'),
      // `partner` is a nullable relation (no drawer partner, or a walk-in
      // drawer). Fall back to `drawer_name` (the human who handed over the
      // instrument), then a dash — never the phantom `partner_name`.
      render: (instrument) =>
        instrument.partner ? (
          <Link
            to={`/sales/customers/${instrument.partner.id}`}
            className={cn(textColors.brand, 'hover:underline')}
          >
            {instrument.partner.name}
          </Link>
        ) : (
          <span className={textColors.tertiary}>{instrument.drawer_name ?? '-'}</span>
        ),
    },
    {
      key: 'received',
      header: t('treasury:instruments.receivedDate'),
      render: (instrument) => (
        <div className={cn('flex items-center gap-1', textColors.tertiary)}>
          <Calendar className="h-3.5 w-3.5" />
          {new Date(instrument.received_date).toLocaleDateString()}
        </div>
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
      // `repository` is nullable (the instrument may not be assigned to a
      // repository yet) — never the phantom `repository_name`.
      render: (instrument) =>
        instrument.repository ? (
          <Link
            to={`/treasury/repositories/${instrument.repository.id}`}
            className={cn(textColors.brand, 'hover:underline')}
          >
            {instrument.repository.name}
          </Link>
        ) : (
          <span className={textColors.tertiary}>-</span>
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
