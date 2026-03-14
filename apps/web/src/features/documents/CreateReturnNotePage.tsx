/**
 * CreateReturnNotePage Component
 * Create return notes from delivery notes or invoices
 * Matches invoice/quote form patterns - document search drives the form
 */

import { useState, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm, Controller } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient, useQuery } from '@tanstack/react-query'
import { ArrowLeft, AlertCircle } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import { InvoiceSearchSelect } from '@/components/ui/InvoiceSearchSelect'
import { DeliveryNoteSearchSelect } from '@/components/ui/DeliveryNoteSearchSelect'
import { ReturnReasonSelect } from './components/ReturnReasonSelect'
import { ReturnConditionSelect } from './components/ReturnConditionSelect'
import { RefundMethodSelect } from './components/RefundMethodSelect'
import type { Invoice } from '@/components/ui/InvoiceSearchSelect'
import type { DeliveryNote } from '@/components/ui/DeliveryNoteSearchSelect'
import type { ReturnReason } from '@/types/returnNote'

// Source type for return note
type SourceType = 'delivery_note' | 'invoice'
type LineMode = 'all' | 'partial'

// Validation schema
const returnNoteSchema = z.object({
  source_invoice_id: z.string().optional(),
  source_delivery_note_id: z.string().optional(),
  return_reason: z.enum(['defective', 'wrong_item', 'customer_regret', 'damaged_in_transit', 'warranty', 'exchange', 'other']),
  return_condition: z.enum(['unopened', 'used', 'damaged', 'unusable']).optional(),
  refund_method: z.enum(['original_payment', 'store_credit', 'exchange', 'none']).optional(),
  notes: z.string().optional(),
  auto_create_credit_note: z.boolean().default(false),
}).refine(
  (data) => data.source_invoice_id || data.source_delivery_note_id,
  {
    message: 'Please select a source document',
    path: ['source_invoice_id'],
  }
)

type ReturnNoteFormData = {
  return_reason: 'defective' | 'wrong_item' | 'customer_regret' | 'damaged_in_transit' | 'warranty' | 'exchange' | 'other'
  source_invoice_id?: string | undefined
  source_delivery_note_id?: string | undefined
  return_condition?: 'unopened' | 'used' | 'damaged' | 'unusable' | undefined
  refund_method?: 'original_payment' | 'store_credit' | 'exchange' | 'none' | undefined
  notes?: string | undefined
  auto_create_credit_note: boolean
}

interface DocumentLine {
  id: string
  product_id: string
  product_code: string
  description: string
  quantity: number
  unit_price: string
  tax_rate: string
  total: string
}

