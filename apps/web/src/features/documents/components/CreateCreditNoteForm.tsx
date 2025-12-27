/**
 * CreateCreditNoteForm Component
 * Form for creating credit notes from posted invoices
 * Supports both amount-based and line-based credit note creation
 */

import { useState, useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { AlertCircle, Check } from 'lucide-react'
import { useCreateCreditNote } from '../hooks/useCreditNotes'
import type { InvoiceForCreditNote } from '@/types/creditNote'

// Credit mode enum
type CreditMode = 'amount' | 'line'

// Zod validation schema for amount-based mode
const amountBasedSchema = z.object({
  amount: z
    .string()
    .min(1, 'sales.creditNotes.form.amountRequired')
    .refine((val) => parseFloat(val) > 0, 'sales.creditNotes.form.amountPositive'),
  reason: z.enum([
    'return',
    'price_adjustment',
    'billing_error',
    'damaged_goods',
    'service_issue',
    'other',
  ]),
  notes: z.string().optional(),
})

// Line selection state for line-based mode
interface LineSelection {
  lineId: string
  quantity: number
  maxQuantity: number
  unitPrice: number
  description: string
}

type CreditNoteFormData = z.infer<typeof amountBasedSchema>

interface CreateCreditNoteFormProps {
  invoice: InvoiceForCreditNote & {
    lines?: Array<{
      id: string
      product_id: string
      product_code: string
      description: string
      quantity: number
      unit_price: string
      tax_rate: string
      total: string
    }>
  }
  onSuccess?: () => void
  onCancel?: () => void
}

/**
 * Form component for creating credit notes
 *
 * Features:
 * - Mode toggle: Amount-based vs Line-based
 * - Amount validation (positive, not exceeding invoice total/remaining)
 * - Line selection with quantity validation
 * - Reason selection (6 predefined reasons)
 * - Notes field
 * - Full refund quick action
 * - Pessimistic form submission
 */
export function CreateCreditNoteForm({
  invoice,
  onSuccess,
  onCancel,
}: CreateCreditNoteFormProps) {
  const { t } = useTranslation(['sales', 'common'])
  const createCreditNote = useCreateCreditNote()

  // Credit mode state
  const [creditMode, setCreditMode] = useState<CreditMode>('amount')

  // Line selections for line-based mode
  const [selectedLines, setSelectedLines] = useState<Map<string, number>>(new Map())

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<CreditNoteFormData>({
    resolver: zodResolver(amountBasedSchema),
    defaultValues: {
      amount: '',
      notes: '',
    },
  })

  const amountValue = watch('amount')
  const reasonValue = watch('reason')

  // Calculate remaining creditable amount
  const remainingCreditable = parseFloat(invoice.balance_due || invoice.total)
  const invoiceTotal = parseFloat(invoice.total)

  // Validate amount against invoice total and remaining creditable
  const validateAmount = (amount: string): string | null => {
    const numAmount = parseFloat(amount)

    if (isNaN(numAmount) || numAmount <= 0) {
      return t('sales:creditNotes.form.amountPositive')
    }

    if (numAmount > invoiceTotal) {
      return t('sales:creditNotes.errors.exceedsInvoiceTotal')
    }

    if (numAmount > remainingCreditable) {
      return t('sales:creditNotes.errors.exceedsRemainingBalance')
    }

    return null
  }

  const customAmountError = amountValue ? validateAmount(amountValue) : null

  // Calculate line-based credit total
  const lineBasedTotal = useMemo(() => {
    if (!invoice.lines || selectedLines.size === 0) return 0

    let total = 0
    selectedLines.forEach((quantity, lineId) => {
      const line = invoice.lines?.find(l => l.id === lineId)
      if (line && quantity > 0) {
        const unitPrice = parseFloat(line.unit_price)
        const taxRate = parseFloat(line.tax_rate)
        const subtotal = unitPrice * quantity
        const tax = subtotal * (taxRate / 100)
        total += subtotal + tax
      }
    })

    return total
  }, [invoice.lines, selectedLines])

  // Toggle line selection
  const handleToggleLine = (lineId: string, maxQuantity: number, unitPrice: number) => {
    setSelectedLines(prev => {
      const newMap = new Map(prev)
      if (newMap.has(lineId)) {
        newMap.delete(lineId)
      } else {
        newMap.set(lineId, maxQuantity)
      }
      return newMap
    })
  }

  // Update line quantity
  const handleUpdateLineQuantity = (lineId: string, quantity: number, maxQuantity: number) => {
    if (quantity < 0 || quantity > maxQuantity) return

    setSelectedLines(prev => {
      const newMap = new Map(prev)
      if (quantity === 0) {
        newMap.delete(lineId)
      } else {
        newMap.set(lineId, quantity)
      }
      return newMap
    })
  }

  // Handle form submission
  const onSubmit = (data: CreditNoteFormData) => {
    if (creditMode === 'amount') {
      // Amount-based credit note
      const amountError = validateAmount(data.amount)
      if (amountError) {
        return
      }

      createCreditNote.mutate(
        {
          source_invoice_id: invoice.id,
          amount: parseFloat(data.amount).toFixed(4),
          reason: data.reason as any,
          ...(data.notes ? { notes: data.notes } : {}),
        },
        {
          onSuccess: () => {
            onSuccess?.()
          },
        }
      )
    } else {
      // Line-based credit note
      if (selectedLines.size === 0) {
        return
      }

      // Build lines array for API
      const lines = Array.from(selectedLines.entries()).map(([lineId, quantity]) => ({
        line_id: lineId,
        quantity,
      }))

      createCreditNote.mutate(
        {
          source_invoice_id: invoice.id,
          reason: data.reason as any,
          lines,
          ...(data.notes ? { notes: data.notes } : {}),
        } as any,
        {
          onSuccess: () => {
            onSuccess?.()
          },
        }
      )
    }
  }

  // Handle full refund button
  const handleFullRefund = () => {
    setValue('amount', remainingCreditable.toFixed(2))
  }

  const isSubmitting = createCreditNote.isPending

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      {/* Header */}
      <div className="border-b border-gray-200 pb-4">
        <h2 className="text-lg font-medium text-gray-900">
          {t('sales:creditNotes.createFromInvoice')}
        </h2>
        <div className="mt-2 text-sm text-gray-600">
          <p>
            <span className="font-medium">{t('sales:documents.number')}:</span> {invoice.document_number}
          </p>
          <p>
            <span className="font-medium">{t('sales:documents.total')}:</span>{' '}
            {invoiceTotal.toFixed(2)}
          </p>
          <p className="text-blue-700">
            {t('sales:creditNotes.form.remainingCreditable', {
              amount: remainingCreditable.toFixed(2),
            })}
          </p>
        </div>
      </div>

      {/* Credit Mode Selector */}
      {invoice.lines && invoice.lines.length > 0 && (
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('sales:creditNotes.form.mode')}
          </label>
          <div className="flex gap-4">
            <button
              type="button"
              onClick={() => { setCreditMode('amount'); }}
              className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors ${
                creditMode === 'amount'
                  ? 'border-blue-500 bg-blue-50 text-blue-700'
                  : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
              }`}
              disabled={isSubmitting}
            >
              <div className="flex items-center justify-center gap-2">
                {creditMode === 'amount' && <Check className="h-4 w-4" />}
                {t('sales:creditNotes.form.amountBased')}
              </div>
            </button>
            <button
              type="button"
              onClick={() => { setCreditMode('line'); }}
              className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors ${
                creditMode === 'line'
                  ? 'border-blue-500 bg-blue-50 text-blue-700'
                  : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
              }`}
              disabled={isSubmitting}
            >
              <div className="flex items-center justify-center gap-2">
                {creditMode === 'line' && <Check className="h-4 w-4" />}
                {t('sales:creditNotes.form.lineBased')}
              </div>
            </button>
          </div>
        </div>
      )}

      {/* Amount-Based Mode */}
      {creditMode === 'amount' && (
        <div>
          <label htmlFor="amount" className="block text-sm font-medium text-gray-700">
            {t('sales:creditNotes.amount')}
          </label>
          <div className="mt-1 flex gap-2">
            <input
              {...register('amount')}
              type="number"
              step="0.01"
              id="amount"
              className={`flex-1 rounded-md border ${
                errors.amount || customAmountError
                  ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                  : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
              } px-3 py-2 text-sm`}
              disabled={isSubmitting}
              placeholder="0.00"
            />
            <button
              type="button"
              onClick={handleFullRefund}
              className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              disabled={isSubmitting}
            >
              {t('sales:creditNotes.messages.fullRefund', 'Full Refund')}
            </button>
          </div>
          {(errors.amount || customAmountError) && (
            <p className="mt-1 text-sm text-red-600">
              {errors.amount ? t(errors.amount.message!) : customAmountError}
            </p>
          )}
          <p className="mt-1 text-xs text-gray-500">
            {t('sales:creditNotes.form.maxAmount', { amount: remainingCreditable.toFixed(2) })}
          </p>
        </div>
      )}

      {/* Line-Based Mode */}
      {creditMode === 'line' && invoice.lines && (
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('sales:creditNotes.form.selectLines')}
          </label>

          <div className="rounded-md border border-gray-300 overflow-hidden">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="w-10 px-3 py-2"></th>
                  <th className="px-3 py-2 text-start text-xs font-medium text-gray-500">
                    {t('sales:lineItems.item')}
                  </th>
                  <th className="px-3 py-2 text-end text-xs font-medium text-gray-500">
                    {t('sales:lineItems.quantity')}
                  </th>
                  <th className="px-3 py-2 text-end text-xs font-medium text-gray-500">
                    {t('sales:creditNotes.form.creditQuantity')}
                  </th>
                  <th className="px-3 py-2 text-end text-xs font-medium text-gray-500">
                    {t('sales:lineItems.unitPrice')}
                  </th>
                  <th className="px-3 py-2 text-end text-xs font-medium text-gray-500">
                    {t('sales:lineItems.total')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {invoice.lines.map((line) => {
                  const isSelected = selectedLines.has(line.id)
                  const creditQty = selectedLines.get(line.id) || line.quantity
                  const unitPrice = parseFloat(line.unit_price)
                  const taxRate = parseFloat(line.tax_rate)
                  const subtotal = unitPrice * creditQty
                  const tax = subtotal * (taxRate / 100)
                  const total = subtotal + tax

                  return (
                    <tr key={line.id} className={isSelected ? 'bg-blue-50' : ''}>
                      <td className="px-3 py-2 text-center">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => { handleToggleLine(line.id, line.quantity, unitPrice); }}
                          className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                          disabled={isSubmitting}
                        />
                      </td>
                      <td className="px-3 py-2 text-sm">
                        <div className="font-medium text-gray-900">{line.product_code}</div>
                        <div className="text-gray-500">{line.description}</div>
                      </td>
                      <td className="px-3 py-2 text-end text-sm text-gray-900">
                        {line.quantity}
                      </td>
                      <td className="px-3 py-2 text-end">
                        {isSelected ? (
                          <input
                            type="number"
                            min="1"
                            max={line.quantity}
                            value={creditQty}
                            onChange={(e) => { handleUpdateLineQuantity(
                              line.id,
                              parseInt(e.target.value) || 0,
                              line.quantity
                            ); }}
                            className="w-20 rounded border-gray-300 px-2 py-1 text-sm text-end"
                            disabled={isSubmitting}
                          />
                        ) : (
                          <span className="text-sm text-gray-400">-</span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-end text-sm text-gray-900">
                        {unitPrice.toFixed(2)}
                      </td>
                      <td className="px-3 py-2 text-end text-sm font-medium text-gray-900">
                        {isSelected ? total.toFixed(2) : '-'}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
              <tfoot className="bg-gray-50">
                <tr>
                  <td colSpan={5} className="px-3 py-2 text-end text-sm font-medium text-gray-900">
                    {t('sales:documents.total')}
                  </td>
                  <td className="px-3 py-2 text-end text-sm font-bold text-gray-900">
                    {lineBasedTotal.toFixed(2)}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          {selectedLines.size === 0 && (
            <p className="mt-1 text-sm text-red-600">
              {t('sales:creditNotes.form.noLinesSelected')}
            </p>
          )}
        </div>
      )}

      {/* Reason Field */}
      <div>
        <label htmlFor="reason" className="block text-sm font-medium text-gray-700">
          {t('sales:creditNotes.reason.title')}
        </label>
        <select
          {...register('reason')}
          id="reason"
          className={`mt-1 block w-full rounded-md border ${
            errors.reason
              ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
              : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
          } px-3 py-2 text-sm`}
          disabled={isSubmitting}
        >
          <option value="">{t('common:select', 'Select...')}</option>
          <option value="return">{t('sales:creditNotes.reason.return')}</option>
          <option value="price_adjustment">{t('sales:creditNotes.reason.priceAdjustment')}</option>
          <option value="billing_error">{t('sales:creditNotes.reason.billingError')}</option>
          <option value="damaged_goods">{t('sales:creditNotes.reason.damagedGoods')}</option>
          <option value="service_issue">{t('sales:creditNotes.reason.serviceIssue')}</option>
          <option value="other">{t('sales:creditNotes.reason.other')}</option>
        </select>
        {errors.reason && (
          <p className="mt-1 text-sm text-red-600">{t(errors.reason.message!)}</p>
        )}
      </div>

      {/* Notes Field */}
      <div>
        <label htmlFor="notes" className="block text-sm font-medium text-gray-700">
          {t('sales:creditNotes.notes')}
        </label>
        <textarea
          {...register('notes')}
          id="notes"
          rows={3}
          className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
          placeholder={t('sales:creditNotes.notesPlaceholder')}
          disabled={isSubmitting}
        />
      </div>

      {/* Error Display */}
      {createCreditNote.isError && (
        <div className="rounded-md bg-red-50 p-4">
          <div className="flex">
            <AlertCircle className="h-5 w-5 text-red-400" />
            <div className="ms-3">
              <p className="text-sm text-red-800">
                {createCreditNote.error?.message ||
                  t('sales:creditNotes.messages.createFailed')}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Action Buttons */}
      <div className="flex justify-end gap-3">
        <button
          type="button"
          onClick={onCancel}
          className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          disabled={isSubmitting}
        >
          {t('common:actions.cancel')}
        </button>
        <button
          type="submit"
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:bg-gray-400"
          disabled={
            isSubmitting ||
            (creditMode === 'amount' && !!customAmountError) ||
            (creditMode === 'line' && selectedLines.size === 0) ||
            !reasonValue
          }
        >
          {isSubmitting ? t('common:status.saving', 'Saving...') : t('common:actions.save')}
        </button>
      </div>
    </form>
  )
}
