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
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import type { Invoice } from '@/components/molecules/pickers/InvoiceSearchSelect'
import type { DeliveryNote } from '@/components/molecules/pickers/DeliveryNoteSearchSelect'
import type { CreateReturnNoteRequest, ReturnReason } from '@/types/returnNote'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'

// Source type for return note
type SourceType = 'delivery_note' | 'invoice'
type LineMode = 'all' | 'partial'

// Validation schema
const returnNoteSchema = z.object({
  source_invoice_id: z.string().optional(),
  source_delivery_note_id: z.string().optional(),
  return_reason: z.enum(['defective', 'wrong_item', 'customer_regret', 'damaged_in_transit', 'warranty', 'exchange', 'other']),
  return_condition: z.enum(['unopened', 'used', 'damaged', 'unusable']).optional(),
  notes: z.string().optional(),
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
  notes?: string | undefined
}

interface DocumentLine {
  id: string
  product_id: string
  product_code: string
  description: string
  /**
   * A decimal STRING (rule 19). It was `number`, which silently truncated fractional
   * returns for any unit with `decimal_places > 0` — and the backend type has always
   * been `DocumentLineData.quantity: string`.
   */
  quantity: string
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
  const [lineQuantities, setLineQuantities] = useState<Map<string, string>>(new Map())

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
    },
  })

  /**
   * Plan CF T8 / CF-D9 (frontend gate C-2). `partner_id` and `currency` were BOTH
   * absent here, and the backend requires `partner_id`. Because the type simply did not
   * mention them, TypeScript could not catch the omission and the failure reached
   * runtime as a 422.
   */
  type DocumentDetailResponse = {
    document_number: string
    document_date: string
    partner?: { id: string; name: string } | null
    partner_id: string | null
    currency: string
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
        // DISPLAY ONLY. These Numbers must never reach the payload — the posted
        // quantities and prices are the strings themselves (rule 19).
        const quantity = Number(lineQuantities.get(lineId) ?? line.quantity)
        const unitPrice = Number(line.unit_price)
        const taxRate = Number(line.tax_rate)
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

  /**
   * Update a line's return quantity.
   *
   * Plan CF T8 / frontend gate I-4. The old signature took a `number` and clamped with
   * `Math.max(1, …)`, which made a fractional return IMPOSSIBLE for any unit with
   * `decimal_places > 0` — 0.5 kg became 1 kg, silently, on a stock document. The value
   * is a decimal string end to end now, and the floor-at-1 clamp is gone; the cap
   * against the source quantity survives, because over-returning is a real error the
   * server also refuses.
   */
  const handleQuantityChange = (lineId: string, value: string, maxQuantity: string) => {
    const quantity = Number(value) > Number(maxQuantity) ? maxQuantity : value
    setLineQuantities((prev) => {
      const newMap = new Map(prev)
      newMap.set(lineId, quantity)
      return newMap
    })
  }

  /**
   * Build the create payload in the CANONICAL document-create shape.
   *
   * Plan CF T8 / CF-D9. Everything this used to send was invented client-side:
   * `source_invoice_id` (an index-endpoint query filter), `auto_create_credit_note`
   * (zero occurrences anywhere in `apps/api/app`), `refund_method` (not a create key),
   * and `lines[].line_id` (consumed by `CreditNoteService`, a different endpoint).
   * Meanwhile `partner_id`, `document_date` and the per-line `description` +
   * `unit_price` the server requires were never sent at all. Both modes 422'd.
   *
   * `any` is gone with it — the payload is typed, so the next omission fails to
   * compile instead of reaching runtime as a 422.
   */
  const buildCreatePayload = (
    data: ReturnNoteFormData,
    document: DocumentDetailResponse,
  ): CreateReturnNoteRequest => {
    const sourceId = sourceType === 'invoice' ? selectedInvoice?.id : selectedDeliveryNote?.id

    const selected = lineMode === 'partial'
      ? documentLines.filter((line) => selectedLineIds.has(line.id))
      : documentLines

    return {
      // Non-null by construction: submit is blocked upstream when the source document
      // carries no partner (see onSubmit) rather than posting a null the server refuses.
      partner_id: document.partner_id as string,
      document_date: new Date().toISOString().slice(0, 10),
      currency: document.currency,
      ...(sourceId ? { source_document_id: sourceId } : {}),
      return_reason: data.return_reason,
      ...(data.return_condition ? { return_condition: data.return_condition } : {}),
      ...(data.notes ? { notes: data.notes } : {}),
      lines: selected.map((line) => ({
        ...(line.product_id ? { product_id: line.product_id } : {}),
        description: line.description,
        // Strings end to end. `unit_price` is the source line's net/HT value copied
        // VERBATIM — never round-tripped through a number (rule 19).
        quantity: lineMode === 'partial'
          ? (lineQuantities.get(line.id) ?? line.quantity)
          : line.quantity,
        unit_price: line.unit_price,
        tax_rate: line.tax_rate,
      })),
    }
  }

  // Create return note mutation
  const createMutation = useMutation({
    mutationFn: async (data: ReturnNoteFormData) => {
      if (!currentDocument) {
        throw new Error(t('sales:returnNotes.form.noSourceDocument'))
      }

      const response = await api.post<{ data?: { id?: string }; id?: string }>(
        '/return-notes',
        buildCreatePayload(data, currentDocument),
      )
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

    // Gate CF round 1, MAJOR M5. `QuantityInput` emits `''` when the box is cleared and
    // `buildCreatePayload` forwards the raw string, so an emptied line posted a quantity
    // the server refuses with `gt:0` / required — a Laravel validation 422 that reaches
    // the user as axios' bare "Request failed with status code 422". The sibling surface
    // got exactly this guard in the same commit (`CreateReturnNoteForm`'s `canSubmit`),
    // so the asymmetry was an oversight, not a decision.
    if (lineMode === 'partial') {
      for (const lineId of selectedLineIds) {
        const quantity = lineQuantities.get(lineId)
        if (quantity !== undefined && (quantity === '' || Number(quantity) <= 0)) {
          toast.error(t('sales:returnNotes.form.invalidQuantity'))
          return
        }
      }
    }

    // Plan CF T8, null-partner path. `partner_id` is `required` on the server, and a
    // source document CAN legitimately have no partner. Block here with a translated
    // message rather than posting a null and letting the user discover it as a raw 422
    // — the whole point of repairing this contract was to stop doing that.
    if (!currentDocument?.partner_id) {
      toast.error(t('sales:returnNotes.form.sourceHasNoPartner'))
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
                        const returnQty = lineQuantities.get(line.id) ?? line.quantity
                        // DISPLAY ONLY — these Numbers never reach the payload, which
                        // carries the source strings verbatim (rule 19).
                        const unitPrice = Number(line.unit_price)
                        const taxRate = Number(line.tax_rate)
                        const subtotal = unitPrice * Number(returnQty)
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
                                /*
                                 * Plan CF T8 / frontend gate I-4. This was a raw numeric
                                 * input floored at 1, whose onChange ran
                                 * `parseInt(...) || 1`, so a 0.5 kg return became 1 kg
                                 * — silently, on a stock document, for every unit with
                                 * decimal_places > 0. QuantityInput keeps the value a
                                 * canonical decimal string end to end and takes its
                                 * step from the product unit's own precision. The
                                 * floor-at-1 clamp is deliberately NOT carried over.
                                 */
                                <QuantityInput
                                  value={returnQty}
                                  onChange={(value) => { handleQuantityChange(line.id, value, line.quantity) }}
                                  decimalPlaces={getQuantityDecimals(line)}
                                  max={line.quantity}
                                  disabled={isSubmitting}
                                  className="w-24 text-end"
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

              {/*
                * Refund method and auto-create-credit-note are NOT RENDERED (gate CF
                * round 1, MAJOR M3 / plan Q-D).
                *
                * Neither key is accepted by any server route — `auto_create_credit_note`
                * matches ZERO occurrences in `apps/api/app`, and `refund_method` is not a
                * create key — so T8 correctly dropped both from the payload. But BEFORE
                * T8 the whole request 422'd, so ticking the box did nothing LOUDLY;
                * after T8 the create SUCCEEDS and the choice is discarded behind a
                * success toast. Rendering a no-op control on a document flow is the
                * newly-reachable silent discard that the governing ruling — "explicit,
                * never silent" — exists to prevent.
                *
                * Removed rather than disabled: a disabled control still advertises a
                * capability the system does not have. Q-D decides whether to wire
                * `auto_create_credit_note` to `POST /invoices/{id}/credit-full` as a real
                * second document; until then the honest UI is no control at all.
                */}

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
