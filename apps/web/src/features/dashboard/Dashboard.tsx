import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { usePageTitle } from '../../hooks/usePageTitle'
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
  AlertTriangle,
  X,
} from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { documentRouteTypeFromSource } from '../../lib/entityRoutes'
import { EntityLink } from '../../components/molecules/EntityLink'
import { fetchOnboardingStatus } from '../settings/api/onboardingApi'
import { usePermissions } from '../../hooks/usePermissions'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { CashPositionWidget } from '@/features/treasury/components/CashPositionWidget'

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
  document_number: string | null
  type: string
  partner_name: string
  total: number | string | null
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
  const { t } = useTranslation(['common', 'settings', 'sales'])
  usePageTitle('dashboard.title')
  const navigate = useNavigate()
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { hasPermission } = usePermissions()

  const [bannerDismissed, setBannerDismissed] = useState<boolean>(
    () => sessionStorage.getItem('onboarding-banner-dismissed') === 'true'
  )

  const { data: onboardingItems = [] } = useQuery({
    queryKey: tenantScopedKey(['onboarding-status']),
    queryFn: fetchOnboardingStatus,
    // GET /onboarding/status now requires settings.view (backend route gate,
    // docs/superpowers/tickets/2026-08-02-settings-setup-route-ungated.md
    // item 2). Without this, a user lacking settings.view (e.g. technician)
    // would fire a query that always 403s, and — before this fix — the
    // banner + hasIncompleteRequired logic could still render for users who
    // cannot action any linked setup step (dashboard->setup->dashboard
    // bounce loop, since /settings/setup is itself gated on moduleKey="settings").
    enabled: tenantId !== null && companyId !== null && hasPermission('settings.view'),
  })

  const hasIncompleteRequired = onboardingItems.some((item) => item.required && !item.completed)

  const handleDismissBanner = () => {
    sessionStorage.setItem('onboarding-banner-dismissed', 'true')
    setBannerDismissed(true)
  }

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  const { data: stats, isLoading: statsLoading } = useQuery({
    queryKey: tenantScopedKey(['dashboard', 'stats']),
    queryFn: async () => {
      const response = await api.get<{ data: DashboardStats }>('/dashboard/stats')
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const { data: documentsData, isLoading: documentsLoading } = useQuery({
    queryKey: tenantScopedKey(['dashboard', 'documents']),
    queryFn: async () => {
      const response = await api.get<DocumentsResponse>('/documents?limit=5&sort=-created_at')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const { data: paymentsData, isLoading: paymentsLoading } = useQuery({
    queryKey: tenantScopedKey(['dashboard', 'payments']),
    queryFn: async () => {
      const response = await api.get<PaymentsResponse>('/payments?limit=5&sort=-created_at')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
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
  const getDocumentNumberLabel = (documentNumber: string | null) =>
    documentNumber ?? t('sales:documents.draftNumberPlaceholder')

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={`${colorTokens.text.subtle}`}>{t('status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Onboarding alert banner */}
      {hasIncompleteRequired && !bannerDismissed && (
        <div className={`flex items-start justify-between gap-4 rounded-lg border ${colorTokens.intent.caution.borderSubtle} ${colorTokens.intent.caution.bgSubtle} p-4`}>
          <div className="flex items-start gap-3">
            <AlertTriangle className={`h-5 w-5 shrink-0 ${colorTokens.intent.caution.text} mt-0.5`} />
            <div>
              <p className={`text-sm font-medium ${colorTokens.intent.caution.textStrongest}`}>
                {t('settings:onboarding.requiredStepsAlert')}
              </p>
              <button
                type="button"
                onClick={() => { navigate('/settings/setup'); }}
                className={`mt-1 inline-flex items-center gap-1 text-sm ${colorTokens.intent.caution.textStrong} underline ${colorTokens.variants.hoverTextAmber900}`}
              >
                {t('settings:onboarding.viewChecklist')}
                <ArrowRight className="h-3 w-3" />
              </button>
            </div>
          </div>
          <button
            type="button"
            onClick={handleDismissBanner}
            aria-label={t('settings:onboarding.dismiss')}
            className={`shrink-0 rounded p-1 ${colorTokens.intent.caution.text} ${colorTokens.variants.hoverBgAmber100} ${colorTokens.variants.hoverTextAmber800}`}
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      )}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{t('dashboard.title')}</PageHeaderTitle>
          <p className={`${colorTokens.text.subtle}`}>{t('dashboard.welcome')}</p>
        </div>
        <div className="flex gap-3">
          <Link
            to="/sales/quotes/new"
            className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} bg-white px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50} transition-colors`}
          >
            <Plus className="h-4 w-4" />
            {t('dashboard.newQuote')}
          </Link>
          <Link
            to="/sales/invoices/new"
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium text-white ${colorTokens.intent.primary.bgStrongHover} transition-colors`}
          >
            <Plus className="h-4 w-4" />
            {t('dashboard.newInvoice')}
          </Link>
        </div>
      </div>

      {/* KPI Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {/* Revenue */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <div className="flex items-center justify-between">
            <div className={`rounded-lg ${colorTokens.intent.success.bgSoft} p-2`}>
              <DollarSign className={`h-5 w-5 ${colorTokens.intent.success.text}`} />
            </div>
            {stats?.revenue.change !== undefined && (
              <div
                className={`flex items-center gap-1 text-sm ${
                  stats.revenue.change >= 0 ? `${colorTokens.intent.success.text}` : `${colorTokens.intent.danger.text}`
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
            <p className={`text-sm ${colorTokens.text.subtle}`}>{t('dashboard.revenue')}</p>
            <p className={`text-2xl font-semibold ${colorTokens.text.primary}`}>
              {formatAmount(stats?.revenue.current ?? 0)}
            </p>
          </div>
        </div>

        {/* Invoices */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <div className="flex items-center justify-between">
            <div className={`rounded-lg ${colorTokens.intent.primary.bgSoft} p-2`}>
              <FileText className={`h-5 w-5 ${colorTokens.intent.primary.text}`} />
            </div>
            {stats?.invoices.overdue !== undefined && stats.invoices.overdue > 0 && (
              <span className={`rounded-full ${colorTokens.intent.danger.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.danger.textStronger}`}>
                {t('dashboard.overdueCount', { count: stats.invoices.overdue })}
              </span>
            )}
          </div>
          <div className="mt-4">
            <p className={`text-sm ${colorTokens.text.subtle}`}>{t('dashboard.invoices')}</p>
            <p className={`text-2xl font-semibold ${colorTokens.text.primary}`}>
              {stats?.invoices.total ?? 0}
            </p>
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t('dashboard.pendingCount', { count: stats?.invoices.pending ?? 0 })}
            </p>
          </div>
        </div>

        {/* Partners */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <div className="flex items-center justify-between">
            <div className={`rounded-lg ${colorTokens.intent.accent.bgSoft} p-2`}>
              <Users className={`h-5 w-5 ${colorTokens.intent.accent.text}`} />
            </div>
            {stats?.partners.newThisMonth !== undefined && stats.partners.newThisMonth > 0 && (
              <span className={`rounded-full ${colorTokens.intent.success.bgSoft} px-2 py-0.5 text-xs font-medium ${colorTokens.intent.success.textStronger}`}>
                {t('dashboard.newCount', { count: stats.partners.newThisMonth })}
              </span>
            )}
          </div>
          <div className="mt-4">
            <p className={`text-sm ${colorTokens.text.subtle}`}>{t('dashboard.partners')}</p>
            <p className={`text-2xl font-semibold ${colorTokens.text.primary}`}>
              {stats?.partners.total ?? 0}
            </p>
          </div>
        </div>

        {/* Payments */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white p-6`}>
          <div className="flex items-center justify-between">
            <div className={`rounded-lg ${colorTokens.intent.warning.bgSoft} p-2`}>
              <CreditCard className={`h-5 w-5 ${colorTokens.intent.warning.text}`} />
            </div>
          </div>
          <div className="mt-4">
            <p className={`text-sm ${colorTokens.text.subtle}`}>{t('dashboard.paymentsReceived')}</p>
            <p className={`text-2xl font-semibold ${colorTokens.text.primary}`}>
              {formatAmount(stats?.payments.received ?? 0)}
            </p>
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t('dashboard.pendingAmount', { amount: formatAmount(stats?.payments.pending ?? 0) })}
            </p>
          </div>
        </div>
      </div>

      {/* Recent Activity */}
      <div className="grid gap-6 lg:grid-cols-2">
        <CashPositionWidget />
        {/* Recent Documents */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white`}>
          <div className={`flex items-center justify-between border-b ${colorTokens.border.subtle} px-6 py-4`}>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('dashboard.recentDocuments')}</h2>
            <Link
              to="/documents"
              className={`flex items-center gap-1 text-sm ${colorTokens.intent.primary.text} ${colorTokens.variants.hoverTextBlue800}`}
            >
              {t('viewAll')}
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>
          <div className={`divide-y ${colorTokens.border.divider}`}>
            {recentDocuments.length === 0 ? (
              <div className={`px-6 py-8 text-center text-sm ${colorTokens.text.subtle}`}>
                {t('dashboard.noRecentDocuments')}
              </div>
            ) : (
              recentDocuments.map((doc) => {
                const documentType = documentRouteTypeFromSource(doc.type)
                const row = (
                  <>
                    <div>
                      <p className={`font-medium ${colorTokens.text.primary}`}>{getDocumentNumberLabel(doc.document_number)}</p>
                      <p className={`text-sm ${colorTokens.text.subtle}`}>{doc.partner_name}</p>
                    </div>
                    <div className="text-end">
                      <p className={`font-medium ${colorTokens.text.primary}`}>
                        {formatAmount(doc.total)}
                      </p>
                      <p className={`text-sm ${colorTokens.text.subtle} capitalize`}>
                        {t(`sales:documents.statuses.${doc.status}`, doc.status)}
                      </p>
                    </div>
                  </>
                )

                return documentType ? (
                  <EntityLink
                    key={doc.id}
                    type="document"
                    id={doc.id}
                    documentType={documentType}
                    label={row}
                    className={`flex items-center justify-between px-6 py-4 ${colorTokens.variants.hoverBgGray50}`}
                  />
                ) : (
                  <span key={doc.id} className="flex items-center justify-between px-6 py-4">
                    {row}
                  </span>
                )
              })
            )}
          </div>
        </div>

        {/* Recent Payments */}
        <div className={`rounded-lg border ${colorTokens.border.subtle} bg-white`}>
          <div className={`flex items-center justify-between border-b ${colorTokens.border.subtle} px-6 py-4`}>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>{t('dashboard.recentPayments')}</h2>
            <Link
              to="/treasury/payments"
              className={`flex items-center gap-1 text-sm ${colorTokens.intent.primary.text} ${colorTokens.variants.hoverTextBlue800}`}
            >
              {t('viewAll')}
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>
          <div className={`divide-y ${colorTokens.border.divider}`}>
            {recentPayments.length === 0 ? (
              <div className={`px-6 py-8 text-center text-sm ${colorTokens.text.subtle}`}>
                {t('dashboard.noRecentPayments')}
              </div>
            ) : (
              recentPayments.map((payment) => (
                <EntityLink
                  key={payment.id}
                  type="payment"
                  id={payment.id}
                  label={(
                    <>
                      <div>
                        <p className={`font-medium ${colorTokens.text.primary}`}>{payment.payment_number}</p>
                        <p className={`text-sm ${colorTokens.text.subtle}`}>{payment.partner_name}</p>
                      </div>
                      <div className="text-end">
                        <p className={`font-medium ${colorTokens.text.primary}`}>
                          {formatAmount(payment.amount)}
                        </p>
                        <p className={`text-sm ${colorTokens.text.subtle}`}>{payment.payment_method_name}</p>
                      </div>
                    </>
                  )}
                  className={`flex items-center justify-between px-6 py-4 ${colorTokens.variants.hoverBgGray50}`}
                />
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
