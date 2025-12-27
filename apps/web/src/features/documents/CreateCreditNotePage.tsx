/**
 * Create Credit Note Page
 * Full-page credit note creation matching invoice form patterns
 * Supports: customer selection OR invoice selection with line-based crediting
 */

import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useForm, Controller } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, AlertCircle, Receipt } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import { PartnerSearchSelect } from '@/components/ui/PartnerSearchSelect'
import { InvoiceSearchSelect } from '@/components/ui/InvoiceSearchSelect'
import { DocumentLineEditor, type DocumentLine } from '@/components/documents/DocumentLineEditor'
import type { Invoice } from '@mecanospex/shared/types/generated'

const creditNoteSchema = z.object({
  partner_id: z.string().min(1, 'Partner is required'),
  source_invoice_id: z.string().optional(),
  issue_date: z.string().min(1, 'Date is required'),
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

type CreditNoteFormData = z.infer<typeof creditNoteSchema>

type CreditMode = 'customer' | 'invoice'
type LineMode = 'all' | 'partial'

export function CreateCreditNotePage() {
  const { t } = useTranslation(['sales', 'common'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()

  // Mode states
  const [creditMode, setCreditMode] = useState<CreditMode>('invoice')
  const [lineMode, setLineMode] = useState<LineMode>('all')

  // Line selection states
  const [lines, setLines] = useState<DocumentLine[]>([])
  const [selectedLineIds, setSelectedLineIds] = useState<Set<string>>(new Set())
  const [lineQuantities, setLineQuantities] = useState<Map<string, number>>(new Map())

  // Selected invoice state
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null)

  const {
    control,
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<CreditNoteFormData>({
    resolver: zodResolver(creditNoteSchema),
    defaultValues: {
      partner_id: '',
      source_invoice_id: '',
      issue_date: new Date().toISOString().split('T')[0],
      reason: 'return',
      notes: '',
    },
  })

  const partnerId = watch('partner_id')
  const sourceInvoiceId = watch('source_invoice_id')

  // Fetch invoice details when invoice is selected
  const { data: invoiceData } = useQuery({
    queryKey: ['invoice', sourceInvoiceId],
    queryFn: async () => {
      const response = await api.get<{ data: Invoice }>(`/invoices/${sourceInvoiceId}`)
      return response.data.data
    },
    enabled: !!sourceInvoiceId && creditMode === 'invoice',
  })

  // When invoice is selected, update partner and lines
  useEffect(() => {
    if (invoiceData) {
      setSelectedInvoice(invoiceData)
      setValue('partner_id', invoiceData.partner_id)

      // Convert invoice lines to document lines
      if (invoiceData.lines) {
        const documentLines: DocumentLine[] = invoiceData.lines.map((line) => ({
          id: line.id,
          product_id: line.product_id,
          product_code: line.product_code || '',
          product_name: line.product_name,
          description: line.description,
          quantity: line.quantity,
          unit_price: parseFloat(line.unit_price),
          tax_rate: parseFloat(line.tax_rate),
          line_total: parseFloat(line.total),
        }))
        setLines(documentLines)

        // Initialize all lines as selected with full quantities
        const allLineIds = new Set(documentLines.map(l => l.id))
        setSelectedLineIds(allLineIds)

        const quantities = new Map<string, number>()
        documentLines.forEach(line => {
          quantities.set(line.id, line.quantity)
        })
        setLineQuantities(quantities)
      }
    }
  }, [invoiceData, setValue])

  // Handle invoice selection
  const handleInvoiceSelect = (invoice: Invoice | null) => {
    if (invoice) {
      setValue('source_invoice_id', invoice.id)
    } else {
      setValue('source_invoice_id', '')
      setSelectedInvoice(null)
      setLines([])
      setSelectedLineIds(new Set())
      setLineQuantities(new Map())
    }
  }

  // Toggle line selection
  const toggleLineSelection = (lineId: string) => {
    const newSelected = new Set(selectedLineIds)
    if (newSelected.has(lineId)) {
      newSelected.delete(lineId)
      const newQuantities = new Map(lineQuantities)
      newQuantities.delete(lineId)
      setLineQuantities(newQuantities)
    } else {
      newSelected.add(lineId)
      const line = lines.find(l => l.id === lineId)
      if (line) {
        const newQuantities = new Map(lineQuantities)
        newQuantities.set(lineId, line.quantity)
        setLineQuantities(newQuantities)
      }
    }
    setSelectedLineIds(newSelected)
  }

  // Update line quantity
  const updateLineQuantity = (lineId: string, quantity: number) => {
    const newQuantities = new Map(lineQuantities)
    newQuantities.set(lineId, quantity)
    setLineQuantities(newQuantities)
  }

  // Calculate total
  const calculateTotal = () => {
    return lines
      .filter(line => selectedLineIds.has(line.id))
      .reduce((sum, line) => {
        const qty = lineQuantities.get(line.id) || line.quantity
        const lineTotal = qty * line.unit_price * (1 + line.tax_rate / 100)
        return sum + lineTotal
      }, 0)
  }

  // Create mutation
  const createMutation = useMutation({
    mutationFn: async (data: CreditNoteFormData) => {
      const payload: any = {
        partner_id: data.partner_id,
        issue_date: data.issue_date,
        reason: data.reason,
        notes: data.notes,
      }

      if (creditMode === 'invoice' && data.source_invoice_id) {
        payload.source_invoice_id = data.source_invoice_id

        if (lineMode === 'partial') {
          // Send selected lines with quantities
          payload.lines = Array.from(selectedLineIds).map(lineId => ({
            line_id: lineId,
            quantity: lineQuantities.get(lineId) || 0,
          }))
        }
        // For 'all' mode, backend will credit entire invoice
      } else {
        // Customer mode - manual line entry
        payload.lines = lines.map(line => ({
          product_id: line.product_id,
          description: line.description,
          quantity: line.quantity,
          unit_price: line.unit_price,
          tax_rate: line.tax_rate,
        }))
      }

      const response = await api.post('/credit-notes', payload)
      return response.data
    },
    onSuccess: () => {
      toast.success(t('sales:creditNotes.messages.created'))
      void queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      navigate('/sales/credit-notes')
    },
    onError: (error: Error) => {
      toast.error(error.message || t('sales:creditNotes.messages.createFailed'))
    },
  })

  const onSubmit = (data: CreditNoteFormData) => {
    // Validation for invoice mode
    if (creditMode === 'invoice') {
      if (!data.source_invoice_id) {
        toast.error(t('sales:creditNotes.form.invoiceRequired'))
        return
      }

      if (lineMode === 'partial' && selectedLineIds.size === 0) {
        toast.error(t('sales:creditNotes.form.selectLinesRequired'))
        return
      }
    } else {
      // Customer mode validation
      if (lines.length === 0) {
        toast.error(t('sales:creditNotes.form.linesRequired'))
        return
      }
    }

    createMutation.mutate(data)
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="border-b border-gray-200 bg-white">
        <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
          <div className="flex items-center gap-4">
            <Link
              to="/sales/credit-notes"
              className="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
            >
              <ArrowLeft className="h-5 w-5" />
            </Link>
            <div className="flex-1">
              <h1 className="text-2xl font-bold text-gray-900">
                {t('sales:creditNotes.new')}
              </h1>
              <p className="mt-1 text-sm text-gray-500">
                {t('sales:creditNotes.createDescription', 'Create a new credit note')}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Main Content */}
      <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
          {/* Mode Selection */}
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-medium text-gray-900 mb-4">
              {t('sales:creditNotes.form.creditMode')}
            </h2>
            <div className="flex gap-4">
              <button
                type="button"
                onClick={() => { setCreditMode('invoice'); }}
                className={`flex-1 rounded-lg border-2 p-4 text-start transition-all ${
                  creditMode === 'invoice'
                    ? 'border-blue-500 bg-blue-50'
                    : 'border-gray-200 hover:border-gray-300'
                }`}
              >
                <div className="flex items-center gap-3">
                  <Receipt className={`h-5 w-5 ${creditMode === 'invoice' ? 'text-blue-600' : 'text-gray-400'}`} />
                  <div>
                    <p className="font-medium text-gray-900">
                      {t('sales:creditNotes.form.fromInvoice')}
                    </p>
                    <p className="text-sm text-gray-500">
                      {t('sales:creditNotes.form.fromInvoiceDesc', 'Credit an existing invoice')}
                    </p>
                  </div>
                </div>
              </button>

              <button
                type="button"
                onClick={() => { setCreditMode('customer'); }}
                className={`flex-1 rounded-lg border-2 p-4 text-start transition-all ${
                  creditMode === 'customer'
                    ? 'border-blue-500 bg-blue-50'
                    : 'border-gray-200 hover:border-gray-300'
                }`}
              >
                <div className="flex items-center gap-3">
                  <Receipt className={`h-5 w-5 ${creditMode === 'customer' ? 'text-blue-600' : 'text-gray-400'}`} />
                  <div>
                    <p className="font-medium text-gray-900">
                      {t('sales:creditNotes.form.fromCustomer')}
                    </p>
                    <p className="text-sm text-gray-500">
                      {t('sales:creditNotes.form.fromCustomerDesc', 'Create without source invoice')}
                    </p>
                  </div>
                </div>
              </button>
            </div>
          </div>

          {/* Credit Note Details */}
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-medium text-gray-900 mb-4">
              {t('sales:creditNotes.form.details')}
            </h2>

            <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
              {/* Invoice Selection (invoice mode) */}
              {creditMode === 'invoice' && (
                <div className="md:col-span-2">
                  <Controller
                    name="source_invoice_id"
                    control={control}
                    render={({ field }) => (
                      <InvoiceSearchSelect
                        value={selectedInvoice}
                        onChange={handleInvoiceSelect}
                        label={t('sales:creditNotes.sourceInvoice')}
                        required
                        error={errors.source_invoice_id?.message}
                      />
                    )}
                  />
                </div>
              )}

              {/* Customer Selection */}
              <div className={creditMode === 'invoice' ? 'md:col-span-2' : ''}>
                <Controller
                  name="partner_id"
                  control={control}
                  render={({ field }) => (
                    <PartnerSearchSelect
                      value={field.value}
                      onChange={field.onChange}
                      partnerType="customer"
                      label={t('sales:documents.partner')}
                      required
                      disabled={creditMode === 'invoice' && !!selectedInvoice}
                      error={errors.partner_id?.message}
                    />
                  )}
                />
              </div>

              {/* Issue Date */}
              <div>
                <label className="block text-sm font-medium text-gray-700">
                  {t('sales:documents.date')} <span className="text-red-500">*</span>
                </label>
                <input
                  type="date"
                  {...register('issue_date')}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
                {errors.issue_date && (
                  <p className="mt-1 text-sm text-red-600">{errors.issue_date.message}</p>
                )}
              </div>

              {/* Reason */}
              <div>
                <label className="block text-sm font-medium text-gray-700">
                  {t('sales:creditNotes.reason.title')} <span className="text-red-500">*</span>
                </label>
                <select
                  {...register('reason')}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="return">{t('sales:creditNotes.reason.return')}</option>
                  <option value="price_adjustment">{t('sales:creditNotes.reason.priceAdjustment')}</option>
                  <option value="billing_error">{t('sales:creditNotes.reason.billingError')}</option>
                  <option value="damaged_goods">{t('sales:creditNotes.reason.damagedGoods')}</option>
                  <option value="service_issue">{t('sales:creditNotes.reason.serviceIssue')}</option>
                  <option value="other">{t('sales:creditNotes.reason.other')}</option>
                </select>
              </div>

              {/* Notes */}
              <div className="md:col-span-2">
                <label className="block text-sm font-medium text-gray-700">
                  {t('sales:documents.notes')}
                </label>
                <textarea
                  {...register('notes')}
                  rows={3}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  placeholder={t('sales:creditNotes.notesPlaceholder')}
                />
              </div>
            </div>
          </div>

          {/* Line Mode Selection (invoice mode only) */}
          {creditMode === 'invoice' && selectedInvoice && (
            <div className="rounded-lg border border-gray-200 bg-white p-6">
              <h2 className="text-lg font-medium text-gray-900 mb-4">
                {t('sales:creditNotes.form.lineSelection')}
              </h2>
              <div className="flex gap-4">
                <button
                  type="button"
                  onClick={() => {
                    setLineMode('all')
                    // Select all lines
                    const allLineIds = new Set(lines.map(l => l.id))
                    setSelectedLineIds(allLineIds)
                  }}
                  className={`flex-1 rounded-lg border-2 p-4 text-start transition-all ${
                    lineMode === 'all'
                      ? 'border-blue-500 bg-blue-50'
                      : 'border-gray-200 hover:border-gray-300'
                  }`}
                >
                  <p className="font-medium text-gray-900">
                    {t('sales:creditNotes.form.creditAll')}
                  </p>
                  <p className="text-sm text-gray-500">
                    {t('sales:creditNotes.form.creditAllDesc', 'Credit all lines from invoice')}
                  </p>
                </button>

                <button
                  type="button"
                  onClick={() => { setLineMode('partial'); }}
                  className={`flex-1 rounded-lg border-2 p-4 text-start transition-all ${
                    lineMode === 'partial'
                      ? 'border-blue-500 bg-blue-50'
                      : 'border-gray-200 hover:border-gray-300'
                  }`}
                >
                  <p className="font-medium text-gray-900">
                    {t('sales:creditNotes.form.creditPartial')}
                  </p>
                  <p className="text-sm text-gray-500">
                    {t('sales:creditNotes.form.creditPartialDesc', 'Select specific lines and quantities')}
                  </p>
                </button>
              </div>

              {/* Partial Line Selection */}
              {lineMode === 'partial' && lines.length > 0 && (
                <div className="mt-6">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="w-12 px-3 py-3"></th>
                        <th className="px-3 py-3 text-start text-xs font-medium uppercase text-gray-500">
                          {t('sales:lineItems.product')}
                        </th>
                        <th className="px-3 py-3 text-end text-xs font-medium uppercase text-gray-500">
                          {t('sales:lineItems.quantity')}
                        </th>
                        <th className="px-3 py-3 text-end text-xs font-medium uppercase text-gray-500">
                          {t('sales:lineItems.price')}
                        </th>
                        <th className="px-3 py-3 text-end text-xs font-medium uppercase text-gray-500">
                          {t('sales:lineItems.total')}
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200">
                      {lines.map((line) => {
                        const isSelected = selectedLineIds.has(line.id)
                        const creditQty = lineQuantities.get(line.id) || line.quantity

                        return (
                          <tr key={line.id} className={isSelected ? 'bg-blue-50' : ''}>
                            <td className="px-3 py-4">
                              <input
                                type="checkbox"
                                checked={isSelected}
                                onChange={() => { toggleLineSelection(line.id); }}
                                className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                              />
                            </td>
                            <td className="px-3 py-4 text-sm">
                              <div className="font-medium text-gray-900">{line.product_name}</div>
                              <div className="text-gray-500">{line.description}</div>
                            </td>
                            <td className="px-3 py-4 text-end text-sm">
                              {isSelected ? (
                                <input
                                  type="number"
                                  min="1"
                                  max={line.quantity}
                                  value={creditQty}
                                  onChange={(e) => { updateLineQuantity(line.id, parseInt(e.target.value)); }}
                                  className="w-20 rounded border border-gray-300 px-2 py-1 text-end"
                                />
                              ) : (
                                <span className="text-gray-500">{line.quantity}</span>
                              )}
                              <span className="text-gray-400 ms-1">/ {line.quantity}</span>
                            </td>
                            <td className="px-3 py-4 text-end text-sm text-gray-900">
                              {line.unit_price.toFixed(2)}
                            </td>
                            <td className="px-3 py-4 text-end text-sm font-medium text-gray-900">
                              {((isSelected ? creditQty : 0) * line.unit_price * (1 + line.tax_rate / 100)).toFixed(2)}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                    <tfoot className="bg-gray-50">
                      <tr>
                        <td colSpan={4} className="px-3 py-3 text-end text-sm font-medium text-gray-900">
                          {t('sales:documents.total')}:
                        </td>
                        <td className="px-3 py-3 text-end text-lg font-bold text-gray-900">
                          {calculateTotal().toFixed(2)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              )}
            </div>
          )}

          {/* Customer Mode - Manual Line Entry */}
          {creditMode === 'customer' && partnerId && (
            <div className="rounded-lg border border-gray-200 bg-white p-6">
              <DocumentLineEditor
                lines={lines}
                onChange={setLines}
                partnerId={partnerId}
              />
            </div>
          )}

          {/* Actions */}
          <div className="flex justify-end gap-3">
            <Link
              to="/sales/credit-notes"
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              {t('common:actions.cancel')}
            </Link>
            <button
              type="submit"
              disabled={createMutation.isPending}
              className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            >
              {createMutation.isPending
                ? t('common:status.creating')
                : t('sales:creditNotes.form.create')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
