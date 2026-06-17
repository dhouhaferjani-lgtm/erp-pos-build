/**
 * PaymentAllocationForm Component
 * Core component for smart payment allocation with preview and application
 */

import { useState, useMemo, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle } from 'lucide-react'
import { OpenInvoicesList } from './OpenInvoicesList'
import { AllocationPreview } from './AllocationPreview'
import { usePaymentAllocationPreview, useApplyAllocation } from '../hooks/useSmartPayment'
import { AllocationMethod, type OpenInvoice, type ManualAllocation } from '@/types/treasury'
import { useCurrency } from '@/hooks/useCurrency'
import { Button } from '@/components/atoms/Button'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { bcadd, bccomp, formatCurrency } from '@/lib/decimal'

interface PaymentAllocationFormProps {
  paymentId: string
  partnerId: string
  paymentAmount: string
  invoices: OpenInvoice[]
  onSuccess?: () => void
  onCancel?: () => void
}

/**
 * Payment allocation form with FIFO/Due Date/Manual methods
 *
 * Features:
 * - Allocation method selection (radio buttons)
 * - Integration with OpenInvoicesList for manual selection
 * - Preview functionality before applying
 * - Apply allocation with pessimistic mutation
 * - Validation for manual allocations
 */
export function PaymentAllocationForm({
  paymentId,
  partnerId,
  paymentAmount,
  invoices,
  onSuccess,
  onCancel,
}: PaymentAllocationFormProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const { currency, decimals } = useCurrency()

  // State
  const [allocationMethod, setAllocationMethod] = useState<AllocationMethod>(AllocationMethod.FIFO)
  const [manualAllocations, setManualAllocations] = useState<ManualAllocation[]>([])

  // Mutations
  const previewMutation = usePaymentAllocationPreview()
  const applyMutation = useApplyAllocation()

  // Reset preview when allocation method changes
  useEffect(() => {
    previewMutation.reset?.()
  }, [allocationMethod])

  // Calculate total manual allocations (canonical decimal string — never a float)
  const totalManualAllocations = useMemo(() => {
    return manualAllocations.reduce<string>((sum, allocation) => {
      return bcadd(sum, allocation.amount || '0', decimals)
    }, '0')
  }, [manualAllocations, decimals])

  // Format amount to currency-aware decimals
  const formatAmount = (amount: string | number): string => {
    return formatCurrency(amount, false, currency, decimals)
  }

  // Validation: Check if manual allocations exceed payment amount
  const exceedsPaymentAmount = useMemo(() => {
    if (allocationMethod !== AllocationMethod.MANUAL) return false
    return bccomp(totalManualAllocations, paymentAmount) > 0
  }, [allocationMethod, totalManualAllocations, paymentAmount])

  // Validation: Check if apply button should be disabled
  const isApplyDisabled = useMemo(() => {
    if (applyMutation.isPending) return true
    if (allocationMethod === AllocationMethod.MANUAL && manualAllocations.length === 0) return true
    if (exceedsPaymentAmount) return true
    return false
  }, [allocationMethod, manualAllocations, exceedsPaymentAmount, applyMutation.isPending])

  // Handle allocation method change
  const handleMethodChange = (method: AllocationMethod) => {
    setAllocationMethod(method)
    setManualAllocations([]) // Reset manual allocations when switching methods
  }

  // Handle preview button click
  const handlePreview = () => {
    previewMutation.mutate({
      partner_id: partnerId,
      payment_amount: paymentAmount,
      allocation_method: allocationMethod,
      ...(allocationMethod === 'manual' && { manual_allocations: manualAllocations }),
    })
  }

  // Handle apply button click
  const handleApply = () => {
    applyMutation.mutate(
      {
        payment_id: paymentId,
        allocation_method: allocationMethod,
        ...(allocationMethod === 'manual' && { manual_allocations: manualAllocations }),
      },
      {
        onSuccess: () => {
          onSuccess?.()
        },
      }
    )
  }

  const methodOptions: readonly {
    method: AllocationMethod
    label: string
    description: string
  }[] = [
    {
      method: AllocationMethod.FIFO,
      label: t('treasury:smartPayment.allocation.fifo'),
      description: t('treasury:smartPayment.allocation.fifoDescription'),
    },
    {
      method: AllocationMethod.DUE_DATE,
      label: t('treasury:smartPayment.allocation.dueDate'),
      description: t('treasury:smartPayment.allocation.dueDateDescription'),
    },
    {
      method: AllocationMethod.MANUAL,
      label: t('treasury:smartPayment.allocation.manual'),
      description: t('treasury:smartPayment.allocation.manualDescription'),
    },
  ]

  return (
    <div className="space-y-6">
      {/* Header with Payment Amount */}
      <div className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
        <div className="flex items-center justify-between">
          <h2 className={tokens.heading.section}>
            {t('treasury:smartPayment.allocation.title')}
          </h2>
          <div className="text-right">
            <p className={cn('text-sm', textColors.tertiary)}>
              {t('treasury:smartPayment.allocation.paymentAmount')}
            </p>
            <p className={cn('text-xl font-bold tabular-nums', textColors.primary)}>
              {formatAmount(paymentAmount)}
            </p>
          </div>
        </div>
      </div>

      {/* Allocation Method Selection */}
      <div className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
        <h3 className={cn('mb-4 text-sm font-medium', textColors.primary)}>
          {t('treasury:smartPayment.allocation.method')}
        </h3>
        <div className="space-y-3">
          {methodOptions.map(({ method, label, description }) => (
            <label
              key={method}
              className={cn(
                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                borderColors.light,
                colors.hover.gray50
              )}
            >
              <input
                type="radio"
                name="allocation-method"
                value={method}
                checked={allocationMethod === method}
                onChange={() => {
                  handleMethodChange(method)
                }}
                className={cn('mt-1', tokens.radio.base)}
              />
              <div className="flex-1">
                <p className={cn('font-medium', textColors.primary)}>{label}</p>
                <p className={cn('text-sm', textColors.tertiary)}>{description}</p>
              </div>
            </label>
          ))}
        </div>
      </div>

      {/* Open Invoices List */}
      <OpenInvoicesList
        partnerId={partnerId}
        invoices={invoices}
        allocationMethod={allocationMethod}
        selectedAllocations={manualAllocations}
        onAllocationChange={setManualAllocations}
      />

      {/* Manual Allocations Summary */}
      {allocationMethod === 'manual' && manualAllocations.length > 0 && (
        <div className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
          <div className="flex items-center justify-between">
            <span className={cn('text-sm font-medium', textColors.secondary)}>
              {t('treasury:smartPayment.allocation.totalAllocated', {
                amount: formatAmount(totalManualAllocations),
              })}
            </span>
            {exceedsPaymentAmount && (
              <div className={cn('flex items-center gap-2', textColors.error)}>
                <AlertCircle className="h-4 w-4" />
                <span className="text-sm font-medium">
                  {t('treasury:smartPayment.allocation.exceedsPayment')}
                </span>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Allocation Preview */}
      {previewMutation.data && (
        <AllocationPreview preview={previewMutation.data} isLoading={previewMutation.isPending} />
      )}

      {/* Action Buttons */}
      <div className="flex items-center justify-between gap-4">
        <div className="flex gap-3">
          {/* Preview Button */}
          <Button
            type="button"
            variant="secondary"
            onClick={handlePreview}
            disabled={previewMutation.isPending}
          >
            {previewMutation.isPending
              ? t('common:status.loading')
              : t('treasury:smartPayment.allocation.previewButton')}
          </Button>

          {/* Apply Button */}
          <Button type="button" variant="primary" onClick={handleApply} disabled={isApplyDisabled}>
            {applyMutation.isPending
              ? t('common:status.loading')
              : t('treasury:smartPayment.allocation.applyButton')}
          </Button>
        </div>

        {/* Cancel Button */}
        {onCancel && (
          <Button type="button" variant="secondary" onClick={onCancel}>
            {t('common:actions.cancel')}
          </Button>
        )}
      </div>

      {/* Validation Error for Manual Mode */}
      {allocationMethod === 'manual' && manualAllocations.length === 0 && (
        <div className={cn(tokens.alert.base, tokens.alert.warning)}>
          <div className="flex items-center gap-2">
            <AlertCircle className="h-5 w-5" />
            <p className="text-sm">{t('treasury:smartPayment.allocation.noInvoicesSelected')}</p>
          </div>
        </div>
      )}
    </div>
  )
}
