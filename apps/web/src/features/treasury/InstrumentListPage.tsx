import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { FileCheck, Calendar } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'

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

const statusColors: Record<Instrument['status'], string> = {
  received: 'bg-yellow-100 text-yellow-800',
  deposited: 'bg-blue-100 text-blue-800',
  cleared: 'bg-green-100 text-green-800',
  bounced: 'bg-red-100 text-red-800',
  cancelled: 'bg-gray-100 text-gray-800',
}

// Status and type labels are loaded from translations

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

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('treasury:instruments.title')}</h1>
          <p className="text-gray-500">
            {total} {total === 1 ? t('treasury:instruments.singular') : t('treasury:instruments.plural')} {t('total')}
          </p>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed')}
        </div>
      ) : instruments.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <FileCheck className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {t('treasury:instruments.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {t('treasury:instruments.empty.description')}
          </p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:instruments.number')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:instruments.type')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:instruments.partner')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:instruments.maturity')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:instruments.location')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:instruments.status')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:instruments.amount')}
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('actions.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {instruments.map((instrument) => (
                <tr key={instrument.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/treasury/instruments/${instrument.id}`}
                      className="font-medium text-gray-900 hover:text-blue-600"
                    >
                      {instrument.instrument_number}
                    </Link>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {getTypeLabel(instrument.type)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {instrument.partner_id ? (
                      <Link
                        to={`/sales/customers/${instrument.partner_id}`}
                        className="text-blue-600 hover:text-blue-800 hover:underline"
                      >
                        {instrument.partner_name}
                      </Link>
                    ) : (
                      <span className="text-gray-500">{instrument.partner_name ?? t('status.unknown')}</span>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {instrument.maturity_date ? (
                      <div className="flex items-center gap-1">
                        <Calendar className="h-3.5 w-3.5" />
                        {new Date(instrument.maturity_date).toLocaleDateString()}
                      </div>
                    ) : (
                      '-'
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {instrument.repository_id ? (
                      <Link
                        to={`/treasury/repositories/${instrument.repository_id}`}
                        className="text-blue-600 hover:text-blue-800 hover:underline"
                      >
                        {instrument.repository_name}
                      </Link>
                    ) : (
                      <span>{instrument.repository_name ?? t('status.unknown')}</span>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[instrument.status]}`}
                    >
                      {getStatusLabel(instrument.status)}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                    {formatAmount(instrument.amount)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                    <Link
                      to={`/treasury/instruments/${instrument.id}`}
                      className="text-blue-600 hover:text-blue-900"
                    >
                      {t('actions.view')}
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
