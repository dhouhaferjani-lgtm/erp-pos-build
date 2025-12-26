import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, CreditCard, Calendar } from 'lucide-react'
import { api } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'

interface Payment {
  id: string
  payment_number: string
  amount: number
  payment_date: string
  payment_method_id: string
  payment_method_name: string
  partner_id: string
  partner_name: string
  partner_type: 'customer' | 'supplier' | 'both' | null
  payment_type: string | null
  status: 'pending' | 'completed' | 'cancelled'
  created_at: string
}

interface PaymentsResponse {
  data: Payment[]
  meta?: { total: number }
}

const statusColors: Record<Payment['status'], string> = {
  pending: 'bg-yellow-100 text-yellow-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
}

// Status labels are loaded from translations

export function PaymentListPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  // Get translated status label
  const getStatusLabel = (status: Payment['status']) => {
    return t(`treasury:payments.statuses.${status}`, status)
  }

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const { data, isLoading, error } = useQuery({
    queryKey: ['payments'],
    queryFn: async () => {
      const response = await api.get<PaymentsResponse>('/payments')
      return response.data
    },
  })

  const payments = data?.data ?? []
  const total = data?.meta?.total ?? payments.length

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
          <h1 className="text-2xl font-bold text-gray-900">{t('treasury:payments.title')}</h1>
          <p className="text-gray-500">
            {total} {total === 1 ? t('treasury:payments.singular', 'payment') : t('treasury:payments.plural', 'payments')} {t('total')}
          </p>
        </div>
        <Link
          to="/treasury/payments/new"
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('treasury:payments.record')}
        </Link>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : payments.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <CreditCard className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">{t('treasury:payments.empty.title')}</h3>
          <p className="mt-1 text-sm text-gray-500">
            {t('treasury:payments.empty.description')}
          </p>
          <div className="mt-6">
            <Link
              to="/treasury/payments/new"
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              <Plus className="h-4 w-4" />
              {t('treasury:payments.record')}
            </Link>
          </div>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:payments.number')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:payments.partner')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:payments.method')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:payments.date')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:payments.status')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('treasury:payments.amount')}
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('actions.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {payments.map((payment) => (
                <tr key={payment.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <Link
                      to={`/treasury/payments/${payment.id}`}
                      className="font-medium text-gray-900 hover:text-blue-600"
                    >
                      {payment.payment_number}
                    </Link>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {payment.partner_id ? (
                      <Link
                        to={
                          payment.partner_type === 'supplier' || payment.payment_type === 'supplier_payment'
                            ? `/purchases/suppliers/${payment.partner_id}`
                            : `/sales/customers/${payment.partner_id}`
                        }
                        className="text-blue-600 hover:text-blue-800 hover:underline"
                      >
                        {payment.partner_name ?? t('treasury:payments.messages.noPartnerLinked')}
                      </Link>
                    ) : (
                      <span className="text-gray-500">{payment.partner_name ?? t('treasury:payments.messages.noPartnerLinked')}</span>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {payment.payment_method_name}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    <div className="flex items-center gap-1">
                      <Calendar className="h-3.5 w-3.5" />
                      {new Date(payment.payment_date).toLocaleDateString()}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[payment.status]}`}
                    >
                      {getStatusLabel(payment.status)}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                    {formatAmount(payment.amount)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                    <Link
                      to={`/treasury/payments/${payment.id}`}
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
