import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, CreditCard, Check, Banknote } from 'lucide-react'
import { useQueryClient, useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { usePaymentMethods } from './hooks/usePaymentMethods'
import { AddPaymentMethodModal } from './components/AddPaymentMethodModal'
import { api } from '../../lib/api'

interface PaymentMethodExtended {
  id: string
  code: string
  name: string
  description: string | null
  is_physical: boolean
  has_maturity: boolean
  requires_third_party: boolean
  is_push: boolean
  has_deducted_fees: boolean
  is_restricted: boolean
  is_active: boolean
  fee_type: 'none' | 'fixed' | 'percentage' | 'mixed'
  fixed_fee_amount: string | null
  variable_fee_percentage: string | null
}

export function PaymentMethodsPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const queryClient = useQueryClient()
  const [showAddModal, setShowAddModal] = useState(false)
  // Route-level protection via RequirePermission moduleKey="treasury" handles access control

  const { data: methods = [], isLoading, error } = usePaymentMethods()

  const activeMethods = methods.filter((m) => m.is_active)
  const inactiveMethods = methods.filter((m) => !m.is_active)

  // Toggle active/inactive mutation
  const toggleActiveMutation = useMutation({
    mutationFn: async ({ id, isActive }: { id: string; isActive: boolean }) => {
      await api.patch(`/payment-methods/${id}`, { is_active: !isActive })
    },
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
      toast.success(
        variables.isActive
          ? t('treasury:paymentMethods.messages.deactivated')
          : t('treasury:paymentMethods.messages.activated')
      )
    },
    onError: () => {
      toast.error(t('common:errors.generic'))
    },
  })

  const handleToggleActive = (id: string, isActive: boolean) => {
    toggleActiveMutation.mutate({ id, isActive })
  }

  // Get fee display text
  const getFeeDisplay = (method: PaymentMethodExtended): string => {
    if (method.fee_type === 'none') {
      return t('treasury:paymentMethods.feeTypes.none')
    }
    if (method.fee_type === 'fixed' && method.fixed_fee_amount) {
      return `${method.fixed_fee_amount}`
    }
    if (method.fee_type === 'percentage' && method.variable_fee_percentage) {
      return `${method.variable_fee_percentage}%`
    }
    if (method.fee_type === 'mixed' && method.fixed_fee_amount && method.variable_fee_percentage) {
      return `${method.fixed_fee_amount} + ${method.variable_fee_percentage}%`
    }
    return t('treasury:paymentMethods.feeTypes.none')
  }

  // Capability badge component
  const CapabilityBadge = ({ enabled, label }: { enabled: boolean; label: string }) => {
    if (!enabled) return null
    return (
      <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
        {label}
      </span>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('treasury:paymentMethods.title')}
          </h1>
          <p className="text-gray-500">
            {methods.length} {methods.length === 1 ? t('treasury:paymentMethods.singular') : t('treasury:paymentMethods.plural')}
            {activeMethods.length !== methods.length && ` (${activeMethods.length} ${t('treasury:paymentMethods.active').toLowerCase()})`}
          </p>
        </div>
        <button
          onClick={() => { setShowAddModal(true) }}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          {t('treasury:paymentMethods.new')}
        </button>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('common:status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('common:errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : methods.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <CreditCard className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {t('treasury:paymentMethods.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {t('treasury:paymentMethods.empty.description')}
          </p>
          <div className="mt-6">
            <button
              onClick={() => { setShowAddModal(true) }}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              <Plus className="h-4 w-4" />
              {t('treasury:paymentMethods.new')}
            </button>
          </div>
        </div>
      ) : (
        <div className="space-y-6">
          {/* Summary Stats */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="rounded-lg border border-gray-200 bg-white p-4">
              <div className="flex items-center gap-3">
                <div className="rounded-lg bg-green-100 p-2 text-green-800">
                  <Check className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-sm text-gray-500">{t('treasury:paymentMethods.active')}</p>
                  <p className="text-lg font-semibold text-gray-900">{activeMethods.length}</p>
                </div>
              </div>
            </div>

            <div className="rounded-lg border border-gray-200 bg-white p-4">
              <div className="flex items-center gap-3">
                <div className="rounded-lg bg-gray-100 p-2 text-gray-800">
                  <CreditCard className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-sm text-gray-500">{t('common:fields.total')}</p>
                  <p className="text-lg font-semibold text-gray-900">{methods.length}</p>
                </div>
              </div>
            </div>

            <div className="rounded-lg border border-gray-200 bg-white p-4">
              <div className="flex items-center gap-3">
                <div className="rounded-lg bg-purple-100 p-2 text-purple-800">
                  <Banknote className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-sm text-gray-500">
                    {t('treasury:paymentMethods.flags.is_physical')}
                  </p>
                  <p className="text-lg font-semibold text-gray-900">
                    {methods.filter((m) => m.is_physical).length}
                  </p>
                </div>
              </div>
            </div>

            <div className="rounded-lg border border-gray-200 bg-white p-4">
              <div className="flex items-center gap-3">
                <div className="rounded-lg bg-orange-100 p-2 text-orange-800">
                  <CreditCard className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-sm text-gray-500">
                    {t('treasury:paymentMethods.flags.has_maturity')}
                  </p>
                  <p className="text-lg font-semibold text-gray-900">
                    {methods.filter((m) => m.has_maturity).length}
                  </p>
                </div>
              </div>
            </div>
          </div>

          {/* Payment Methods Table */}
          <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:paymentMethods.table.name')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:paymentMethods.table.code')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:paymentMethods.table.capabilities')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:paymentMethods.table.fees')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:paymentMethods.table.status')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('treasury:paymentMethods.table.actions')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {methods.map((method) => (
                  <tr key={method.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4">
                      <div>
                        <div className="font-medium text-gray-900">{method.name}</div>
                        {(method as PaymentMethodExtended).description && (
                          <div className="text-sm text-gray-500">
                            {(method as PaymentMethodExtended).description}
                          </div>
                        )}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span className="font-mono text-sm text-gray-600">
                        {(method as PaymentMethodExtended).code ?? method.id}
                      </span>
                    </td>
                    <td className="px-6 py-4">
                      <div className="flex flex-wrap gap-1">
                        <CapabilityBadge
                          enabled={method.is_physical}
                          label={t('treasury:paymentMethods.flags.is_physical')}
                        />
                        <CapabilityBadge
                          enabled={method.has_maturity}
                          label={t('treasury:paymentMethods.flags.has_maturity')}
                        />
                        <CapabilityBadge
                          enabled={method.requires_third_party}
                          label={t('treasury:paymentMethods.flags.requires_third_party')}
                        />
                        <CapabilityBadge
                          enabled={method.is_push}
                          label={t('treasury:paymentMethods.flags.is_push')}
                        />
                        <CapabilityBadge
                          enabled={method.has_deducted_fees}
                          label={t('treasury:paymentMethods.flags.has_deducted_fees')}
                        />
                        <CapabilityBadge
                          enabled={method.is_restricted}
                          label={t('treasury:paymentMethods.flags.is_restricted')}
                        />
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                      {getFeeDisplay(method as PaymentMethodExtended)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span
                        className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                          method.is_active
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {method.is_active
                          ? t('treasury:paymentMethods.active')
                          : t('treasury:paymentMethods.inactive')}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                      <button
                        onClick={() => { handleToggleActive(method.id, method.is_active) }}
                        disabled={toggleActiveMutation.isPending}
                        className="text-blue-600 hover:text-blue-900 disabled:opacity-50"
                      >
                        {method.is_active ? t('common:actions.deactivate') : t('common:actions.activate')}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Show inactive methods separately if there are any */}
          {inactiveMethods.length > 0 && (
            <details className="rounded-lg border border-gray-200 bg-white p-4">
              <summary className="cursor-pointer font-medium text-gray-700">
                {t('treasury:paymentMethods.inactive')} ({inactiveMethods.length})
              </summary>
              <div className="mt-4 space-y-2">
                {inactiveMethods.map((method) => (
                  <div
                    key={method.id}
                    className="flex items-center justify-between rounded-lg bg-gray-50 p-3"
                  >
                    <span className="text-sm text-gray-600">{method.name}</span>
                    <button
                      onClick={() => { handleToggleActive(method.id, method.is_active) }}
                      disabled={toggleActiveMutation.isPending}
                      className="text-sm text-blue-600 hover:text-blue-900 disabled:opacity-50"
                    >
                      {t('common:actions.activate')}
                    </button>
                  </div>
                ))}
              </div>
            </details>
          )}
        </div>
      )}

      {/* Add Payment Method Modal */}
      <AddPaymentMethodModal
        isOpen={showAddModal}
        onClose={() => { setShowAddModal(false) }}
        onSuccess={() => {
          void queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
        }}
      />
    </div>
  )
}
