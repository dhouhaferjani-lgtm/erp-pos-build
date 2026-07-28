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
import { Button } from '@/components/atoms/Button/Button'
import { PageHeader } from '@/components/molecules/PageHeader/PageHeader'
import { useCurrency } from '@/hooks/useCurrency'
import { api } from '@/lib/api'
import { entityRoutes } from '@/lib/entityRoutes'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { InvoiceSearchSelect } from '@/components/molecules/pickers/InvoiceSearchSelect'
import { DeliveryNoteSearchSelect } from '@/components/molecules/pickers/DeliveryNoteSearchSelect'
import { ReturnReasonSelect } from './components/ReturnReasonSelect'
import { ReturnConditionSelect } from './components/ReturnConditionSelect'
import { RefundMethodSelect } from './components/RefundMethodSelect'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { Invoice } from '@/components/molecules/pickers/InvoiceSearchSelect'
import type { DeliveryNote } from '@/components/molecules/pickers/DeliveryNoteSearchSelect'
import type { ReturnReason } from '@/types/returnNote'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'

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
  quantity_decimals?: number | null
  unit_price: string
  tax_rate: string
  total: string
}

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function CreateReturnNotePage() {
  const { t } = useTranslation(['sales', 'common'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { decimals } = useCurrency()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

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
    queryKey: tenantScopedKey(['invoice', selectedInvoice?.id]),
    queryFn: async () => {
      const response = await api.get<{ data: DocumentDetailResponse }>(`/invoices/${selectedInvoice?.id}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!selectedInvoice?.id && sourceType === 'invoice',
  })

  // Fetch selected delivery note details
  const { data: deliveryNoteDetails } = useQuery({
    queryKey: tenantScopedKey(['delivery-note', selectedDeliveryNote?.id]),
    queryFn: async () => {
      const response = await api.get<{ data: DocumentDetailResponse }>(`/delivery-notes/${selectedDeliveryNote?.id}`)
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null && !!selectedDeliveryNote?.id && sourceType === 'delivery_note',
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

      const response = await api.post<{ data?: { id?: string }; id?: string }>('/return-notes', payload)
      return response.data
    },
    onSuccess: async (createdReturnNote) => {
      toast.success(t('sales:returnNotes.messages.created'))
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('return-notes', tenantId, companyId),
      })
      const returnNoteId = createdReturnNote.data?.id ?? createdReturnNote.id
      navigate(returnNoteId ? entityRoutes.document(returnNoteId, { documentType: 'return_note' }) : '/sales/return-notes')
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
      <PageHeader
        title={t('sales:returnNotes.new')}
        subtitle={t('sales:returnNotes.createDescription')}
        actions={(
          <Button
            type="button"
            variant="secondary"
            onClick={() => { navigate('/sales/return-notes') }}
            className="gap-2"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Button>
        )}
      />

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        {/* 1. Source Document Selection */}
        <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-6`}>
          <h2 className={`mb-4 text-lg font-medium ${colorClasses.textGray900}`}>
            {t('sales:returnNotes.form.selectSource')}
          </h2>

          {/* Document Type Toggle */}
          <div className="mb-4">
            <div className={`inline-flex rounded-lg border ${colorClasses.borderGray200} p-1`}>
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
                    ? `${colorClasses.bgBlue600} text-white`
                    : `${colorClasses.textGray700} ${colorClasses.hoverBgGray100}`
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
                    ? `${colorClasses.bgBlue600} text-white`
                    : `${colorClasses.textGray700} ${colorClasses.hoverBgGray100}`
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
            <div className={`mt-4 rounded-lg ${colorClasses.bgGray50} p-4`}>
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span className={`font-medium ${colorClasses.textGray700}`}>{t('sales:documents.number')}:</span>
                  <span className={`ms-2 ${colorClasses.textGray900}`}>{currentDocument.document_number}</span>
                </div>
                <div>
                  <span className={`font-medium ${colorClasses.textGray700}`}>{t('sales:documents.date')}:</span>
                  <span className={`ms-2 ${colorClasses.textGray900}`}>
                    {new Date(currentDocument.document_date).toLocaleDateString()}
                  </span>
                </div>
                <div>
                  <span className={`font-medium ${colorClasses.textGray700}`}>{t('sales:documents.partner')}:</span>
                  <span className={`ms-2 ${colorClasses.textGray900}`}>{currentDocument.partner?.name}</span>
                </div>
                <div>
                  <span className={`font-medium ${colorClasses.textGray700}`}>{t('sales:documents.total')}:</span>
                  <span className={`ms-2 ${colorClasses.textGray900}`}>
                    {parseFloat(currentDocument.total || '0').toFixed(decimals)}
                  </span>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* 2. Items to Return (only shows when document selected) */}
        {hasDocument && documentLines.length > 0 && (
          <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-6`}>
            <h2 className={`mb-4 text-lg font-medium ${colorClasses.textGray900}`}>
              {t('sales:lineItems.title')}
            </h2>

            {/* Line Mode Toggle */}
            <div className="mb-4">
              <div className={`inline-flex rounded-lg border ${colorClasses.borderGray200} p-1`}>
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
                      ? `${colorClasses.bgBlue600} text-white`
                      : `${colorClasses.textGray700} ${colorClasses.hoverBgGray100}`
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
                      ? `${colorClasses.bgBlue600} text-white`
                      : `${colorClasses.textGray700} ${colorClasses.hoverBgGray100}`
                  }`}
                >
                  {t('sales:returnNotes.form.partialReturn')}
                </button>
              </div>
            </div>

            {/* Partial Line Selection Table */}
            {lineMode === 'partial' && (
              <div>
                <div className={`overflow-hidden rounded-lg border ${colorClasses.borderGray200}`}>
                  <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
                    <thead className={`${colorClasses.bgGray50}`}>
                      <tr>
                        <th className="w-10 px-3 py-3"></th>
                        <th className={`px-3 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                          {t('sales:lineItems.item')}
                        </th>
                        <th className={`px-3 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                          {t('sales:lineItems.quantity')}
                        </th>
                        <th className={`px-3 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                          {t('sales:returnNotes.form.returnQuantity')}
                        </th>
                        <th className={`px-3 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                          {t('sales:lineItems.unitPrice')}
                        </th>
                        <th className={`px-3 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                          {t('sales:lineItems.total')}
                        </th>
                      </tr>
                    </thead>
                    <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
                      {documentLines.map((line) => {
                        const isSelected = selectedLineIds.has(line.id)
                        const returnQty = lineQuantities.get(line.id) || line.quantity
                        const unitPrice = parseFloat(line.unit_price)
                        const taxRate = parseFloat(line.tax_rate)
                        const subtotal = unitPrice * returnQty
                        const tax = subtotal * (taxRate / 100)
                        const total = subtotal + tax

                        return (
                          <tr key={line.id} className={isSelected ? `${colorClasses.bgBlue50}` : ''}>
                            <td className="px-3 py-3 text-center">
                              <input
                                type="checkbox"
                                checked={isSelected}
                                onChange={() => { handleToggleLine(line.id) }}
                                disabled={isSubmitting}
                                className={`h-4 w-4 rounded ${colorClasses.borderGray300} ${colorClasses.textBlue600} ${colorClasses.focusRingBlue500}`}
                              />
                            </td>
                            <td className="px-3 py-3">
                              <div className={`text-sm font-medium ${colorClasses.textGray900}`}>{line.product_code}</div>
                              <div className={`text-sm ${colorClasses.textGray500}`}>{line.description}</div>
                            </td>
                            <td className={`px-3 py-3 text-end text-sm ${colorClasses.textGray900}`}>
                              {formatQuantity(line.quantity, getQuantityDecimals(line))}
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
                                  className={`w-20 rounded-md ${colorClasses.borderGray300} px-2 py-1 text-sm text-end`}
                                />
                              ) : (
                                <span className={`text-sm ${colorClasses.textGray400}`}>-</span>
                              )}
                            </td>
                            <td className={`px-3 py-3 text-end text-sm ${colorClasses.textGray900}`}>
                              {unitPrice.toFixed(decimals)}
                            </td>
                            <td className={`px-3 py-3 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                              {isSelected ? total.toFixed(decimals) : '-'}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                    <tfoot className={`${colorClasses.bgGray50}`}>
                      <tr>
                        <td colSpan={5} className={`px-3 py-3 text-end text-sm font-medium ${colorClasses.textGray900}`}>
                          {t('sales:returnNotes.form.returnTotal')}
                        </td>
                        <td className={`px-3 py-3 text-end text-sm font-bold ${colorClasses.textGray900}`}>
                          {partialReturnTotal.toFixed(decimals)}
                        </td>
                      </tr>
                    </tfoot>
                  </DataTable>
                </div>
                {selectedLineIds.size === 0 && (
                  <p className={`mt-2 text-sm ${colorClasses.textRed600}`}>
                    {t('sales:returnNotes.form.noLinesSelected')}
                  </p>
                )}
              </div>
            )}

            {/* All mode - just show line count */}
            {lineMode === 'all' && (
              <div className={`rounded-lg ${colorClasses.bgBlue50} p-4 text-sm ${colorClasses.textBlue800}`}>
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
          <div className={`rounded-lg border ${colorClasses.borderGray200} bg-white p-6`}>
            <h2 className={`mb-4 text-lg font-medium ${colorClasses.textGray900}`}>
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
                <label htmlFor="notes" className={`block text-sm font-medium ${colorClasses.textGray700}`}>
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
                      className={`mt-1 block w-full rounded-lg border ${colorClasses.borderGray300} px-3 py-2 text-sm ${colorClasses.focusBorderBlue500} focus:outline-none focus:ring-1 ${colorClasses.focusRingBlue500}`}
                    />
                  )}
                />
              </div>

              {/* Auto-create credit note (invoice only) */}
              {sourceType === 'invoice' && (
                <div className={`rounded-lg border ${colorClasses.borderBlue200} ${colorClasses.bgBlue50} p-4`}>
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
                          className={`mt-1 h-4 w-4 rounded ${colorClasses.borderGray300} ${colorClasses.textBlue600} ${colorClasses.focusRingBlue500}`}
                        />
                        <div className="flex-1">
                          <span className={`text-sm font-medium ${colorClasses.textGray900}`}>
                            {t('sales:returnNotes.form.autoCreateCreditNote')}
                          </span>
                          <p className={`mt-1 text-sm ${colorClasses.textGray600}`}>
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
          <div className={`rounded-lg border ${colorClasses.borderRed200} ${colorClasses.bgRed50} p-4`}>
            <div className="flex gap-3">
              <AlertCircle className={`h-5 w-5 flex-shrink-0 ${colorClasses.textRed400}`} />
              <p className={`text-sm ${colorClasses.textRed800}`}>
                {createMutation.error.message || t('sales:returnNotes.messages.createFailed')}
              </p>
            </div>
          </div>
        )}

        {/* Action Buttons */}
        <div className={`flex justify-end gap-3 border-t ${colorClasses.borderGray200} pt-6`}>
          <button
            type="button"
            onClick={() => { navigate('/sales/return-notes') }}
            disabled={isSubmitting}
            className={`rounded-lg border ${colorClasses.borderGray300} bg-white px-4 py-2 text-sm font-medium ${colorClasses.textGray700} ${colorClasses.hoverBgGray50} disabled:cursor-not-allowed disabled:opacity-50`}
          >
            {t('common:actions.cancel')}
          </button>
          <button
            type="submit"
            disabled={isSubmitting || !hasDocument}
            className={`rounded-lg ${colorClasses.bgBlue600} px-4 py-2 text-sm font-medium text-white ${colorClasses.hoverBgBlue700} disabled:cursor-not-allowed ${colorClasses.disabledBgGray400}`}
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
