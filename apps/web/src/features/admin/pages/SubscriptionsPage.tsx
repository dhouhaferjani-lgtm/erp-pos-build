import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useSubscriptions, useUpdateSubscription, usePlans } from '../hooks/useBilling'
import type { Subscription, SubscriptionStatus } from '../types'

const STATUS_COLORS: Record<SubscriptionStatus, string> = {
  trial: 'bg-blue-100 text-blue-700',
  active: 'bg-green-100 text-green-700',
  past_due: 'bg-orange-100 text-orange-700',
  unpaid: 'bg-red-100 text-red-700',
  paused: 'bg-yellow-100 text-yellow-700',
  cancelling: 'bg-gray-100 text-gray-700',
  cancelled: 'bg-gray-100 text-gray-500',
  expired: 'bg-red-100 text-red-500',
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '-'
  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

function formatCurrency(amount: string | null, currency = 'EUR'): string {
  if (!amount) return '-'
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
  }).format(parseFloat(amount))
}

export function SubscriptionsPage() {
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [planFilter, setPlanFilter] = useState<string>('')

  const { data: subscriptionsData, isLoading } = useSubscriptions({
    ...(statusFilter && { status: statusFilter }),
    ...(planFilter && { plan_id: planFilter }),
    per_page: 50,
  })
  const { data: plans } = usePlans()
  const updateSubscription = useUpdateSubscription()

  const [selectedSubscription, setSelectedSubscription] =
    useState<Subscription | null>(null)
  const [showActionModal, setShowActionModal] = useState(false)

  const handleStatusChange = (
    subscription: Subscription,
    newStatus: 'active' | 'paused' | 'cancelled'
  ) => {
    if (
      confirm(
        `Are you sure you want to change the subscription status to ${newStatus}?`
      )
    ) {
      updateSubscription.mutate({
        id: subscription.id,
        data: { status: newStatus },
      })
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="text-gray-500">Loading subscriptions...</div>
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8 flex items-center justify-between">
          <div>
            <Link
              to="/admin/billing"
              className="text-sm text-blue-600 hover:text-blue-800"
            >
              &larr; Back to Billing
            </Link>
            <h1 className="mt-2 text-3xl font-bold text-gray-900">
              Subscriptions
            </h1>
          </div>
          <div className="text-sm text-gray-500">
            {subscriptionsData?.total ?? 0} total subscriptions
          </div>
        </div>

        {/* Filters */}
        <div className="mb-6 flex gap-4">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">All Statuses</option>
            <option value="trial">Trial</option>
            <option value="active">Active</option>
            <option value="past_due">Past Due</option>
            <option value="unpaid">Unpaid</option>
            <option value="paused">Paused</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
          </select>

          <select
            value={planFilter}
            onChange={(e) => setPlanFilter(e.target.value)}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">All Plans</option>
            {plans?.map((plan) => (
              <option key={plan.id} value={plan.id}>
                {plan.name}
              </option>
            ))}
          </select>
        </div>

        {/* Table */}
        <div className="overflow-hidden rounded-lg bg-white shadow">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Tenant
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Plan
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Billing
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Period End
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {subscriptionsData?.data.map((subscription) => (
                <tr key={subscription.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="font-medium text-gray-900">
                      {subscription.tenant?.name ?? 'Unknown'}
                    </div>
                    <div className="text-sm text-gray-500">
                      {subscription.tenant?.email ?? ''}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="text-gray-900">
                      {subscription.plan?.name ?? 'Unknown'}
                    </div>
                    <div className="text-sm text-gray-500">
                      {formatCurrency(subscription.price, subscription.currency)}
                      /{subscription.billing_cycle === 'monthly' ? 'mo' : 'yr'}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${STATUS_COLORS[subscription.status]}`}
                    >
                      {subscription.status.replace('_', ' ')}
                    </span>
                    {subscription.status === 'trial' &&
                      subscription.trial_ends_at && (
                        <div className="mt-1 text-xs text-gray-500">
                          Ends {formatDate(subscription.trial_ends_at)}
                        </div>
                      )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {subscription.billing_cycle}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                    {formatDate(subscription.current_period_end)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex gap-2">
                      {subscription.status === 'active' && (
                        <button
                          onClick={() =>
                            handleStatusChange(subscription, 'paused')
                          }
                          className="text-sm text-yellow-600 hover:text-yellow-800"
                        >
                          Pause
                        </button>
                      )}
                      {subscription.status === 'paused' && (
                        <button
                          onClick={() =>
                            handleStatusChange(subscription, 'active')
                          }
                          className="text-sm text-green-600 hover:text-green-800"
                        >
                          Resume
                        </button>
                      )}
                      {!['cancelled', 'expired'].includes(
                        subscription.status
                      ) && (
                        <button
                          onClick={() =>
                            handleStatusChange(subscription, 'cancelled')
                          }
                          className="text-sm text-red-600 hover:text-red-800"
                        >
                          Cancel
                        </button>
                      )}
                      <button
                        onClick={() => {
                          setSelectedSubscription(subscription)
                          setShowActionModal(true)
                        }}
                        className="text-sm text-blue-600 hover:text-blue-800"
                      >
                        Details
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {subscriptionsData?.data.length === 0 && (
            <div className="py-12 text-center text-gray-500">
              No subscriptions found
            </div>
          )}
        </div>

        {/* Details Modal */}
        {showActionModal && selectedSubscription && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
              <h2 className="mb-4 text-xl font-bold text-gray-900">
                Subscription Details
              </h2>

              <div className="space-y-3">
                <div className="flex justify-between">
                  <span className="text-gray-500">ID:</span>
                  <span className="font-mono text-sm">
                    {selectedSubscription.id}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Tenant:</span>
                  <span>{selectedSubscription.tenant?.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Plan:</span>
                  <span>{selectedSubscription.plan?.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Status:</span>
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_COLORS[selectedSubscription.status]}`}
                  >
                    {selectedSubscription.status}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Billing Cycle:</span>
                  <span>{selectedSubscription.billing_cycle}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Price:</span>
                  <span>
                    {formatCurrency(
                      selectedSubscription.price,
                      selectedSubscription.currency
                    )}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Trial Ends:</span>
                  <span>{formatDate(selectedSubscription.trial_ends_at)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Period Start:</span>
                  <span>
                    {formatDate(selectedSubscription.current_period_start)}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Period End:</span>
                  <span>
                    {formatDate(selectedSubscription.current_period_end)}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Last Payment:</span>
                  <span>{formatDate(selectedSubscription.last_payment_at)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Next Payment:</span>
                  <span>{formatDate(selectedSubscription.next_payment_due)}</span>
                </div>
                {selectedSubscription.stripe_subscription_id && (
                  <div className="flex justify-between">
                    <span className="text-gray-500">Stripe ID:</span>
                    <span className="font-mono text-xs">
                      {selectedSubscription.stripe_subscription_id}
                    </span>
                  </div>
                )}
                {selectedSubscription.notes && (
                  <div className="border-t pt-3">
                    <span className="text-gray-500">Notes:</span>
                    <p className="mt-1 text-sm text-gray-700">
                      {selectedSubscription.notes}
                    </p>
                  </div>
                )}
              </div>

              <div className="mt-6 flex justify-end">
                <button
                  onClick={() => {
                    setShowActionModal(false)
                    setSelectedSubscription(null)
                  }}
                  className="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
