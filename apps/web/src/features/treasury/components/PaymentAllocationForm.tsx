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
  const { decimals } = useCurrency()

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

  // Calculate total manual allocations
  const totalManualAllocations = useMemo(() => {
    return manualAllocations.reduce((sum, allocation) => {
      return sum + parseFloat(allocation.amount || '0')
    }, 0)
  }, [manualAllocations])

  // Format amount to currency-aware decimals
  const formatAmount = (amount: string | number): string => {
    return parseFloat(String(amount)).toFixed(decimals)
  }

  // Validation: Check if manual allocations exceed payment amount
  const exceedsPaymentAmount = useMemo(() => {
    if (allocationMethod !== AllocationMethod.MANUAL) return false
    return totalManualAllocations > parseFloat(paymentAmount)
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

  return (
    <div className="space-y-6">
      {/* Header with Payment Amount */}
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('treasury:smartPayment.allocation.title')}
          </h2>
          <div className="text-right">
            <p className="text-sm text-gray-600">
              {t('treasury:smartPayment.allocation.paymentAmount')}
            </p>
            <p className="text-xl font-bold text-gray-900">{formatAmount(paymentAmount)}</p>
          </div>
        </div>
      </div>

      {/* Allocation Method Selection */}
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <h3 className="mb-4 text-sm font-medium text-gray-900">
          {t('treasury:smartPayment.allocation.method')}
        </h3>
        <div className="space-y-3">
          {/* FIFO Option */}
          <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 transition-colors hover:bg-gray-50">
            <input
              type="radio"
              name="allocation-method"
              value={AllocationMethod.FIFO}
              checked={allocationMethod === AllocationMethod.FIFO}
              onChange={() => { handleMethodChange(AllocationMethod.FIFO); }}
              className="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <div className="flex-1">
              <p className="font-medium text-gray-900">
                {t('treasury:smartPayment.allocation.fifo')}
              </p>
              <p className="text-sm text-gray-600">
                {t('treasury:smartPayment.allocation.fifoDescription')}
              </p>
            </div>
          </label>

          {/* Due Date Option */}
          <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 transition-colors hover:bg-gray-50">
            <input
              type="radio"
              name="allocation-method"
              value={AllocationMethod.DUE_DATE}
              checked={allocationMethod === AllocationMethod.DUE_DATE}
              onChange={() => { handleMethodChange(AllocationMethod.DUE_DATE); }}
              className="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <div className="flex-1">
              <p className="font-medium text-gray-900">
                {t('treasury:smartPayment.allocation.dueDate')}
              </p>
              <p className="text-sm text-gray-600">
                {t('treasury:smartPayment.allocation.dueDateDescription')}
              </p>
            </div>
          </label>

          {/* Manual Option */}
          <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 transition-colors hover:bg-gray-50">
            <input
              type="radio"
              name="allocation-method"
              value={AllocationMethod.MANUAL}
              checked={allocationMethod === AllocationMethod.MANUAL}
              onChange={() => { handleMethodChange(AllocationMethod.MANUAL); }}
              className="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <div className="flex-1">
              <p className="font-medium text-gray-900">
                {t('treasury:smartPayment.allocation.manual')}
              </p>
              <p className="text-sm text-gray-600">
                {t('treasury:smartPayment.allocation.manualDescription')}
              </p>
            </div>
          </label>
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
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-gray-700">
              {t('treasury:smartPayment.allocation.totalAllocated', {
                amount: formatAmount(totalManualAllocations),
              })}
            </span>
            {exceedsPaymentAmount && (
              <div className="flex items-center gap-2 text-red-600">
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
          <button
            type="button"
            onClick={handlePreview}
            disabled={previewMutation.isPending}
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {previewMutation.isPending
              ? t('common:status.loading')
              : t('treasury:smartPayment.allocation.previewButton')}
          </button>

          {/* Apply Button */}
          <button
            type="button"
            onClick={handleApply}
            disabled={isApplyDisabled}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {applyMutation.isPending
              ? t('common:status.loading')
              : t('treasury:smartPayment.allocation.applyButton')}
          </button>
        </div>

        {/* Cancel Button */}
        {onCancel && (
          <button
            type="button"
            onClick={onCancel}
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
          >
            {t('common:actions.cancel')}
          </button>
        )}
      </div>

      {/* Validation Error for Manual Mode */}
      {allocationMethod === 'manual' && manualAllocations.length === 0 && (
        <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-3">
          <div className="flex items-center gap-2">
            <AlertCircle className="h-5 w-5 text-yellow-600" />
            <p className="text-sm text-yellow-800">
              {t('treasury:smartPayment.allocation.noInvoicesSelected')}
            </p>
          </div>
        </div>
      )}
    </div>
  )
}