export function CreateReturnNotePage() {
  const { t } = useTranslation(['sales', 'common'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Form state
  const [sourceType, setSourceType] = useState<SourceType>('delivery_note')
  const [lineMode, setLineMode] = useState<LineMode>('all')
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null)
  const [selectedDeliveryNote, setSelectedDeliveryNote] = useState<DeliveryNote | null>(null)
  const [selectedLineIds, setSelectedLineIds] = useState<Set<string>>(new Set())
  const [lineQuantities, setLineQuantities] = useState<Map<string, number>>(new Map())

  // React Hook Form
  const {
    control,
    handleSubmit,
    setValue,
    formState: { errors },
  } = useForm<ReturnNoteFormData>({
    resolver: zodResolver(returnNoteSchema) as never,
    defaultValues: {
      return_reason: 'defective',
      auto_create_credit_note: false,
    },
  })

  type DocumentDetailResponse = {
    document_number: string
    document_date: string
    partner?: { id: string; name: string } | null
    total: string
    lines: DocumentLine[]
  }

  // Fetch selected invoice details
  const { data: invoiceDetails } = useQuery({
    queryKey: ['invoice', selectedInvoice?.id],
    queryFn: async () => {
      const response = await api.get<{ data: DocumentDetailResponse }>(`/invoices/${selectedInvoice?.id}`)
      return response.data.data
    },
    enabled: !!selectedInvoice?.id && sourceType === 'invoice',
  })

  // Fetch selected delivery note details
  const { data: deliveryNoteDetails } = useQuery({
    queryKey: ['delivery-note', selectedDeliveryNote?.id],
    queryFn: async () => {
      const response = await api.get<{ data: DocumentDetailResponse }>(`/delivery-notes/${selectedDeliveryNote?.id}`)
      return response.data.data
    },
    enabled: !!selectedDeliveryNote?.id && sourceType === 'delivery_note',
  })

  // Get current document and lines
  const currentDocument = sourceType === 'invoice' ? invoiceDetails : deliveryNoteDetails
  const documentLines = currentDocument?.lines || []
  const hasDocument = !!currentDocument

  // Calculate partial return total
  const partialReturnTotal = useMemo(() => {
    if (selectedLineIds.size === 0) return 0

    let total = 0
    selectedLineIds.forEach((lineId) => {
      const line = documentLines.find(l => l.id === lineId)
      if (line) {
        const quantity = lineQuantities.get(lineId) || line.quantity
        const unitPrice = parseFloat(line.unit_price)
        const taxRate = parseFloat(line.tax_rate)
        const subtotal = unitPrice * quantity
        const tax = subtotal * (taxRate / 100)
        total += subtotal + tax
      }
    })

    return total
  }, [documentLines, selectedLineIds, lineQuantities])

  // Toggle line selection
  const handleToggleLine = (lineId: string) => {
    setSelectedLineIds((prev) => {
      const newSet = new Set(prev)
      if (newSet.has(lineId)) {
        newSet.delete(lineId)
        setLineQuantities((prevQty) => {
          const newMap = new Map(prevQty)
          newMap.delete(lineId)
          return newMap
        })
      } else {
        newSet.add(lineId)
        const line = documentLines.find(l => l.id === lineId)
        if (line) {
          setLineQuantities((prevQty) => {
            const newMap = new Map(prevQty)
            newMap.set(lineId, line.quantity)
            return newMap
          })
        }
      }
      return newSet
    })
  }

  // Update line quantity
  const handleQuantityChange = (lineId: string, value: number, maxQuantity: number) => {
    const quantity = Math.max(1, Math.min(value, maxQuantity))
    setLineQuantities((prev) => {
      const newMap = new Map(prev)
      newMap.set(lineId, quantity)
      return newMap
    })
  }

  // Create return note mutation
  const createMutation = useMutation({
    mutationFn: async (data: ReturnNoteFormData) => {
      const payload: any = {
        return_reason: data.return_reason,
        ...(data.return_condition && { return_condition: data.return_condition }),
        ...(data.refund_method && { refund_method: data.refund_method }),
        ...(data.notes && { notes: data.notes }),
        auto_create_credit_note: data.auto_create_credit_note,
      }

      // Add source document
      if (sourceType === 'invoice' && selectedInvoice) {
        payload.source_invoice_id = selectedInvoice.id
      } else if (sourceType === 'delivery_note' && selectedDeliveryNote) {
        payload.source_delivery_note_id = selectedDeliveryNote.id
      }

      // Add lines for partial return
      if (lineMode === 'partial' && selectedLineIds.size > 0) {
        payload.lines = Array.from(selectedLineIds).map((lineId) => ({
          line_id: lineId,
          quantity: lineQuantities.get(lineId) || 0,
        }))
      }

      const response = await api.post('/return-notes', payload)
      return response.data
    },
    onSuccess: () => {
      toast.success(t('sales:returnNotes.messages.created'))
      void queryClient.invalidateQueries({ queryKey: ['return-notes'] })
      navigate('/sales/return-notes')
    },
    onError: (error: Error) => {
      toast.error(error.message || t('sales:returnNotes.messages.createFailed'))
    },
  })

  const onSubmit = (data: ReturnNoteFormData) => {
    // Validate line selection for partial mode
    if (lineMode === 'partial' && selectedLineIds.size === 0) {
      toast.error(t('sales:returnNotes.form.noLinesSelected'))
      return
    }

    createMutation.mutate(data)
  }

  const isSubmitting = createMutation.isPending

  return (
    <div className="mx-auto max-w-5xl p-6">
      {/* Header */}
      <div className="mb-6 flex items-center gap-4">
        <button
          onClick={() => { navigate('/sales/return-notes') }}
          className="flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:actions.back')}
        </button>
        <div className="flex-1">
          <h1 className="text-2xl font-bold text-gray-900">
            {t('sales:returnNotes.new')}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {t('sales:returnNotes.createDescription')}
          </p>
        </div>
      </div>

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        {/* 1. Source Document Selection */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-medium text-gray-900">
            {t('sales:returnNotes.form.selectSource')}
          </h2>

          {/* Document Type Toggle */}
          <div className="mb-4">
            <div className="inline-flex rounded-lg border border-gray-200 p-1">
              <button
                type="button"
                onClick={() => {
                  setSourceType('delivery_note')
                  setSelectedInvoice(null)
                  setValue('source_invoice_id', undefined)
                  setValue('auto_create_credit_note', false)
                  setSelectedLineIds(new Set())
                  setLineQuantities(new Map())
                }}
                disabled={isSubmitting}
                className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                  sourceType === 'delivery_note'
                    ? 'bg-blue-600 text-white'
                    : 'text-gray-700 hover:bg-gray-100'
                }`}
              >
                {t('sales:documents.types.delivery_note')}
              </button>
              <button
                type="button"
                onClick={() => {
                  setSourceType('invoice')
                  setSelectedDeliveryNote(null)
                  setValue('source_delivery_note_id', undefined)
                  setSelectedLineIds(new Set())
                  setLineQuantities(new Map())
                }}
                disabled={isSubmitting}
                className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                  sourceType === 'invoice'
                    ? 'bg-blue-600 text-white'
                    : 'text-gray-700 hover:bg-gray-100'
                }`}
              >
                {t('sales:documents.types.invoice')}
              </button>
            </div>
          </div>

          {/* Document Search */}
          {sourceType === 'delivery_note' ? (
            <Controller
              name="source_delivery_note_id"
              control={control}
              render={({ field }) => (
                <DeliveryNoteSearchSelect
                  value={selectedDeliveryNote}
                  onChange={(dn) => {
                    setSelectedDeliveryNote(dn)
                    field.onChange(dn?.id)
                    setSelectedLineIds(new Set())
                    setLineQuantities(new Map())
                    setLineMode('all')
                  }}
                  label={t('sales:documents.types.delivery_note')}
                  required
                  error={errors.source_delivery_note_id?.message}
                  disabled={isSubmitting}
                />
              )}
            />
          ) : (
            <Controller
              name="source_invoice_id"
              control={control}
              render={({ field }) => (
                <InvoiceSearchSelect
                  value={selectedInvoice}
                  onChange={(invoice) => {
                    setSelectedInvoice(invoice)
                    field.onChange(invoice?.id)
                    setSelectedLineIds(new Set())
                    setLineQuantities(new Map())
                    setLineMode('all')
                  }}
                  label={t('sales:documents.types.invoice')}
                  required
                  error={errors.source_invoice_id?.message}
                  disabled={isSubmitting}
                />
              )}
            />
          )}

          {/* Document Summary */}
          {currentDocument && (
            <div className="mt-4 rounded-lg bg-gray-50 p-4">
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span className="font-medium text-gray-700">{t('sales:documents.number')}:</span>
                  <span className="ms-2 text-gray-900">{currentDocument.document_number}</span>
                </div>
                <div>
                  <span className="font-medium text-gray-700">{t('sales:documents.date')}:</span>
                  <span className="ms-2 text-gray-900">
                    {new Date(currentDocument.document_date).toLocaleDateString()}
                  </span>
                </div>
                <div>
                  <span className="font-medium text-gray-700">{t('sales:documents.partner')}:</span>
                  <span className="ms-2 text-gray-900">{currentDocument.partner?.name}</span>
                </div>
                <div>
                  <span className="font-medium text-gray-700">{t('sales:documents.total')}:</span>
                  <span className="ms-2 text-gray-900">
                    {parseFloat(currentDocument.total || '0').toFixed(2)}
                  </span>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* 2. Items to Return (only shows when document selected) */}
        {hasDocument && documentLines.length > 0 && (
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="mb-4 text-lg font-medium text-gray-900">
              {t('sales:lineItems.title')}
            </h2>

            {/* Line Mode Toggle */}
            <div className="mb-4">
              <div className="inline-flex rounded-lg border border-gray-200 p-1">
                <button
                  type="button"
                  onClick={() => {
                    setLineMode('all')
                    setSelectedLineIds(new Set())
                    setLineQuantities(new Map())
                  }}
                  disabled={isSubmitting}
                  className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                    lineMode === 'all'
                      ? 'bg-blue-600 text-white'
                      : 'text-gray-700 hover:bg-gray-100'
                  }`}
                >
                  {t('sales:returnNotes.form.fullReturn')}
                </button>
                <button
                  type="button"
                  onClick={() => { setLineMode('partial') }}
                  disabled={isSubmitting}
                  className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                    lineMode === 'partial'
                      ? 'bg-blue-600 text-white'
                      : 'text-gray-700 hover:bg-gray-100'
                  }`}
                >
                  {t('sales:returnNotes.form.partialReturn')}
                </button>
              </div>
            </div>

            {/* Partial Line Selection Table */}
            {lineMode === 'partial' && (
              <div>
                <div className="overflow-hidden rounded-lg border border-gray-200">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="w-10 px-3 py-3"></th>
                        <th className="px-3 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('sales:lineItems.item')}
                        </th>
                        <th className="px-3 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('sales:lineItems.quantity')}
                        </th>
                        <th className="px-3 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('sales:returnNotes.form.returnQuantity')}
                        </th>
                        <th className="px-3 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('sales:lineItems.unitPrice')}
                        </th>
                        <th className="px-3 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                          {t('sales:lineItems.total')}
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 bg-white">
                      {documentLines.map((line) => {
                        const isSelected = selectedLineIds.has(line.id)
                        const returnQty = lineQuantities.get(line.id) || line.quantity
                        const unitPrice = parseFloat(line.unit_price)
                        const taxRate = parseFloat(line.tax_rate)
                        const subtotal = unitPrice * returnQty
                        const tax = subtotal * (taxRate / 100)
                        const total = subtotal + tax

                        return (
                          <tr key={line.id} className={isSelected ? 'bg-blue-50' : ''}>
                            <td className="px-3 py-3 text-center">
                              <input
                                type="checkbox"
                                checked={isSelected}
                                onChange={() => { handleToggleLine(line.id) }}
                                disabled={isSubmitting}
                                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                              />
                            </td>
                            <td className="px-3 py-3">
                              <div className="text-sm font-medium text-gray-900">{line.product_code}</div>
                              <div className="text-sm text-gray-500">{line.description}</div>
                            </td>
                            <td className="px-3 py-3 text-end text-sm text-gray-900">
                              {line.quantity}
                            </td>
                            <td className="px-3 py-3 text-end">
                              {isSelected ? (
                                <input
                                  type="number"
                                  min="1"
                                  max={line.quantity}
                                  value={returnQty}
                                  onChange={(e) => {
                                    handleQuantityChange(line.id, parseInt(e.target.value) || 1, line.quantity)
                                  }}
                                  disabled={isSubmitting}
                                  className="w-20 rounded-md border-gray-300 px-2 py-1 text-sm text-end"
                                />
                              ) : (
                                <span className="text-sm text-gray-400">-</span>
                              )}
                            </td>
                            <td className="px-3 py-3 text-end text-sm text-gray-900">
                              {unitPrice.toFixed(2)}
                            </td>
                            <td className="px-3 py-3 text-end text-sm font-medium text-gray-900">
                              {isSelected ? total.toFixed(2) : '-'}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                    <tfoot className="bg-gray-50">
                      <tr>
                        <td colSpan={5} className="px-3 py-3 text-end text-sm font-medium text-gray-900">
                          {t('sales:returnNotes.form.returnTotal')}
                        </td>
                        <td className="px-3 py-3 text-end text-sm font-bold text-gray-900">
                          {partialReturnTotal.toFixed(2)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
                {selectedLineIds.size === 0 && (
                  <p className="mt-2 text-sm text-red-600">
                    {t('sales:returnNotes.form.noLinesSelected')}
                  </p>
                )}
              </div>
            )}

            {/* All mode - just show line count */}
            {lineMode === 'all' && (
              <div className="rounded-lg bg-blue-50 p-4 text-sm text-blue-800">
                {t('sales:returnNotes.form.returningAllItems', {
                  count: documentLines.length,
                  defaultValue: `Returning all ${documentLines.length} items`,
                })}
              </div>
            )}
          </div>
        )}

        {/* 3. Return Details (only shows when document selected) */}
        {hasDocument && (
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="mb-4 text-lg font-medium text-gray-900">
              {t('sales:returnNotes.form.returnDetails')}
            </h2>

            <div className="space-y-4">
              {/* Return Reason */}
              <Controller
                name="return_reason"
                control={control}
                render={({ field }) => (
                  <ReturnReasonSelect
                    value={field.value as ReturnReason | '' | undefined}
                    onChange={field.onChange}
                    required
                    disabled={isSubmitting}
                    error={errors.return_reason?.message}
                  />
                )}
              />

              {/* Return Condition */}
              <Controller
                name="return_condition"
                control={control}
                render={({ field }) => (
                  <ReturnConditionSelect
                    value={field.value || ''}
                    onChange={field.onChange}
                    disabled={isSubmitting}
                  />
                )}
              />

              {/* Refund Method */}
              <Controller
                name="refund_method"
                control={control}
                render={({ field }) => (
                  <RefundMethodSelect
                    value={field.value || ''}
                    onChange={field.onChange}
                    disabled={isSubmitting}
                  />
                )}
              />

              {/* Notes */}
              <div>
                <label htmlFor="notes" className="block text-sm font-medium text-gray-700">
                  {t('sales:returnNotes.notes')}
                </label>
                <Controller
                  name="notes"
                  control={control}
                  render={({ field }) => (
                    <textarea
                      {...field}
                      id="notes"
                      rows={3}
                      disabled={isSubmitting}
                      placeholder={t('sales:returnNotes.notesPlaceholder')}
                      className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                  )}
                />
              </div>

              {/* Auto-create credit note (invoice only) */}
              {sourceType === 'invoice' && (
                <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
                  <Controller
                    name="auto_create_credit_note"
                    control={control}
                    render={({ field }) => (
                      <label className="flex items-start gap-3">
                        <input
                          type="checkbox"
                          checked={field.value}
                          onChange={field.onChange}
                          disabled={isSubmitting}
                          className="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        />
                        <div className="flex-1">
                          <span className="text-sm font-medium text-gray-900">
                            {t('sales:returnNotes.form.autoCreateCreditNote')}
                          </span>
                          <p className="mt-1 text-sm text-gray-600">
                            {t('sales:returnNotes.form.autoCreateCreditNoteHint')}
                          </p>
                        </div>
                      </label>
                    )}
                  />
                </div>
              )}
            </div>
          </div>
        )}

        {/* Error Display */}
        {createMutation.isError && (
          <div className="rounded-lg border border-red-200 bg-red-50 p-4">
            <div className="flex gap-3">
              <AlertCircle className="h-5 w-5 flex-shrink-0 text-red-400" />
              <p className="text-sm text-red-800">
                {createMutation.error.message || t('sales:returnNotes.messages.createFailed')}
              </p>
            </div>
          </div>
        )}

        {/* Action Buttons */}
        <div className="flex justify-end gap-3 border-t border-gray-200 pt-6">
          <button
            type="button"
            onClick={() => { navigate('/sales/return-notes') }}
            disabled={isSubmitting}
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {t('common:actions.cancel')}
          </button>
          <button
            type="submit"
            disabled={isSubmitting || !hasDocument}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-400"
          >
            {isSubmitting
              ? t('common:status.saving')
              : t('sales:returnNotes.form.create')}
          </button>
        </div>
      </form>
    </div>
  )
}
