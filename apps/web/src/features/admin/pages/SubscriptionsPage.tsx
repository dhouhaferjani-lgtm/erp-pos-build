import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useSubscriptions, useUpdateSubscription, usePlans } from '../hooks/useBilling'
import type { Subscription, SubscriptionStatus } from '../types'
import { StatusBadge, type StatusTone } from '@/components/atoms/StatusBadge/StatusBadge'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

const STATUS_TONES: Record<SubscriptionStatus, StatusTone> = {
  trial: 'info',
  active: 'success',
  past_due: 'warning',
  unpaid: 'danger',
  paused: 'warning',
  cancelling: 'neutral',
  cancelled: 'neutral',
  expired: 'danger',
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
        <div className={`${colorClasses.textGray500}`}>Loading subscriptions...</div>
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
              className={`text-sm ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}
            >
              &larr; Back to Billing
            </Link>
            <h1 className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>
              Subscriptions
            </h1>
          </div>
          <div className={`text-sm ${colorClasses.textGray500}`}>
            {subscriptionsData?.total ?? 0} total subscriptions
          </div>
        </div>

        {/* Filters */}
        <div className="mb-6 flex gap-4">
          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); }}
            className={`rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
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
            onChange={(e) => { setPlanFilter(e.target.value); }}
            className={`rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
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
          <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
            <thead className={`${colorClasses.bgGray50}`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Tenant
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Plan
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Status
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Billing
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Period End
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
              {subscriptionsData?.data.map((subscription) => (
                <tr key={subscription.id} className={`${colorClasses.hoverBgGray50}`}>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className={`font-medium ${colorClasses.textGray900}`}>
                      {subscription.tenant?.name ?? 'Unknown'}
                    </div>
                    <div className={`text-sm ${colorClasses.textGray500}`}>
                      {subscription.tenant?.email ?? ''}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className={`${colorClasses.textGray900}`}>
                      {subscription.plan?.name ?? 'Unknown'}
                    </div>
                    <div className={`text-sm ${colorClasses.textGray500}`}>
                      {formatCurrency(subscription.price, subscription.currency)}
                      /{subscription.billing_cycle === 'monthly' ? 'mo' : 'yr'}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <StatusBadge tone={STATUS_TONES[subscription.status]}>
                      {subscription.status.replace('_', ' ')}
                    </StatusBadge>
                    {subscription.status === 'trial' &&
                      subscription.trial_ends_at && (
                        <div className={`mt-1 text-xs ${colorClasses.textGray500}`}>
                          Ends {formatDate(subscription.trial_ends_at)}
                        </div>
                      )}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                    {subscription.billing_cycle}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray900}`}>
                    {formatDate(subscription.current_period_end)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex gap-2">
                      {subscription.status === 'active' && (
                        <button
                          onClick={() =>
                            { handleStatusChange(subscription, 'paused'); }
                          }
                          className={`text-sm ${colorClasses.textYellow600} ${colorClasses.hoverTextYellow800}`}
                        >
                          Pause
                        </button>
                      )}
                      {subscription.status === 'paused' && (
                        <button
                          onClick={() =>
                            { handleStatusChange(subscription, 'active'); }
                          }
                          className={`text-sm ${colorClasses.textGreen600} ${colorClasses.hoverTextGreen800}`}
                        >
                          Resume
                        </button>
                      )}
                      {!['cancelled', 'expired'].includes(
                        subscription.status
                      ) && (
                        <button
                          onClick={() =>
                            { handleStatusChange(subscription, 'cancelled'); }
                          }
                          className={`text-sm ${colorClasses.textRed600} ${colorClasses.hoverTextRed800}`}
                        >
                          Cancel
                        </button>
                      )}
                      <button
                        onClick={() => {
                          setSelectedSubscription(subscription)
                          setShowActionModal(true)
                        }}
                        className={`text-sm ${colorClasses.textBlue600} ${colorClasses.hoverTextBlue800}`}
                      >
                        Details
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          {subscriptionsData?.data.length === 0 && (
            <div className={`py-12 text-center ${colorClasses.textGray500}`}>
              No subscriptions found
            </div>
          )}
        </div>

        {/* Details Modal */}
        {showActionModal && selectedSubscription && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
              <h2 className={`mb-4 text-xl font-bold ${colorClasses.textGray900}`}>
                Subscription Details
              </h2>

              <div className="space-y-3">
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>ID:</span>
                  <span className="font-mono text-sm">
                    {selectedSubscription.id}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Tenant:</span>
                  <span>{selectedSubscription.tenant?.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Plan:</span>
                  <span>{selectedSubscription.plan?.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Status:</span>
                  <StatusBadge tone={STATUS_TONES[selectedSubscription.status]}>
                    {selectedSubscription.status}
                  </StatusBadge>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Billing Cycle:</span>
                  <span>{selectedSubscription.billing_cycle}</span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Price:</span>
                  <span>
                    {formatCurrency(
                      selectedSubscription.price,
                      selectedSubscription.currency
                    )}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Trial Ends:</span>
                  <span>{formatDate(selectedSubscription.trial_ends_at)}</span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Period Start:</span>
                  <span>
                    {formatDate(selectedSubscription.current_period_start)}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Period End:</span>
                  <span>
                    {formatDate(selectedSubscription.current_period_end)}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Last Payment:</span>
                  <span>{formatDate(selectedSubscription.last_payment_at)}</span>
                </div>
                <div className="flex justify-between">
                  <span className={`${colorClasses.textGray500}`}>Next Payment:</span>
                  <span>{formatDate(selectedSubscription.next_payment_due)}</span>
                </div>
                {selectedSubscription.stripe_subscription_id && (
                  <div className="flex justify-between">
                    <span className={`${colorClasses.textGray500}`}>Stripe ID:</span>
                    <span className="font-mono text-xs">
                      {selectedSubscription.stripe_subscription_id}
                    </span>
                  </div>
                )}
                {selectedSubscription.notes && (
                  <div className="border-t pt-3">
                    <span className={`${colorClasses.textGray500}`}>Notes:</span>
                    <p className={`mt-1 text-sm ${colorClasses.textGray700}`}>
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
                  className={`rounded-md ${colorClasses.bgGray100} px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray200}`}
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
