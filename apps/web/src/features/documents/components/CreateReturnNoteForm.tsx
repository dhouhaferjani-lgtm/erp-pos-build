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
import { useCurrency } from '@/hooks/useCurrency'
import { useCreateReturnNote } from '../hooks/useReturnNotes'
import { ReturnReasonSelect } from './ReturnReasonSelect'
import { ReturnConditionSelect } from './ReturnConditionSelect'
import { RefundMethodSelect } from './RefundMethodSelect'
import type { ReturnReason } from './ReturnReasonSelect'
import type { ReturnCondition } from './ReturnConditionSelect'
import type { RefundMethod } from './RefundMethodSelect'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'

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
  quantity_decimals?: number | null
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
  const { decimals } = useCurrency()

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
      <div className={`border-b ${colorClasses.borderGray200} pb-4`}>
        <h2 className={`text-lg font-medium ${colorClasses.textGray900}`}>
          {sourceType === 'invoice'
            ? t('sales:returnNotes.createFromInvoice')
            : t('sales:returnNotes.createFromDelivery')}
        </h2>
        <div className={`mt-2 text-sm ${colorClasses.textGray600}`}>
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
            {parseFloat(sourceDocument.total).toFixed(decimals)}
          </p>
        </div>
      </div>

      {/* Return Mode Selector */}
      {hasLines && (
        <div>
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('sales:returnNotes.form.returnMode', 'Return Mode')}
          </label>
          <div className="flex gap-4">
            <button
              type="button"
              onClick={() => { setReturnMode('full'); }}
              className={`flex-1 rounded-lg border px-4 py-3 text-sm font-medium transition-colors ${
                returnMode === 'full'
                  ? `${colorClasses.borderBlue500} ${colorClasses.bgBlue50} ${colorClasses.textBlue700}`
                  : `${colorClasses.borderGray300} bg-white ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`
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
                  ? `${colorClasses.borderBlue500} ${colorClasses.bgBlue50} ${colorClasses.textBlue700}`
                  : `${colorClasses.borderGray300} bg-white ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`
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
          <label className={`block text-sm font-medium ${colorClasses.textGray700} mb-2`}>
            {t('sales:returnNotes.form.selectLinesToReturn', 'Select Lines to Return')}
          </label>

          <div className={`rounded-md border ${colorClasses.borderGray300} overflow-hidden`}>
            <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
              <thead className={`${colorClasses.bgGray50}`}>
                <tr>
                  <th className="w-10 px-3 py-2"></th>
                  <th className={`px-3 py-2 text-start text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.item')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.quantity')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:returnNotes.form.returnQuantity', 'Return Qty')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.unitPrice')}
                  </th>
                  <th className={`px-3 py-2 text-end text-xs font-medium ${colorClasses.textGray500}`}>
                    {t('sales:lineItems.total')}
                  </th>
                </tr>
              </thead>
              <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
                {sourceDocument.lines.map((line) => {
                  const isSelected = selectedLines.has(line.id)
                  const returnQty = selectedLines.get(line.id) || line.quantity
                  const unitPrice = parseFloat(line.unit_price)
                  const taxRate = parseFloat(line.tax_rate)
                  const subtotal = unitPrice * returnQty
                  const tax = subtotal * (taxRate / 100)
                  const total = subtotal + tax

                  return (
                    <tr key={line.id} className={isSelected ? `${colorClasses.bgBlue50}` : ''}>
                      <td className="px-3 py-2 text-center">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => { handleToggleLine(line.id, line.quantity); }}
                          className={`h-4 w-4 rounded ${colorClasses.borderGray300} ${colorClasses.textBlue600} ${colorClasses.focusRingBlue500}`}
                          disabled={isSubmitting}
                        />
                      </td>
                      <td className="px-3 py-2 text-sm">
                        <div className={`font-medium ${colorClasses.textGray900}`}>{line.product_code}</div>
                        <div className={`${colorClasses.textGray500}`}>{line.description}</div>
                      </td>
                      <td className={`px-3 py-2 text-end text-sm ${colorClasses.textGray900}`}>
                        {formatQuantity(line.quantity, getQuantityDecimals(line))}
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
                            className={`w-20 rounded ${colorClasses.borderGray300} px-2 py-1 text-sm text-end`}
                            disabled={isSubmitting}
                          />
                        ) : (
                          <span className={`text-sm ${colorClasses.textGray400}`}>-</span>
                        )}
                      </td>
                      <td className={`px-3 py-2 text-end text-sm ${colorClasses.textGray900}`}>
                        {unitPrice.toFixed(decimals)}
                      </td>
                      <td className={`px-3 py-2 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                        {isSelected ? total.toFixed(decimals) : '-'}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
              <tfoot className={`${colorClasses.bgGray50}`}>
                <tr>
                  <td colSpan={5} className={`px-3 py-2 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                    {t('sales:returnNotes.form.returnTotal', 'Return Total')}
                  </td>
                  <td className={`px-3 py-2 text-end text-sm font-bold ${colorClasses.textGray900}`}>
                    {partialReturnTotal.toFixed(decimals)}
                  </td>
                </tr>
              </tfoot>
            </DataTable>
          </div>

          {selectedLines.size === 0 && (
            <p className={`mt-1 text-sm ${colorClasses.textRed600}`}>
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
        <div className={`rounded-lg border ${colorClasses.borderGray200} ${colorClasses.bgGray50} p-4`}>
          <label className="flex items-start gap-3">
            <input
              type="checkbox"
              checked={autoCreateCreditNote}
              onChange={(e) => { setAutoCreateCreditNote(e.target.checked); }}
              disabled={isSubmitting}
              className={`mt-1 h-4 w-4 rounded ${colorClasses.borderGray300} ${colorClasses.textBlue600} ${colorClasses.focusRingBlue500}`}
            />
            <div className="flex-1">
              <span className={`text-sm font-medium ${colorClasses.textGray900}`}>
                {t('sales:returnNotes.form.autoCreateCreditNote', 'Automatically create credit note')}
              </span>
              <p className={`mt-1 text-sm ${colorClasses.textGray500}`}>
                {t('sales:returnNotes.form.autoCreateCreditNoteHint', 'A credit note will be created automatically when this return is confirmed')}
              </p>
            </div>
          </label>
        </div>
      )}

      {/* Notes Field */}
      <div>
        <label htmlFor="notes" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
          {t('sales:returnNotes.notes')}
        </label>
        <textarea
          {...register('notes')}
          id="notes"
          rows={3}
          className={`mt-1 block w-full rounded-md border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} ${colorClasses.focusRingBlue500}`}
          placeholder={t('sales:returnNotes.notesPlaceholder')}
          disabled={isSubmitting}
        />
      </div>

      {/* Error Display */}
      {createReturnNote.isError && (
        <div className={`rounded-md ${colorClasses.bgRed50} p-4`}>
          <div className="flex">
            <AlertCircle className={`h-5 w-5 ${colorClasses.textRed400}`} />
            <div className="ms-3">
              <p className={`text-sm ${colorClasses.textRed800}`}>
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
          className={`rounded-md border ${colorClasses.borderGray300} bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50}`}
          disabled={isSubmitting}
        >
          {t('common:actions.cancel')}
        </button>
        <button
          type="submit"
          className={`rounded-md ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700} ${colorClasses.disabledBgGray400}`}
          disabled={isSubmitting || !canSubmit()}
        >
          {isSubmitting ? t('common:status.saving', 'Saving...') : t('common:actions.save')}
        </button>
      </div>
    </form>
  )
}
