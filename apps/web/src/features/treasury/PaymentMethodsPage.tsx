import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, CreditCard, Check, Banknote } from 'lucide-react'
import { useQueryClient, useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { usePaymentMethods } from './hooks/usePaymentMethods'
import { AddPaymentMethodModal } from './components/AddPaymentMethodModal'
import { api } from '../../lib/api'
import { cn } from '../../lib/utils'
import { tokens, textColors } from '../../lib/designTokens'
import { Button, StatusBadge, statusTone } from '../../components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '../../components/molecules'

interface PaymentMethodExtended {
  id: string
  code: string
  name: string
  description: string | null
  instrument_kind: 'cheque' | 'effet' | 'other' | null
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

// Capability badge — module scope so React preserves it across renders.
function CapabilityBadge({ enabled, label }: { enabled: boolean; label: string }) {
  if (!enabled) return null
  return (
    <span className={cn(tokens.badge.base, tokens.badge.blue)}>{label}</span>
  )
}

export function PaymentMethodsPage() {
  const { t } = useTranslation(['common', 'treasury'])
  const queryClient = useQueryClient()
  const [showAddModal, setShowAddModal] = useState(false)
  // Route-level protection via RequirePermission moduleKey="treasury" handles access control

  const { data: rawMethods = [], isLoading, error } = usePaymentMethods()

  // The list endpoint returns the extended shape (code/description/fee fields);
  // the hook types the narrow shape, so normalize each row to the extended view.
  const methods = rawMethods.map((m) => m as PaymentMethodExtended)

  const activeMethods = methods.filter((m) => m.is_active)

  // Toggle active/inactive mutation
  const toggleActiveMutation = useMutation({
    mutationFn: async ({ id, isActive }: { id: string; isActive: boolean }) => {
      await api.patch(`/payment-methods/${id}`, { is_active: !isActive })
    },
    onSuccess: async (_data, variables) => {
      await queryClient.invalidateQueries({ queryKey: ['payment-methods'] })
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
      return method.fixed_fee_amount
    }
    if (method.fee_type === 'percentage' && method.variable_fee_percentage) {
      return `${method.variable_fee_percentage}%`
    }
    if (method.fee_type === 'mixed' && method.fixed_fee_amount && method.variable_fee_percentage) {
      return `${method.fixed_fee_amount} + ${method.variable_fee_percentage}%`
    }
    return t('treasury:paymentMethods.feeTypes.none')
  }

  const columns: DataTableColumn<PaymentMethodExtended>[] = [
    {
      key: 'name',
      header: t('treasury:paymentMethods.table.name'),
      render: (method) => (
        <div>
          <div className={cn('font-medium', textColors.primary)}>{method.name}</div>
          {method.description && (
            <div className={cn('text-sm', textColors.tertiary)}>{method.description}</div>
          )}
        </div>
      ),
    },
    {
      key: 'code',
      header: t('treasury:paymentMethods.table.code'),
      render: (method) => (
        <span className={cn('font-mono text-sm', textColors.tertiary)}>
          {method.code || method.id}
        </span>
      ),
    },
    {
      key: 'capabilities',
      header: t('treasury:paymentMethods.table.capabilities'),
      render: (method) => (
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
      ),
    },
    {
      key: 'fees',
      header: t('treasury:paymentMethods.table.fees'),
      render: (method) => (
        <span className={cn('text-sm', textColors.tertiary)}>{getFeeDisplay(method)}</span>
      ),
    },
    {
      key: 'status',
      header: t('treasury:paymentMethods.table.status'),
      render: (method) => (
        <StatusBadge tone={statusTone(method.is_active ? 'active' : 'inactive')}>
          {method.is_active
            ? t('treasury:paymentMethods.active')
            : t('treasury:paymentMethods.inactive')}
        </StatusBadge>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('treasury:paymentMethods.table.actions')}</span>,
      align: 'right',
      render: (method) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={() => { handleToggleActive(method.id, method.is_active) }}
          disabled={toggleActiveMutation.isPending}
        >
          {method.is_active ? t('common:actions.deactivate') : t('common:actions.activate')}
        </Button>
      ),
    },
  ]

  return (
    <ListPageLayout
      title={t('treasury:paymentMethods.title')}
      subtitle={`${String(methods.length)} ${
        methods.length === 1
          ? t('treasury:paymentMethods.singular')
          : t('treasury:paymentMethods.plural')
      }${
        activeMethods.length !== methods.length
          ? ` (${String(activeMethods.length)} ${t('treasury:paymentMethods.active').toLowerCase()})`
          : ''
      }`}
      actions={
        <Button className="gap-2" onClick={() => { setShowAddModal(true) }}>
          <Plus className="h-4 w-4" />
          {t('treasury:paymentMethods.new')}
        </Button>
      }
    >
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('common:errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : !isLoading && methods.length === 0 ? (
        <div className="py-6">
          <EmptyState
            icon={<CreditCard className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
            title={t('treasury:paymentMethods.empty.title')}
            description={t('treasury:paymentMethods.empty.description')}
          />
          <div className="mt-6 flex justify-center">
            <Button className="gap-2" onClick={() => { setShowAddModal(true) }}>
              <Plus className="h-4 w-4" />
              {t('treasury:paymentMethods.new')}
            </Button>
          </div>
        </div>
      ) : (
        <div className="space-y-6">
          {/* Summary Stats */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className={tokens.card.base}>
              <div className="flex items-center gap-3">
                <div className={cn('rounded-lg p-2', tokens.badge.green)}>
                  <Check className="h-5 w-5" />
                </div>
                <div>
                  <p className={cn('text-sm', textColors.tertiary)}>
                    {t('treasury:paymentMethods.active')}
                  </p>
                  <p className={cn('text-lg font-semibold', textColors.primary)}>
                    {activeMethods.length}
                  </p>
                </div>
              </div>
            </div>

            <div className={tokens.card.base}>
              <div className="flex items-center gap-3">
                <div className={cn('rounded-lg p-2', tokens.badge.gray)}>
                  <CreditCard className="h-5 w-5" />
                </div>
                <div>
                  <p className={cn('text-sm', textColors.tertiary)}>{t('common:fields.total')}</p>
                  <p className={cn('text-lg font-semibold', textColors.primary)}>
                    {methods.length}
                  </p>
                </div>
              </div>
            </div>

            <div className={tokens.card.base}>
              <div className="flex items-center gap-3">
                <div className={cn('rounded-lg p-2', tokens.badge.purple)}>
                  <Banknote className="h-5 w-5" />
                </div>
                <div>
                  <p className={cn('text-sm', textColors.tertiary)}>
                    {t('treasury:paymentMethods.flags.is_physical')}
                  </p>
                  <p className={cn('text-lg font-semibold', textColors.primary)}>
                    {methods.filter((m) => m.is_physical).length}
                  </p>
                </div>
              </div>
            </div>

            <div className={tokens.card.base}>
              <div className="flex items-center gap-3">
                <div className={cn('rounded-lg p-2', tokens.badge.yellow)}>
                  <CreditCard className="h-5 w-5" />
                </div>
                <div>
                  <p className={cn('text-sm', textColors.tertiary)}>
                    {t('treasury:paymentMethods.flags.has_maturity')}
                  </p>
                  <p className={cn('text-lg font-semibold', textColors.primary)}>
                    {methods.filter((m) => m.has_maturity).length}
                  </p>
                </div>
              </div>
            </div>
          </div>

          {/* Payment Methods Table */}
          <DataTable
            columns={columns}
            data={methods}
            keyExtractor={(method) => method.id}
            isLoading={isLoading}
            emptyTitle={t('treasury:paymentMethods.empty.title')}
            emptyDescription={t('treasury:paymentMethods.empty.description')}
          />
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
    </ListPageLayout>
  )
}
