import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  DollarSign,
  FileText,
  Users,
  CreditCard,
  TrendingUp,
  TrendingDown,
  Plus,
  ArrowRight,
} from 'lucide-react'
import { api } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'

interface DashboardStats {
  revenue: {
    current: number
    previous: number
    change: number
  }
  invoices: {
    total: number
    pending: number
    overdue: number
  }
  partners: {
    total: number
    newThisMonth: number
  }
  payments: {
    received: number
    pending: number
  }
}

interface RecentDocument {
  id: string
  document_number: string
  type: string
  partner_name: string
  total_amount: number | string | null
  status: string
  created_at: string
}

interface RecentPayment {
  id: string
  payment_number: string
  partner_name: string
  amount: number | string | null
  payment_method_name: string
  created_at: string
}

interface DocumentsResponse {
  data: RecentDocument[]
}

interface PaymentsResponse {
  data: RecentPayment[]
}

export function Dashboard() {
  const { t } = useTranslation()
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const { data: stats, isLoading: statsLoading } = useQuery({
    queryKey: ['dashboard', 'stats'],
    queryFn: async () => {
      const response = await api.get<{ data: DashboardStats }>('/dashboard/stats')
      return response.data.data
    },
  })

  const { data: documentsData, isLoading: documentsLoading } = useQuery({
    queryKey: ['dashboard', 'documents'],
    queryFn: async () => {
      const response = await api.get<DocumentsResponse>('/documents?limit=5&sort=-created_at')
      return response.data
    },
  })

  const { data: paymentsData, isLoading: paymentsLoading } = useQuery({
    queryKey: ['dashboard', 'payments'],
    queryFn: async () => {
      const response = await api.get<PaymentsResponse>('/payments?limit=5&sort=-created_at')
      return response.data
    },
  })

  const isLoading = statsLoading || documentsLoading || paymentsLoading
  const recentDocuments = documentsData?.data ?? []
  const recentPayments = paymentsData?.data ?? []

  // Format currency using company settings
  const formatAmount = (amount: number | string | null | undefined) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : (amount ?? 0)
    return formatCurrency(isNaN(num) ? 0 : num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{t('dashboard.title')}</h1>
          <p className="text-gray-500">{t('dashboard.welcome')}</p>
        </div>
        <div className="flex gap-3">
          <Link
            to="/documents/new?type=quote"
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            <Plus className="h-4 w-4" />
            {t('dashboard.newQuote')}
          </Link>
          <Link
            to="/documents/new?type=invoice"
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
          >
            <Plus className="h-4 w-4" />
            {t('dashboard.newInvoice')}
          </Link>
        </div>
      </div>

      {/* KPI Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {/* Revenue */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="flex items-center justify-between">
            <div className="rounded-lg bg-green-100 p-2">
              <DollarSign className="h-5 w-5 text-green-600" />
            </div>
            {stats?.revenue.change !== undefined && (
              <div
                className={`flex items-center gap-1 text-sm ${
                  stats.revenue.change >= 0 ? 'text-green-600' : 'text-red-600'
                }`}
              >
                {stats.revenue.change >= 0 ? (
                  <TrendingUp className="h-4 w-4" />
                ) : (
                  <TrendingDown className="h-4 w-4" />
                )}
                {Math.abs(stats.revenue.change)}%
              </div>
            )}
          </div>
          <div className="mt-4">
            <p className="text-sm text-gray-500">{t('dashboard.revenue')}</p>
            <p className="text-2xl font-semibold text-gray-900">
              {formatAmount(stats?.revenue.current ?? 0)}
            </p>
          </div>
        </div>

        {/* Invoices */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="flex items-center justify-between">
            <div className="rounded-lg bg-blue-100 p-2">
              <FileText className="h-5 w-5 text-blue-600" />
            </div>
            {stats?.invoices.overdue !== undefined && stats.invoices.overdue > 0 && (
              <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                {t('dashboard.overdueCount', { count: stats.invoices.overdue })}
              </span>
            )}
          </div>
          <div className="mt-4">
            <p className="text-sm text-gray-500">{t('dashboard.invoices')}</p>
            <p className="text-2xl font-semibold text-gray-900">
              {stats?.invoices.total ?? 0}
            </p>
            <p className="text-sm text-gray-500">
              {t('dashboard.pendingCount', { count: stats?.invoices.pending ?? 0 })}
            </p>
          </div>
        </div>

        {/* Partners */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="flex items-center justify-between">
            <div className="rounded-lg bg-purple-100 p-2">
              <Users className="h-5 w-5 text-purple-600" />
            </div>
            {stats?.partners.newThisMonth !== undefined && stats.partners.newThisMonth > 0 && (
              <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">
                {t('dashboard.newCount', { count: stats.partners.newThisMonth })}
              </span>
            )}
          </div>
          <div className="mt-4">
            <p className="text-sm text-gray-500">{t('dashboard.partners')}</p>
            <p className="text-2xl font-semibold text-gray-900">
              {stats?.partners.total ?? 0}
            </p>
          </div>
        </div>

        {/* Payments */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="flex items-center justify-between">
            <div className="rounded-lg bg-yellow-100 p-2">
              <CreditCard className="h-5 w-5 text-yellow-600" />
            </div>
          </div>
          <div className="mt-4">
            <p className="text-sm text-gray-500">{t('dashboard.paymentsReceived')}</p>
            <p className="text-2xl font-semibold text-gray-900">
              {formatAmount(stats?.payments.received ?? 0)}
            </p>
            <p className="text-sm text-gray-500">
              {t('dashboard.pendingAmount', { amount: formatAmount(stats?.payments.pending ?? 0) })}
            </p>
          </div>
        </div>
      </div>

      {/* Recent Activity */}
      <div className="grid gap-6 lg:grid-cols-2">
        {/* Recent Documents */}
        <div className="rounded-lg border border-gray-200 bg-white">
          <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h2 className="text-lg font-semibold text-gray-900">{t('dashboard.recentDocuments')}</h2>
            <Link
              to="/documents"
              className="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800"
            >
              {t('common.viewAll')}
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>
          <div className="divide-y divide-gray-200">
            {recentDocuments.length === 0 ? (
              <div className="px-6 py-8 text-center text-sm text-gray-500">
                {t('dashboard.noRecentDocuments')}
              </div>
            ) : (
              recentDocuments.map((doc) => (
                <Link
                  key={doc.id}
                  to={`/documents/${doc.id}`}
                  className="flex items-center justify-between px-6 py-4 hover:bg-gray-50"
                >
                  <div>
                    <p className="font-medium text-gray-900">{doc.document_number}</p>
                    <p className="text-sm text-gray-500">{doc.partner_name}</p>
                  </div>
                  <div className="text-end">
                    <p className="font-medium text-gray-900">
                      {formatAmount(doc.total_amount)}
                    </p>
                    <p className="text-sm text-gray-500 capitalize">{doc.status}</p>
                  </div>
                </Link>
              ))
            )}
          </div>
        </div>

        {/* Recent Payments */}
        <div className="rounded-lg border border-gray-200 bg-white">
          <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h2 className="text-lg font-semibold text-gray-900">{t('dashboard.recentPayments')}</h2>
            <Link
              to="/treasury/payments"
              className="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800"
            >
              {t('common.viewAll')}
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>
          <div className="divide-y divide-gray-200">
            {recentPayments.length === 0 ? (
              <div className="px-6 py-8 text-center text-sm text-gray-500">
                {t('dashboard.noRecentPayments')}
              </div>
            ) : (
              recentPayments.map((payment) => (
                <Link
                  key={payment.id}
                  to={`/treasury/payments/${payment.id}`}
                  className="flex items-center justify-between px-6 py-4 hover:bg-gray-50"
                >
                  <div>
                    <p className="font-medium text-gray-900">{payment.payment_number}</p>
                    <p className="text-sm text-gray-500">{payment.partner_name}</p>
                  </div>
                  <div className="text-end">
                    <p className="font-medium text-gray-900">
                      {formatAmount(payment.amount)}
                    </p>
                    <p className="text-sm text-gray-500">{payment.payment_method_name}</p>
                  </div>
                </Link>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
