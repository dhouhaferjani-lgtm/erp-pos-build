import { type ChangeEvent, type FormEvent, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, FileUp, Plus, ReceiptText, Trash2, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'

import { Button, MoneyInput, QuantityInput, StatusBadge, type StatusTone } from '@/components/atoms'
import { DataTable, PageHeader, type DataTableColumn } from '@/components/molecules'
import { SaveSplitButton } from '@/components/molecules/SaveSplitButton'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { PartnerPicker, ProductPicker, type PartnerPickerValue, type ProductPickerValue } from '@/components/molecules/pickers'
import { api, getErrorMessage } from '@/lib/api'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { bcadd, bccomp, bcmul, bcsub, formatCurrency, formatQuantity } from '@/lib/decimal'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { usePermissions } from '@/hooks/usePermissions'

import {
  useCreateSupplierInvoice,
  useDuplicateSupplierInvoiceReference,
  useOpenPurchaseOrdersForSupplier,
  usePurchaseOrderReceiptLinesForSupplierInvoice,
  usePurchaseOrdersForSupplierInvoice,
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
  poNumber: string
  quantity: string
  unitPrice: string
  vatRate: string
}

interface ManualInvoiceLineFormState {
  product: ProductPickerValue | null
  productId: string
  variantId: string
  quantity: string
  unitPrice: string
  vatRate: string
  batchNumber: string
  batchExpiryDate: string
  batchManufacturingDate: string
}

type InvoiceLineEdits = Partial<Pick<InvoiceLineFormState, 'quantity' | 'unitPrice' | 'vatRate'>>
type MatchPreviewStatus = 'matched' | 'priceVariance' | 'quantityVariance'
type SupplierInvoiceEntryMode = 'receipts' | 'invoiceFirstDelivered' | 'invoiceFirstPending'

interface OptionResponse {
  data: Array<{ id: string; name: string }>
}

interface ProcurementPolicyResponse {
  data: { allow_invoice_first: boolean }
}

const SUPPLIER_INVOICE_CREATE_FORM_ID = 'supplier-invoice-create-form'

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

function matchPreviewTone(status: MatchPreviewStatus): StatusTone {
  switch (status) {
    case 'matched':
      return 'success'
    case 'priceVariance':
      return 'warning'
    case 'quantityVariance':
      return 'danger'
  }
}

function newIdempotencyKey(): string {
  const randomPart =
    typeof globalThis.crypto?.randomUUID === 'function'
      ? globalThis.crypto.randomUUID()
      : `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `supplier-invoice-delivered-${randomPart}`
}

export function SupplierInvoiceCreatePage() {
  const { t } = useTranslation(['common', 'purchases', 'documentIngestions'])
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { hasPermission } = usePermissions()
  const createInvoice = useCreateSupplierInvoice()
  const initialPurchaseOrderIds = useMemo(() => {
    const all = searchParams.getAll('po')
    const first = searchParams.get('po')
    return all.length > 0 ? all : first !== null ? [first] : []
  }, [searchParams])
  const lockedFromEntryPoint = initialPurchaseOrderIds.length > 0

  const [selectedSupplier, setSelectedSupplier] = useState<PartnerPickerValue | null>(null)
  const [selectedPurchaseOrderIds, setSelectedPurchaseOrderIds] = useState<string[]>(initialPurchaseOrderIds)
  const [supplierReference, setSupplierReference] = useState('')
  const [duplicateCheckReference, setDuplicateCheckReference] = useState('')
  const [issueDate, setIssueDate] = useState(todayIso())
  const [dueDate, setDueDate] = useState('')
  const [notes, setNotes] = useState('')
  const [lineEdits, setLineEdits] = useState<Record<string, InvoiceLineEdits>>({})
  const [entryMode, setEntryMode] = useState<SupplierInvoiceEntryMode>(
    initialPurchaseOrderIds.length > 0 ? 'receipts' : 'invoiceFirstPending',
  )
  const [manualLines, setManualLines] = useState<ManualInvoiceLineFormState[]>([
    {
      product: null,
      productId: '',
      variantId: '',
      quantity: '1.0000',
      unitPrice: '0.000',
      vatRate: '0.00',
      batchNumber: '',
      batchExpiryDate: '',
      batchManufacturingDate: '',
    },
  ])
  const [invoiceFirstLocationId, setInvoiceFirstLocationId] = useState('')
  const [invoiceFirstExternalReference, setInvoiceFirstExternalReference] = useState('')
  const [invoiceFirstExternalDate, setInvoiceFirstExternalDate] = useState(todayIso())
  const [invoiceFirstIdempotencyKey] = useState(newIdempotencyKey)
  const [attachments, setAttachments] = useState<File[]>([])
  const uploadAttachment = useUploadAttachment('')
  const canCreatePendingInvoice = hasPermission('supplier-invoices.create-pending')
  const canCreateDeliveredInvoice = canCreatePendingInvoice && hasPermission('goods-receipt.create-standalone')

  const policyQuery = useQuery({
    queryKey: tenantScopedKey(['supplier-invoices', 'procurement-policy']),
    queryFn: async () => {
      const response = await api.get<ProcurementPolicyResponse>('/procurement-policies')
      return response.data.data
    },
  })

  const locationsQuery = useQuery({
    queryKey: tenantScopedKey(['supplier-invoices', 'locations']),
    queryFn: async () => {
      const response = await api.get<OptionResponse>('/locations')
      return response.data.data
    },
    enabled: entryMode === 'invoiceFirstDelivered',
  })

  const purchaseOrdersQuery = usePurchaseOrdersForSupplierInvoice(selectedPurchaseOrderIds)
  const receiptLinesQuery = usePurchaseOrderReceiptLinesForSupplierInvoice(selectedPurchaseOrderIds, selectedPurchaseOrderIds.length > 0)
  const purchaseOrders = purchaseOrdersQuery.data
  const primaryPurchaseOrder = purchaseOrders[0]
  const supplierId = primaryPurchaseOrder?.partner_id ?? selectedSupplier?.id ?? ''
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
    if (purchaseOrders.length === 0 || receiptLines.length === 0) {
      return []
    }

    const poLinesById = new Map(purchaseOrders.flatMap((po) => po.lines.map((line) => [line.id, { line, po }] as const)))
    return receiptLines.map((receiptLine): InvoiceLineFormState => {
      const poLineEntry = poLinesById.get(receiptLine.po_line_id)
      const poLine = poLineEntry?.line
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
        poNumber: poLineEntry?.po.document_number ?? '',
        quantity: edits.quantity ?? matchableQty,
        unitPrice: edits.unitPrice ?? unitPrice,
        vatRate: edits.vatRate ?? poLine?.tax_rate ?? '0.00',
      }
    }).filter((line) => bccomp(line.matchableQty, '0') > 0)
  }, [lineEdits, purchaseOrders, receiptLinesQuery.data])

  const lines = prefilledLines
  const invoiceableLines = lines.filter((line) => bccomp(line.quantity, '0') > 0)
  const manualInvoiceableLines = manualLines.filter((line) => line.productId.trim() !== '' && bccomp(line.quantity, '0') > 0)
  const isReceiptMode = entryMode === 'receipts'
  const isInvoiceFirstDelivered = entryMode === 'invoiceFirstDelivered'
  const invoiceFirstAllowed = policyQuery.data?.allow_invoice_first === true && canCreatePendingInvoice
  const deliveredInvoiceFirstAllowed = invoiceFirstAllowed && canCreateDeliveredInvoice
  const canSubmit =
    supplierId !== '' &&
    (
      isReceiptMode
        ? selectedPurchaseOrderIds.length > 0 && invoiceableLines.length > 0
        : manualInvoiceableLines.length > 0 && (!isInvoiceFirstDelivered || invoiceFirstLocationId.trim() !== '')
    )

  const currency = primaryPurchaseOrder?.currency ?? openPurchaseOrdersQuery.data?.[0]?.currency ?? 'TND'
  const selectedPoNumbers = purchaseOrders.map((po) => po.document_number)
  const selectedPoNumber = selectedPoNumbers.join(', ') || selectedPurchaseOrderIds.join(', ')
  const loadedPurchaseOrderIds = useMemo(() => new Set(purchaseOrders.map((po) => po.id)), [purchaseOrders])
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

  function updateManualLine(index: number, patch: Partial<ManualInvoiceLineFormState>): void {
    setManualLines((current) => current.map((line, lineIndex) => (
      lineIndex === index ? { ...line, ...patch } : line
    )))
  }

  function addManualLine(): void {
    setManualLines((current) => [
      ...current,
      {
        product: null,
        productId: '',
        variantId: '',
        quantity: '1.0000',
        unitPrice: '0.000',
        vatRate: '0.00',
        batchNumber: '',
        batchExpiryDate: '',
        batchManufacturingDate: '',
      },
    ])
  }

  function removeManualLine(index: number): void {
    setManualLines((current) => current.length <= 1 ? current : current.filter((_, lineIndex) => lineIndex !== index))
  }

  function handleAttachments(event: ChangeEvent<HTMLInputElement>): void {
    setAttachments(Array.from(event.target.files ?? []))
  }

  function buildPayload(): CreateSupplierInvoicePayload {
    if (!isReceiptMode) {
      const payload: CreateSupplierInvoicePayload = {
        partner_id: supplierId,
        currency,
        issue_date: issueDate,
        lines: manualInvoiceableLines.map((line) => ({
          product_id: line.productId.trim(),
          ...(line.variantId.trim() !== '' ? { variant_id: line.variantId.trim() } : {}),
          quantity: line.quantity,
          unit_price: line.unitPrice,
          vat_rate: line.vatRate,
          ...(entryMode === 'invoiceFirstDelivered' && line.batchNumber.trim() !== '' && line.batchExpiryDate !== '' ? {
            batch: {
              batch_number: line.batchNumber.trim(),
              expiry_date: line.batchExpiryDate,
              ...(line.batchManufacturingDate !== '' ? { manufacturing_date: line.batchManufacturingDate } : {}),
            },
          } : {}),
        })),
      }
      if (entryMode === 'invoiceFirstPending') {
        payload.pending_receipt = true
      } else {
        payload.invoice_first_delivered = true
        payload.location_id = invoiceFirstLocationId.trim()
        payload.idempotency_key = invoiceFirstIdempotencyKey
        if (invoiceFirstExternalReference.trim() !== '') {
          payload.external_reference = invoiceFirstExternalReference.trim()
        }
        if (invoiceFirstExternalDate !== '') {
          payload.external_date = invoiceFirstExternalDate
        }
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

    const payload: CreateSupplierInvoicePayload = {
      partner_id: supplierId,
      source_document_id: selectedPurchaseOrderIds[0] ?? '',
      source_document_ids: selectedPurchaseOrderIds,
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

  const manualLineColumns: DataTableColumn<ManualInvoiceLineFormState>[] = [
    {
      key: 'product',
      header: t('purchases:supplierInvoices.create.columns.product'),
      cellClassName: 'min-w-64',
      render: (line, index) => (
        <>
          <ProductPicker
            value={line.product}
            onChange={(product) => {
              updateManualLine(index, {
                product,
                productId: product?.id ?? '',
                batchNumber: product?.requires_batch_tracking === true ? line.batchNumber : '',
                batchExpiryDate: product?.requires_batch_tracking === true ? line.batchExpiryDate : '',
                batchManufacturingDate: product?.requires_batch_tracking === true ? line.batchManufacturingDate : '',
              })
            }}
            label={t('purchases:supplierInvoices.create.manualLine.productId')}
            productType="all"
            testId={`manual-line-product-picker-${String(index)}`}
          />
          {isInvoiceFirstDelivered && line.product?.requires_batch_tracking === true ? (
            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
              <input
                data-testid={`manual-line-batch-number-${String(index)}`}
                className={tokens.input.base}
                placeholder={t('purchases:supplierInvoices.create.manualLine.batchNumber')}
                value={line.batchNumber}
                onChange={(event) => { updateManualLine(index, { batchNumber: event.target.value }) }}
              />
              <input
                data-testid={`manual-line-batch-expiry-${String(index)}`}
                type="date"
                className={tokens.input.base}
                value={line.batchExpiryDate}
                onChange={(event) => { updateManualLine(index, { batchExpiryDate: event.target.value }) }}
              />
              <input
                data-testid={`manual-line-batch-manufacturing-${String(index)}`}
                type="date"
                className={tokens.input.base}
                value={line.batchManufacturingDate}
                onChange={(event) => { updateManualLine(index, { batchManufacturingDate: event.target.value }) }}
              />
            </div>
          ) : null}
        </>
      ),
    },
    {
      key: 'quantity',
      header: t('purchases:supplierInvoices.create.columns.quantity'),
      numeric: true,
      width: '9rem',
      render: (line, index) => (
        <QuantityInput
          data-testid={`manual-line-quantity-${String(index)}`}
          value={line.quantity}
          onChange={(quantity) => { updateManualLine(index, { quantity }) }}
          decimalPlaces={4}
        />
      ),
    },
    {
      key: 'unitPrice',
      header: t('purchases:supplierInvoices.create.columns.unitPrice'),
      numeric: true,
      width: '9rem',
      render: (line, index) => (
        <MoneyInput
          data-testid={`manual-line-unit-price-${String(index)}`}
          value={line.unitPrice}
          onChange={(unitPrice) => { updateManualLine(index, { unitPrice }) }}
          currency={currency}
        />
      ),
    },
    {
      key: 'vatRate',
      header: t('purchases:supplierInvoices.create.columns.vatRate'),
      numeric: true,
      width: '7rem',
      render: (line, index) => (
        <input
          data-testid={`manual-line-vat-rate-${String(index)}`}
          className={tokens.input.base}
          value={line.vatRate}
          onChange={(event) => { updateManualLine(index, { vatRate: event.target.value }) }}
        />
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'center',
      width: '3rem',
      render: (_line, index) => (
        <button
          type="button"
          data-testid={`remove-manual-line-${String(index)}`}
          disabled={manualLines.length <= 1}
          className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm}`}
          onClick={() => { removeManualLine(index) }}
          aria-label={t('purchases:supplierInvoices.create.manualLine.remove')}
        >
          <Trash2 className="h-4 w-4" />
        </button>
      ),
    },
  ]

  const receiptLineColumns: DataTableColumn<InvoiceLineFormState>[] = [
    {
      key: 'product',
      header: t('purchases:supplierInvoices.create.columns.product'),
      cellClassName: 'max-w-md',
      render: (line) => (
        <>
          <div className="line-clamp-2 font-medium">{line.description}</div>
          <div className={`line-clamp-2 text-xs ${textColors.tertiary}`}>
            {[line.poNumber, line.receiptNumber].filter(Boolean).join(' · ')}
          </div>
        </>
      ),
    },
    {
      key: 'received',
      header: t('purchases:supplierInvoices.create.columns.received'),
      numeric: true,
      accessor: (line) => line.receivedQty,
    },
    {
      key: 'invoiced',
      header: t('purchases:supplierInvoices.create.columns.invoiced'),
      numeric: true,
      accessor: (line) => line.alreadyInvoiced,
    },
    {
      key: 'quantity',
      header: t('purchases:supplierInvoices.create.columns.quantity'),
      numeric: true,
      width: '9rem',
      render: (line, index) => (
        <QuantityInput
          data-testid={`invoice-line-quantity-${String(index)}`}
          value={line.quantity}
          onChange={(quantity) => { updateLine(index, { quantity }) }}
          decimalPlaces={4}
          max={line.matchableQty}
        />
      ),
    },
    {
      key: 'unitPrice',
      header: t('purchases:supplierInvoices.create.columns.unitPrice'),
      numeric: true,
      width: '9rem',
      render: (line, index) => (
        <MoneyInput
          data-testid={`invoice-line-unit-price-${String(index)}`}
          value={line.unitPrice}
          onChange={(unitPrice) => { updateLine(index, { unitPrice }) }}
          currency={currency}
        />
      ),
    },
    {
      key: 'vatRate',
      header: t('purchases:supplierInvoices.create.columns.vatRate'),
      numeric: true,
      width: '7rem',
      render: (line, index) => (
        <input
          className={tokens.input.base}
          value={line.vatRate}
          onChange={(event) => { updateLine(index, { vatRate: event.target.value }) }}
        />
      ),
    },
    {
      key: 'match',
      header: t('purchases:supplierInvoices.create.columns.match'),
      render: (line) => {
        const preview = linePreview(line)
        return (
          <StatusBadge tone={matchPreviewTone(preview)} className="gap-1">
            {preview === 'matched' ? <CheckCircle2 className="h-3 w-3" /> : <AlertTriangle className="h-3 w-3" />}
            {t(`purchases:supplierInvoices.create.match.${preview}`)}
          </StatusBadge>
        )
      },
    },
  ]

  return (
    <div className="flex min-h-full flex-col gap-6">
      <PageHeader
        title={t('purchases:supplierInvoices.create.title')}
        subtitle={t('purchases:supplierInvoices.create.description')}
        actions={hasPermission('document-ingestions.view') ? (
          <Link
            to="/purchases/scans/new?kind=supplier_invoice"
            className={`text-sm ${textColors.brand} hover:underline`}
          >
            {t('documentIngestions:actions.scanInstead')}
          </Link>
        ) : null}
        className="mb-0"
      />

      <form
        id={SUPPLIER_INVOICE_CREATE_FORM_ID}
        className="flex flex-1 flex-col gap-6"
        onSubmit={(event) => { void handleSubmit(event) }}
      >

      <section className={`${tokens.card.base} grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1fr_0.8fr_0.8fr]`}>
        <div>
          <PartnerPicker
            value={primaryPurchaseOrder ? { id: primaryPurchaseOrder.partner_id, name: primaryPurchaseOrder.partner_name, type: 'supplier' } : selectedSupplier}
            onChange={(next) => {
              setSelectedSupplier(next)
              setSelectedPurchaseOrderIds([])
              setLineEdits({})
              if (entryMode === 'receipts') {
                setEntryMode('invoiceFirstPending')
              }
            }}
            partnerType="supplier"
            label={t('purchases:supplierInvoices.create.supplier')}
            placeholder={t('purchases:supplierInvoices.create.supplierPlaceholder')}
            disabled={selectedPurchaseOrderIds.length > 0 && primaryPurchaseOrder !== undefined}
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
            multiple
            value={selectedPurchaseOrderIds}
            disabled={lockedFromEntryPoint}
            onChange={(event) => {
              setSelectedPurchaseOrderIds(Array.from(event.target.selectedOptions).map((option) => option.value))
              setLineEdits({})
              setEntryMode('receipts')
            }}
          >
            {selectedPurchaseOrderIds.length === 0 ? (
              <option value="">{t('purchases:supplierInvoices.create.purchaseOrderPlaceholder')}</option>
            ) : null}
            {purchaseOrders.map((po) => (
              <option key={po.id} value={po.id}>{po.document_number}</option>
            ))}
            {(openPurchaseOrdersQuery.data ?? []).filter((po) => !loadedPurchaseOrderIds.has(po.id)).map((po) => (
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
        <div>
          <h2 className={tokens.heading.section}>{t('purchases:supplierInvoices.create.entryMode.title')}</h2>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('purchases:supplierInvoices.create.entryMode.description')}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {invoiceFirstAllowed ? (
            <button
              type="button"
              data-testid="invoice-first-pending"
              disabled={lockedFromEntryPoint}
              className={`${tokens.button.base} ${entryMode === 'invoiceFirstPending' ? tokens.button.primary : tokens.button.secondary} ${tokens.button.sizes.sm}`}
              onClick={() => {
                setEntryMode('invoiceFirstPending')
                setSelectedPurchaseOrderIds([])
                setLineEdits({})
              }}
            >
              {t('purchases:supplierInvoices.create.entryMode.pending')}
            </button>
          ) : null}
          {deliveredInvoiceFirstAllowed ? (
            <button
              type="button"
              data-testid="invoice-first-delivered"
              disabled={lockedFromEntryPoint}
              className={`${tokens.button.base} ${entryMode === 'invoiceFirstDelivered' ? tokens.button.primary : tokens.button.secondary} ${tokens.button.sizes.sm}`}
              onClick={() => {
                setEntryMode('invoiceFirstDelivered')
                setSelectedPurchaseOrderIds([])
                setLineEdits({})
              }}
            >
              {t('purchases:supplierInvoices.create.entryMode.delivered')}
            </button>
          ) : null}
          <button
            type="button"
            disabled={selectedPurchaseOrderIds.length === 0}
            className={`${tokens.button.base} ${entryMode === 'receipts' ? tokens.button.primary : tokens.button.secondary} ${tokens.button.sizes.sm}`}
            onClick={() => { setEntryMode('receipts') }}
          >
            {t('purchases:supplierInvoices.create.entryMode.receipts')}
          </button>
        </div>

        {isInvoiceFirstDelivered ? (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label className={tokens.label.base} htmlFor="invoice-first-location-id">
                {t('purchases:supplierInvoices.create.invoiceFirst.location')}
              </label>
              <select
                id="invoice-first-location-id"
                data-testid="invoice-first-location-id"
                className={tokens.select.base}
                value={invoiceFirstLocationId}
                onChange={(event) => { setInvoiceFirstLocationId(event.target.value) }}
              >
                <option value="">{t('purchases:supplierInvoices.create.invoiceFirst.location')}</option>
                {(locationsQuery.data ?? []).map((location) => (
                  <option key={location.id} value={location.id}>{location.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className={tokens.label.base} htmlFor="invoice-first-external-reference">
                {t('purchases:supplierInvoices.create.invoiceFirst.externalReference')}
              </label>
              <input
                id="invoice-first-external-reference"
                data-testid="invoice-first-external-reference"
                className={tokens.input.base}
                value={invoiceFirstExternalReference}
                onChange={(event) => { setInvoiceFirstExternalReference(event.target.value) }}
              />
            </div>
            <div>
              <label className={tokens.label.base} htmlFor="invoice-first-external-date">
                {t('purchases:supplierInvoices.create.invoiceFirst.externalDate')}
              </label>
              <input
                id="invoice-first-external-date"
                data-testid="invoice-first-external-date"
                type="date"
                className={tokens.input.base}
                value={invoiceFirstExternalDate}
                onChange={(event) => { setInvoiceFirstExternalDate(event.target.value) }}
              />
            </div>
          </div>
        ) : null}
      </section>

      <section className={`${tokens.card.base} space-y-4`}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className={tokens.heading.section}>{t('purchases:supplierInvoices.create.linesTitle')}</h2>
            {selectedPurchaseOrderIds.length > 0 ? (
              <p className={`mt-1 flex flex-wrap items-center gap-2 text-sm ${textColors.tertiary}`}>
                <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
                  <ReceiptText className="me-1 h-3 w-3" />
                  {selectedPurchaseOrderIds.length > 1
                    ? t('purchases:supplierInvoices.create.linkedPOs', { numbers: selectedPoNumber })
                    : selectedPoNumber}
                </span>
                {receiptNumbers !== '' ? <span>{receiptNumbers}</span> : null}
              </p>
            ) : null}
          </div>
          <div className={`text-sm font-medium ${textColors.primary}`}>
            {formatCurrency(subtotal, true, currency)}
          </div>
        </div>

        {!isReceiptMode ? (
          <div className="space-y-3">
            <div className="flex justify-end">
              <button
                type="button"
                data-testid="add-manual-line"
                className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                onClick={addManualLine}
              >
                <Plus className="me-1.5 h-4 w-4" />
                {t('purchases:supplierInvoices.create.manualLine.add')}
              </button>
            </div>
            <DataTable
              columns={manualLineColumns}
              data={manualLines}
              keyExtractor={(_line, index) => index}
            />
          </div>
        ) : receiptLinesQuery.isLoading || purchaseOrdersQuery.isLoading ? (
          <div className={`py-8 text-center text-sm ${textColors.tertiary}`}>
            {t('common:status.loading')}
          </div>
        ) : lines.length === 0 ? (
          <div className={`rounded-md border ${borderColors.light} p-8 text-center text-sm ${textColors.tertiary}`}>
            {selectedPurchaseOrderIds.length === 0
              ? t('purchases:supplierInvoices.create.pickSupplierAndPo')
              : t('purchases:supplierInvoices.create.noReceiptLines')}
          </div>
        ) : (
          <DataTable
            columns={receiptLineColumns}
            data={lines}
            keyExtractor={(line) => line.receiptLineId}
          />
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

      <StickyFormFooter>
        <Button
          type="button"
          variant="secondary"
          onClick={() => { void navigate('/purchases/supplier-invoices') }}
        >
          {t('common:actions.cancel')}
        </Button>
        <SaveSplitButton
          form={SUPPLIER_INVOICE_CREATE_FORM_ID}
          isPending={createInvoice.isPending || uploadAttachment.isPending}
          disabled={!canSubmit}
          primaryLabel={t('purchases:supplierInvoices.create.saveDraft')}
          onPrimarySave={() => {}}
          onSaveAndClose={() => {
            const form = document.getElementById(SUPPLIER_INVOICE_CREATE_FORM_ID)
            if (form instanceof HTMLFormElement) {
              form.requestSubmit()
            }
          }}
        />
      </StickyFormFooter>
    </form>
    </div>
  )
}
