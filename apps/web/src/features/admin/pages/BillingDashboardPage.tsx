import { Link } from 'react-router-dom'
import { useBillingDashboard, usePaymentProviders } from '../hooks/useBilling'

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
        <div className="text-gray-500">Loading billing dashboard...</div>
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8 flex items-center justify-between">
          <h1 className="text-3xl font-bold text-gray-900">
            Billing Dashboard
          </h1>
          <div className="flex gap-3">
            <Link
              to="/admin/billing/subscriptions"
              className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50"
            >
              Subscriptions
            </Link>
            <Link
              to="/admin/billing/invoices"
              className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50"
            >
              Invoices
            </Link>
            <Link
              to="/admin/billing/payments"
              className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50"
            >
              Payments
            </Link>
          </div>
        </div>

        {/* Revenue Stats */}
        <div className="mb-8">
          <h2 className="mb-4 text-lg font-semibold text-gray-700">Revenue</h2>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">MRR</div>
                <div className="mt-2 text-3xl font-bold text-green-600">
                  {formatCurrency(stats?.mrr ?? 0)}
                </div>
                <div className="mt-1 text-xs text-gray-400">
                  Monthly Recurring Revenue
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">ARR</div>
                <div className="mt-2 text-3xl font-bold text-green-600">
                  {formatCurrency(stats?.arr ?? 0)}
                </div>
                <div className="mt-1 text-xs text-gray-400">
                  Annual Recurring Revenue
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">
                  This Month
                </div>
                <div className="mt-2 text-3xl font-bold text-blue-600">
                  {formatCurrency(stats?.revenue_this_month ?? 0)}
                </div>
                <div className="mt-1 text-xs text-gray-400">
                  Revenue collected this month
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">
                  Outstanding
                </div>
                <div className="mt-2 text-3xl font-bold text-orange-600">
                  {formatCurrency(stats?.outstanding_invoices ?? 0)}
                </div>
                <div className="mt-1 text-xs text-gray-400">
                  Unpaid invoices total
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Subscription Stats */}
        <div className="mb-8">
          <h2 className="mb-4 text-lg font-semibold text-gray-700">
            Subscriptions
          </h2>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">Active</div>
                <div className="mt-2 text-3xl font-bold text-green-600">
                  {stats?.active_subscriptions ?? 0}
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">Trial</div>
                <div className="mt-2 text-3xl font-bold text-blue-600">
                  {stats?.trial_subscriptions ?? 0}
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">
                  Past Due
                </div>
                <div className="mt-2 text-3xl font-bold text-red-600">
                  {stats?.past_due_subscriptions ?? 0}
                </div>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg bg-white shadow">
              <div className="p-6">
                <div className="text-sm font-medium text-gray-500">
                  Overdue Invoices
                </div>
                <div className="mt-2 text-3xl font-bold text-red-600">
                  {stats?.overdue_invoices_count ?? 0}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Payment Providers */}
        <div>
          <h2 className="mb-4 text-lg font-semibold text-gray-700">
            Payment Providers
          </h2>
          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="divide-y divide-gray-200">
              {providers &&
                Object.entries(providers).map(([code, provider]) => (
                  <div
                    key={code}
                    className="flex items-center justify-between p-4"
                  >
                    <div className="flex items-center gap-3">
                      <span className="font-medium text-gray-900">
                        {provider.name}
                      </span>
                      <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                          provider.type === 'online'
                            ? 'bg-purple-100 text-purple-700'
                            : 'bg-gray-100 text-gray-700'
                        }`}
                      >
                        {provider.type}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      {provider.configured ? (
                        <span className="flex items-center gap-1 text-sm text-green-600">
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
                        <span className="flex items-center gap-1 text-sm text-gray-400">
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
                        <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
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
