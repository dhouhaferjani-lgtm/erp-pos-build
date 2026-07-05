import { type ChangeEvent, type FormEvent, useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertTriangle, CheckCircle2, FileUp, ReceiptText, Save, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'

import { MoneyInput, QuantityInput } from '@/components/atoms'
import { PartnerPicker, type PartnerPickerValue } from '@/components/molecules/pickers'
import { getErrorMessage } from '@/lib/api'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { bcadd, bccomp, bcmul, bcsub, formatCurrency, formatQuantity } from '@/lib/decimal'

import {
  useCreateSupplierInvoice,
  useDuplicateSupplierInvoiceReference,
  useOpenPurchaseOrdersForSupplier,
  usePurchaseOrderForSupplierInvoice,
  usePurchaseOrderReceiptLines,
  useUploadAttachment,
} from './api'
import type { CreateSupplierInvoicePayload, SupplierInvoiceDetail } from './types'

interface InvoiceLineFormState {
  receiptLineId: string
  receiptNumber: string
  poLineId: string
  description: string
  receivedQty: string
  alreadyInvoiced: string
  matchableQty: string
  basisPrice: string
  quantity: string
  unitPrice: string
  vatRate: string
}

type InvoiceLineEdits = Partial<Pick<InvoiceLineFormState, 'quantity' | 'unitPrice' | 'vatRate'>>
type MatchPreviewStatus = 'matched' | 'priceVariance' | 'quantityVariance'

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

function positiveSub(a: string, b: string, scale: number): string {
  const result = bcsub(a, b, scale)
  return bccomp(result, '0') < 0 ? formatQuantity('0', scale) : result
}

function linePreview(line: InvoiceLineFormState): MatchPreviewStatus {
  if (bccomp(line.quantity, line.matchableQty) > 0) {
    return 'quantityVariance'
  }
  if (bccomp(line.unitPrice, line.basisPrice) !== 0) {
    return 'priceVariance'
  }
  return 'matched'
}

function matchChipClass(status: MatchPreviewStatus): string {
  switch (status) {
    case 'matched':
      return `${tokens.badge.base} ${tokens.badge.green}`
    case 'priceVariance':
      return `${tokens.badge.base} ${tokens.badge.yellow}`
    case 'quantityVariance':
      return `${tokens.badge.base} ${tokens.badge.red}`
  }
}

export function SupplierInvoiceCreatePage() {
  const { t } = useTranslation(['common', 'purchases'])
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const createInvoice = useCreateSupplierInvoice()

  const [selectedSupplier, setSelectedSupplier] = useState<PartnerPickerValue | null>(null)
  const [selectedPurchaseOrderId, setSelectedPurchaseOrderId] = useState(searchParams.get('po') ?? '')
  const [supplierReference, setSupplierReference] = useState('')
  const [duplicateCheckReference, setDuplicateCheckReference] = useState('')
  const [issueDate, setIssueDate] = useState(todayIso())
  const [dueDate, setDueDate] = useState('')
  const [notes, setNotes] = useState('')
  const [lineEdits, setLineEdits] = useState<Record<string, InvoiceLineEdits>>({})
  const [attachments, setAttachments] = useState<File[]>([])
  const uploadAttachment = useUploadAttachment('')

  const purchaseOrderQuery = usePurchaseOrderForSupplierInvoice(selectedPurchaseOrderId)
  const receiptLinesQuery = usePurchaseOrderReceiptLines(selectedPurchaseOrderId, selectedPurchaseOrderId !== '')
  const purchaseOrder = purchaseOrderQuery.data
  const supplierId = purchaseOrder?.partner_id ?? selectedSupplier?.id ?? ''
  const openPurchaseOrdersQuery = useOpenPurchaseOrdersForSupplier(selectedSupplier?.id ?? '')
  const duplicateReferenceQuery = useDuplicateSupplierInvoiceReference(
    supplierId,
    duplicateCheckReference,
    duplicateCheckReference !== '',
  )

  useEffect(() => {
    if ((receiptLinesQuery.data?.length ?? 0) > 0) {
      console.warn('Procurement policy tolerance read endpoint is not available; rendering supplier-invoice match preview without active tolerance.')
    }
  }, [receiptLinesQuery.data])

  const prefilledLines = useMemo((): InvoiceLineFormState[] => {
    const receiptLines = receiptLinesQuery.data ?? []
    if (purchaseOrder === undefined || receiptLines.length === 0) {
      return []
    }

    const poLinesById = new Map(purchaseOrder.lines.map((line) => [line.id, line]))
    return receiptLines.map((receiptLine): InvoiceLineFormState => {
      const poLine = poLinesById.get(receiptLine.po_line_id)
      const matchableQty = positiveSub(receiptLine.received_qty, receiptLine.quantity_invoiced, 4)
      const unitPrice = receiptLine.received_unit_price ?? poLine?.unit_price ?? '0.000'
      const edits = lineEdits[receiptLine.id] ?? {}
      return {
        receiptLineId: receiptLine.id,
        receiptNumber: receiptLine.receipt_number,
        poLineId: receiptLine.po_line_id,
        description: poLine?.product_name ?? poLine?.description ?? receiptLine.product_id,
        receivedQty: receiptLine.received_qty,
        alreadyInvoiced: receiptLine.quantity_invoiced,
        matchableQty,
        basisPrice: receiptLine.accrual_unit_cost,
        quantity: edits.quantity ?? matchableQty,
        unitPrice: edits.unitPrice ?? unitPrice,
        vatRate: edits.vatRate ?? poLine?.tax_rate ?? '0.00',
      }
    }).filter((line) => bccomp(line.matchableQty, '0') > 0)
  }, [lineEdits, purchaseOrder, receiptLinesQuery.data])

  const lines = prefilledLines
  const invoiceableLines = lines.filter((line) => bccomp(line.quantity, '0') > 0)

  const currency = purchaseOrder?.currency ?? openPurchaseOrdersQuery.data?.[0]?.currency ?? 'TND'
  const selectedPoNumber = purchaseOrder?.document_number ?? selectedPurchaseOrderId
  const receiptNumbers = useMemo(
    () => Array.from(new Set(lines.map((line) => line.receiptNumber))).join(', '),
    [lines],
  )
  const subtotal = invoiceableLines.reduce(
    (carry, line) => bcadd(carry, bcmul(line.quantity, line.unitPrice, 3), 3),
    '0.000',
  )
  const duplicateWarningVisible =
    duplicateCheckReference !== '' &&
    duplicateCheckReference === supplierReference.trim() &&
    duplicateReferenceQuery.data?.exists === true

  function updateLine(index: number, patch: Partial<InvoiceLineFormState>): void {
    const line = lines[index]
    const edits: InvoiceLineEdits = {}
    if (patch.quantity !== undefined) {
      edits.quantity = patch.quantity
    }
    if (patch.unitPrice !== undefined) {
      edits.unitPrice = patch.unitPrice
    }
    if (patch.vatRate !== undefined) {
      edits.vatRate = patch.vatRate
    }
    setLineEdits((current) => ({
      ...current,
      [line.receiptLineId]: {
        ...(current[line.receiptLineId] ?? {}),
        ...edits,
      },
    }))
  }

  function handleAttachments(event: ChangeEvent<HTMLInputElement>): void {
    setAttachments(Array.from(event.target.files ?? []))
  }

  function buildPayload(): CreateSupplierInvoicePayload {
    const payload: CreateSupplierInvoicePayload = {
      partner_id: supplierId,
      source_document_id: selectedPurchaseOrderId,
      currency,
      issue_date: issueDate,
      lines: invoiceableLines.map((line) => ({
        source_line_id: line.poLineId,
        quantity: line.quantity,
        unit_price: line.unitPrice,
        vat_rate: line.vatRate,
      })),
    }
    if (dueDate !== '') {
      payload.due_date = dueDate
    }
    if (supplierReference.trim() !== '') {
      payload.supplier_reference = supplierReference.trim()
    }
    if (notes.trim() !== '') {
      payload.notes = notes.trim()
    }
    return payload
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault()
    let created: SupplierInvoiceDetail
    try {
      created = await createInvoice.mutateAsync(buildPayload())
    } catch (error) {
      toast.error(getErrorMessage(error) || t('common:errors.unexpected'))
      return
    }

    try {
      if (attachments.length > 0) {
        await Promise.all(
          attachments.map((file) => uploadAttachment.mutateAsync({ documentId: created.id, file })),
        )
        toast.success(t('purchases:supplierInvoices.create.attachmentsUploaded', { count: attachments.length }))
      }
    } catch (error) {
      toast.warning(t('purchases:supplierInvoices.create.attachmentUploadFailed'))
    }
    void navigate(`/purchases/supplier-invoices/${created.id}`)
  }

  return (
    <form className="space-y-6" onSubmit={(event) => { void handleSubmit(event) }}>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className={`text-2xl font-bold ${textColors.primary}`}>
            {t('purchases:supplierInvoices.create.title')}
          </h1>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('purchases:supplierInvoices.create.description')}
          </p>
        </div>
        <button
          type="submit"
          data-testid="save-supplier-invoice"
          disabled={createInvoice.isPending || uploadAttachment.isPending || supplierId === '' || selectedPurchaseOrderId === '' || invoiceableLines.length === 0}
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
        >
          <Save className="me-2 h-4 w-4" />
          {t('purchases:supplierInvoices.create.saveDraft')}
        </button>
      </div>

      <section className={`${tokens.card.base} grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1fr_0.8fr_0.8fr]`}>
        <div>
          <PartnerPicker
            value={purchaseOrder ? { id: purchaseOrder.partner_id, name: purchaseOrder.partner_name, type: 'supplier' } : selectedSupplier}
            onChange={(next) => {
              setSelectedSupplier(next)
              setSelectedPurchaseOrderId('')
              setLineEdits({})
            }}
            partnerType="supplier"
            label={t('purchases:supplierInvoices.create.supplier')}
            placeholder={t('purchases:supplierInvoices.create.supplierPlaceholder')}
            disabled={selectedPurchaseOrderId !== '' && purchaseOrder !== undefined}
          />
        </div>

        <div>
          <label className={tokens.label.base} htmlFor="source-po">
            {t('purchases:supplierInvoices.create.purchaseOrder')}
          </label>
          <select
            id="source-po"
            data-testid="source-purchase-order"
            className={tokens.select.base}
            value={selectedPurchaseOrderId}
            disabled={purchaseOrder !== undefined && searchParams.get('po') !== null}
            onChange={(event) => {
              setSelectedPurchaseOrderId(event.target.value)
              setLineEdits({})
            }}
          >
            <option value="">{t('purchases:supplierInvoices.create.purchaseOrderPlaceholder')}</option>
            {purchaseOrder !== undefined ? (
              <option value={purchaseOrder.id}>{purchaseOrder.document_number}</option>
            ) : null}
            {(openPurchaseOrdersQuery.data ?? []).map((po) => (
              <option key={po.id} value={po.id}>
                {po.document_number}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className={tokens.label.base} htmlFor="issue-date">
            {t('purchases:supplierInvoices.create.issueDate')}
          </label>
          <input
            id="issue-date"
            type="date"
            className={tokens.input.base}
            value={issueDate}
            onChange={(event) => { setIssueDate(event.target.value) }}
          />
        </div>

        <div>
          <label className={tokens.label.base} htmlFor="due-date">
            {t('purchases:supplierInvoices.create.dueDate')}
          </label>
          <input
            id="due-date"
            type="date"
            className={tokens.input.base}
            value={dueDate}
            onChange={(event) => { setDueDate(event.target.value) }}
          />
        </div>

        <div className="lg:col-span-2">
          <label className={tokens.label.base} htmlFor="supplier-reference">
            {t('purchases:supplierInvoices.create.supplierReference')}
          </label>
          <input
            id="supplier-reference"
            data-testid="supplier-reference"
            className={tokens.input.base}
            value={supplierReference}
            onChange={(event) => {
              setSupplierReference(event.target.value)
              setDuplicateCheckReference('')
            }}
            onBlur={() => { setDuplicateCheckReference(supplierReference.trim()) }}
          />
          {duplicateWarningVisible ? (
            <p className={`${tokens.helperText.base} flex items-center gap-1 ${textColors.warning}`}>
              <TriangleAlert className="h-3.5 w-3.5" />
              {t('purchases:supplierInvoices.create.duplicateWarning', {
                number: duplicateReferenceQuery.data?.invoice_number ?? '',
              })}
            </p>
          ) : null}
        </div>

        <div className="lg:col-span-2">
          <label className={tokens.label.base} htmlFor="invoice-notes">
            {t('purchases:supplierInvoices.create.notes')}
          </label>
          <input
            id="invoice-notes"
            className={tokens.input.base}
            value={notes}
            onChange={(event) => { setNotes(event.target.value) }}
          />
        </div>
      </section>

      <section className={`${tokens.card.base} space-y-4`}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className={tokens.heading.section}>{t('purchases:supplierInvoices.create.linesTitle')}</h2>
            {selectedPurchaseOrderId !== '' ? (
              <p className={`mt-1 flex flex-wrap items-center gap-2 text-sm ${textColors.tertiary}`}>
                <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
                  <ReceiptText className="me-1 h-3 w-3" />
                  {selectedPoNumber}
                </span>
                {receiptNumbers !== '' ? <span>{receiptNumbers}</span> : null}
              </p>
            ) : null}
          </div>
          <div className={`text-sm font-medium ${textColors.primary}`}>
            {formatCurrency(subtotal, true, currency)}
          </div>
        </div>

        {receiptLinesQuery.isLoading || purchaseOrderQuery.isLoading ? (
          <div className={`py-8 text-center text-sm ${textColors.tertiary}`}>
            {t('common:status.loading')}
          </div>
        ) : lines.length === 0 ? (
          <div className={`rounded-md border ${borderColors.light} p-8 text-center text-sm ${textColors.tertiary}`}>
            {selectedPurchaseOrderId === ''
              ? t('purchases:supplierInvoices.create.pickSupplierAndPo')
              : t('purchases:supplierInvoices.create.noReceiptLines')}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className={tokens.table.header}>
                <tr>
                  <th className={`px-4 py-3 text-start text-xs font-medium uppercase ${textColors.tertiary}`}>
                    {t('purchases:supplierInvoices.create.columns.product')}
                  </th>
                  <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                    {t('purchases:supplierInvoices.create.columns.received')}
                  </th>
                  <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                    {t('purchases:supplierInvoices.create.columns.invoiced')}
                  </th>
                  <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                    {t('purchases:supplierInvoices.create.columns.quantity')}
                  </th>
                  <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                    {t('purchases:supplierInvoices.create.columns.unitPrice')}
                  </th>
                  <th className={`px-4 py-3 text-end text-xs font-medium uppercase ${textColors.tertiary}`}>
                    {t('purchases:supplierInvoices.create.columns.vatRate')}
                  </th>
                  <th className={`px-4 py-3 text-start text-xs font-medium uppercase ${textColors.tertiary}`}>
                    {t('purchases:supplierInvoices.create.columns.match')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {lines.map((line, index) => {
                  const preview = linePreview(line)
                  return (
                    <tr key={line.receiptLineId}>
                      <td className={`px-4 py-3 text-sm ${textColors.primary}`}>
                        <div className="font-medium">{line.description}</div>
                        <div className={`text-xs ${textColors.tertiary}`}>{line.receiptNumber}</div>
                      </td>
                      <td className={`px-4 py-3 text-end text-sm ${textColors.secondary}`}>
                        {line.receivedQty}
                      </td>
                      <td className={`px-4 py-3 text-end text-sm ${textColors.secondary}`}>
                        {line.alreadyInvoiced}
                      </td>
                      <td className="w-36 px-4 py-3">
                        <QuantityInput
                          data-testid={`invoice-line-quantity-${String(index)}`}
                          value={line.quantity}
                          onChange={(quantity) => { updateLine(index, { quantity }) }}
                          decimalPlaces={4}
                          max={line.matchableQty}
                        />
                      </td>
                      <td className="w-36 px-4 py-3">
                        <MoneyInput
                          data-testid={`invoice-line-unit-price-${String(index)}`}
                          value={line.unitPrice}
                          onChange={(unitPrice) => { updateLine(index, { unitPrice }) }}
                          currency={currency}
                        />
                      </td>
                      <td className="w-28 px-4 py-3">
                        <input
                          className={tokens.input.base}
                          value={line.vatRate}
                          onChange={(event) => { updateLine(index, { vatRate: event.target.value }) }}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <span className={matchChipClass(preview)}>
                          {preview === 'matched' ? <CheckCircle2 className="me-1 h-3 w-3" /> : <AlertTriangle className="me-1 h-3 w-3" />}
                          {t(`purchases:supplierInvoices.create.match.${preview}`)}
                        </span>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className={`${tokens.card.base} space-y-3`}>
        <label className={tokens.label.base} htmlFor="supplier-invoice-attachments">
          {t('purchases:supplierInvoices.create.attachments')}
        </label>
        <div className="flex flex-wrap items-center gap-3">
          <input
            id="supplier-invoice-attachments"
            data-testid="supplier-invoice-attachments"
            type="file"
            multiple
            className={tokens.input.base}
            onChange={handleAttachments}
          />
          <span className={`inline-flex items-center gap-1 text-sm ${textColors.tertiary}`}>
            <FileUp className="h-4 w-4" />
            {t('purchases:supplierInvoices.create.attachmentCount', { count: attachments.length })}
          </span>
        </div>
      </section>
    </form>
  )
}
