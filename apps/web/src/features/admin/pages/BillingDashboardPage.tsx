import { Link } from 'react-router-dom'
import { useBillingDashboard, usePaymentProviders } from '../hooks/useBilling'
import { colorClasses } from '@/lib/designTokens'

function formatCurrency(amount: number, currency = 'EUR'): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
  }).format(amount)
}

export function BillingDashboardPage() {
  const { data: stats, isLoading: statsLoading } = useBillingDashboard()
  const { data: providers, isLoading: providersLoading } = usePaymentProviders()

  if (statsLoading || providersLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className={`${colorClasses.textGray500}`}>Loading billing dashboard...</div>
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8 flex items-center justify-between">
          <h1 className={`text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>
            Billing Dashboard
          </h1>
          <div className="flex gap-3">
            <Link
              to="/admin/billing/subscriptions"
              className={`rounded-md bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} shadow-sm ring-1 ${colorClasses.ringGray300} ${colorClasses.hoverBgGray50}`}
            >
              Subscriptions
            </Link>
            <Link
              to="/admin/billing/invoices"
              className={`rounded-md bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} shadow-sm ring-1 ${colorClasses.ringGray300} ${colorClasses.hoverBgGray50}`}
            >
              Invoices
            </Link>
            <Link
              to="/admin/billing/payments"
              className={`rounded-md bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} shadow-sm ring-1 ${colorClasses.ringGray300} ${colorClasses.hoverBgGray50}`}
            >
              Payments
            </Link>
          </div>
        </div>

        {/* Revenue Stats */}
        <div className="mb-8">
          <h2 className={`mb-4 text-lg font-semibold ${colorClasses.textGray700}`}>Revenue</h2>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>MRR</div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGreen600}`}>
                  {formatCurrency(stats?.mrr ?? 0)}
                </div>
                <div className={`mt-1 text-xs ${colorClasses.textGray400}`}>
                  Monthly Recurring Revenue
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>ARR</div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGreen600}`}>
                  {formatCurrency(stats?.arr ?? 0)}
                </div>
                <div className={`mt-1 text-xs ${colorClasses.textGray400}`}>
                  Annual Recurring Revenue
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                  This Month
                </div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textBlue600}`}>
                  {formatCurrency(stats?.revenue_this_month ?? 0)}
                </div>
                <div className={`mt-1 text-xs ${colorClasses.textGray400}`}>
                  Revenue collected this month
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                  Outstanding
                </div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textOrange600}`}>
                  {formatCurrency(stats?.outstanding_invoices ?? 0)}
                </div>
                <div className={`mt-1 text-xs ${colorClasses.textGray400}`}>
                  Unpaid invoices total
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Subscription Stats */}
        <div className="mb-8">
          <h2 className={`mb-4 text-lg font-semibold ${colorClasses.textGray700}`}>
            Subscriptions
          </h2>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>Active</div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGreen600}`}>
                  {stats?.active_subscriptions ?? 0}
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>Trial</div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textBlue600}`}>
                  {stats?.trial_subscriptions ?? 0}
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                  Past Due
                </div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textRed600}`}>
                  {stats?.past_due_subscriptions ?? 0}
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                  Overdue Invoices
                </div>
                <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textRed600}`}>
                  {stats?.overdue_invoices_count ?? 0}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Payment Providers */}
        <div>
          <h2 className={`mb-4 text-lg font-semibold ${colorClasses.textGray700}`}>
            Payment Providers
          </h2>
          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className={`divide-y ${colorClasses.divideGray200}`}>
              {providers &&
                Object.entries(providers).map(([code, provider]) => (
                  <div
                    key={code}
                    className="flex items-center justify-between p-4"
                  >
                    <div className="flex items-center gap-3">
                      <span className={`font-medium ${colorClasses.textGray900}`}>
                        {provider.name}
                      </span>
                      <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                          provider.type === 'online'
                            ? `${colorClasses.bgPurple100} ${colorClasses.textPurple700}`
                            : `${colorClasses.bgGray100} ${colorClasses.textGray700}`
                        }`}
                      >
                        {provider.type}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      {provider.configured ? (
                        <span className={`flex items-center gap-1 text-sm ${colorClasses.textGreen600}`}>
                          <svg
                            className="h-4 w-4"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                          >
                            <path
                              fillRule="evenodd"
                              d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                              clipRule="evenodd"
                            />
                          </svg>
                          Configured
                        </span>
                      ) : (
                        <span className={`flex items-center gap-1 text-sm ${colorClasses.textGray400}`}>
                          <svg
                            className="h-4 w-4"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                          >
                            <path
                              fillRule="evenodd"
                              d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                              clipRule="evenodd"
                            />
                          </svg>
                          Not Configured
                        </span>
                      )}
                      {provider.available && (
                        <span className={`rounded-full ${colorClasses.bgGreen100} px-2 py-0.5 text-xs font-medium ${colorClasses.textGreen700}`}>
                          Available
                        </span>
                      )}
                    </div>
                  </div>
                ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
