/**
 * CreateReturnNoteForm Component
 * Form for creating return notes from invoices or delivery notes
 * Supports both full document return and line-based partial returns
 */

import { useState, useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { AlertCircle, Check } from 'lucide-react'
import { useCreateReturnNote } from '../hooks/useReturnNotes'
import { ReturnReasonSelect } from './ReturnReasonSelect'
import { ReturnConditionSelect } from './ReturnConditionSelect'
import { RefundMethodSelect } from './RefundMethodSelect'
import type { ReturnReason } from './ReturnReasonSelect'
import type { ReturnCondition } from './ReturnConditionSelect'
import type { RefundMethod } from './RefundMethodSelect'

// Return mode enum
type ReturnMode = 'full' | 'partial'

// Zod validation schema
const createReturnNoteSchema = z.object({
  notes: z.string().optional(),
})

type ReturnNoteFormData = z.infer<typeof createReturnNoteSchema>

interface CreateReturnNotePayload {
  return_reason: ReturnReason
  source_invoice_id?: string
  source_delivery_note_id?: string
  return_condition?: ReturnCondition
  refund_method?: RefundMethod
  notes?: string
  auto_create_credit_note?: boolean
  lines?: Array<{ line_id: string; quantity: number }>
}

interface SourceDocumentLine {
  id: string
  product_id: string
  product_code: string
  product_name: string
  description: string
  quantity: number
  unit_price: string
  tax_rate: string
  total: string
}

interface SourceDocumentForReturn {
  id: string
  document_number: string
  document_date: string
  partner_name: string
  total: string
  lines?: SourceDocumentLine[]
}

interface CreateReturnNoteFormProps {
  sourceDocument: SourceDocumentForReturn
  sourceType: 'invoice' | 'delivery_note'
  onSuccess?: () => void
  onCancel?: () => void
}

/**
 * Form component for creating return notes
 *
 * Features:
 * - Return mode toggle: Full document vs Partial (line-based)
 * - Return reason, condition, and refund method selection
 * - Line selection with quantity input for partial returns
 * - Auto-create credit note option
 * - Validation (return qty ≤ original qty)
 * - Pessimistic form submission
 */
export function CreateReturnNoteForm({
  sourceDocument,
  sourceType,
  onSuccess,
  onCancel,
}: CreateReturnNoteFormProps) {
  const { t } = useTranslation(['sales', 'common'])
  const createReturnNote = useCreateReturnNote()

  // Form state
  const [returnMode, setReturnMode] = useState<ReturnMode>('full')
  const [returnReason, setReturnReason] = useState<ReturnReason | ''>('')
  const [returnCondition, setReturnCondition] = useState<ReturnCondition | ''>('')
  const [refundMethod, setRefundMethod] = useState<RefundMethod | ''>('')
  const [autoCreateCreditNote, setAutoCreateCreditNote] = useState(false)
  const [selectedLines, setSelectedLines] = useState<Map<string, number>>(new Map())

  const {
    register,
    handleSubmit,
  } = useForm<ReturnNoteFormData>({
    resolver: zodResolver(createReturnNoteSchema),
    defaultValues: {
      notes: '',
    },
  })

  const hasLines = sourceDocument.lines && sourceDocument.lines.length > 0

  // Calculate partial return total
  const partialReturnTotal = useMemo(() => {
    if (!sourceDocument.lines || selectedLines.size === 0) return 0

    let total = 0
    selectedLines.forEach((quantity, lineId) => {
      const line = sourceDocument.lines?.find(l => l.id === lineId)
      if (line && quantity > 0) {
        const unitPrice = parseFloat(line.unit_price)
        const taxRate = parseFloat(line.tax_rate)
        const subtotal = unitPrice * quantity
        const tax = subtotal * (taxRate / 100)
        total += subtotal + tax
      }
    })

    return total
  }, [sourceDocument.lines, selectedLines])

  // Toggle line selection
  const handleToggleLine = (lineId: string, maxQuantity: number) => {
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

  // Validation
  const canSubmit = () => {
    if (!returnReason) return false
    if (returnMode === 'partial' && selectedLines.size === 0) return false
    return true
  }

  // Handle form submission
  const onSubmit = (data: ReturnNoteFormData) => {
    if (!canSubmit()) return

    const payload: CreateReturnNotePayload = {
      return_reason: returnReason as ReturnReason,
      ...(sourceType === 'invoice'
        ? { source_invoice_id: sourceDocument.id }
        : { source_delivery_note_id: sourceDocument.id }
      ),
      ...(returnCondition && { return_condition: returnCondition }),
      ...(refundMethod && { refund_method: refundMethod }),
      ...(data.notes && { notes: data.notes }),
      auto_create_credit_note: autoCreateCreditNote,
    }

    // Add lines for partial return
    if (returnMode === 'partial' && selectedLines.size > 0) {
      payload.lines = Array.from(selectedLines.entries()).map(([lineId, quantity]) => ({
        line_id: lineId,
        quantity,
      }))
    }

    createReturnNote.mutate(payload, {
      onSuccess: () => {
        onSuccess?.()
      },
    })
  }

  const isSubmitting = createReturnNote.isPending

  return (
    <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
      {/* Header */}
      <div className="border-b border-gray-200 pb-4">
        <h2 className="text-lg font-medium text-gray-900">
          {sourceType === 'invoice'
            ? t('sales:returnNotes.createFromInvoice')
            : t('sales:returnNotes.createFromDelivery')}
        </h2>
        <div className="mt-2 text-sm text-gray-600">
          <p>
            <span className="font-medium">{t('sales:documents.number')}:</span>{' '}
            {sourceDocument.document_number}
          </p>
          <p>
            <span className="font-medium">{t('sales:documents.partner')}:</span>{' '}
            {sourceDocument.partner_name}
          </p>
          <p>
            <span className="font-medium">{t('sales:documents.total')}:</span>{' '}
            {parseFloat(sourceDocument.total).toFixed(2)}
          </p>
        </div>
      </div>

      {/* Return Mode Selector */}
      {hasLines && (
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('sales:returnNotes.form.returnMode', 'Return Mode')}
          </label>
          <div className="flex gap-4">
            <button
              type="button"
              onClick={() => { setReturnMode('full'); }}
              className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors ${
                returnMode === 'full'
                  ? 'border-blue-500 bg-blue-50 text-blue-700'
                  : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
              }`}
              disabled={isSubmitting}
            >
              <div className="flex items-center justify-center gap-2">
                {returnMode === 'full' && <Check className="h-4 w-4" />}
                {t('sales:returnNotes.form.fullReturn', 'Full Return')}
              </div>
            </button>
            <button
              type="button"
              onClick={() => { setReturnMode('partial'); }}
              className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors ${
                returnMode === 'partial'
                  ? 'border-blue-500 bg-blue-50 text-blue-700'
                  : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
              }`}
              disabled={isSubmitting}
            >
              <div className="flex items-center justify-center gap-2">
                {returnMode === 'partial' && <Check className="h-4 w-4" />}
                {t('sales:returnNotes.form.partialReturn', 'Partial Return')}
              </div>
            </button>
          </div>
        </div>
      )}

      {/* Partial Return - Line Selection */}
      {returnMode === 'partial' && sourceDocument.lines && (
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t('sales:returnNotes.form.selectLinesToReturn', 'Select Lines to Return')}
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
                    {t('sales:returnNotes.form.returnQuantity', 'Return Qty')}
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
                {sourceDocument.lines.map((line) => {
                  const isSelected = selectedLines.has(line.id)
                  const returnQty = selectedLines.get(line.id) || line.quantity
                  const unitPrice = parseFloat(line.unit_price)
                  const taxRate = parseFloat(line.tax_rate)
                  const subtotal = unitPrice * returnQty
                  const tax = subtotal * (taxRate / 100)
                  const total = subtotal + tax

                  return (
                    <tr key={line.id} className={isSelected ? 'bg-blue-50' : ''}>
                      <td className="px-3 py-2 text-center">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => { handleToggleLine(line.id, line.quantity); }}
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
                            value={returnQty}
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
                    {t('sales:returnNotes.form.returnTotal', 'Return Total')}
                  </td>
                  <td className="px-3 py-2 text-end text-sm font-bold text-gray-900">
                    {partialReturnTotal.toFixed(2)}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          {selectedLines.size === 0 && (
            <p className="mt-1 text-sm text-red-600">
              {t('sales:returnNotes.form.noLinesSelected', 'Please select at least one line to return')}
            </p>
          )}
        </div>
      )}

      {/* Return Reason Field (Required) */}
      <ReturnReasonSelect
        value={returnReason}
        onChange={setReturnReason}
        required
        disabled={isSubmitting}
        {...(!returnReason && isSubmitting && { error: t('sales:returnNotes.form.reasonRequired') })}
      />

      {/* Return Condition Field (Optional) */}
      <ReturnConditionSelect
        value={returnCondition}
        onChange={setReturnCondition}
        disabled={isSubmitting}
      />

      {/* Refund Method Field (Optional) */}
      <RefundMethodSelect
        value={refundMethod}
        onChange={setRefundMethod}
        disabled={isSubmitting}
      />

      {/* Auto-Create Credit Note Checkbox */}
      {sourceType === 'invoice' && (
        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
          <label className="flex items-start gap-3">
            <input
              type="checkbox"
              checked={autoCreateCreditNote}
              onChange={(e) => { setAutoCreateCreditNote(e.target.checked); }}
              disabled={isSubmitting}
              className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <div className="flex-1">
              <span className="text-sm font-medium text-gray-900">
                {t('sales:returnNotes.form.autoCreateCreditNote', 'Automatically create credit note')}
              </span>
              <p className="mt-1 text-sm text-gray-500">
                {t('sales:returnNotes.form.autoCreateCreditNoteHint', 'A credit note will be created automatically when this return is confirmed')}
              </p>
            </div>
          </label>
        </div>
      )}

      {/* Notes Field */}
      <div>
        <label htmlFor="notes" className="block text-sm font-medium text-gray-700">
          {t('sales:returnNotes.notes')}
        </label>
        <textarea
          {...register('notes')}
          id="notes"
          rows={3}
          className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
          placeholder={t('sales:returnNotes.notesPlaceholder')}
          disabled={isSubmitting}
        />
      </div>

      {/* Error Display */}
      {createReturnNote.isError && (
        <div className="rounded-md bg-red-50 p-4">
          <div className="flex">
            <AlertCircle className="h-5 w-5 text-red-400" />
            <div className="ms-3">
              <p className="text-sm text-red-800">
                {createReturnNote.error.message || t('sales:returnNotes.messages.createFailed')}
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
          disabled={isSubmitting || !canSubmit()}
        >
          {isSubmitting ? t('common:status.saving', 'Saving...') : t('common:actions.save')}
        </button>
      </div>
    </form>
  )
}
