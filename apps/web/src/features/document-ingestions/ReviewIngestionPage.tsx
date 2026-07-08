import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/api'
import { cn } from '@/lib/utils'
import { textColors, tokens, typography } from '@/lib/designTokens'
import { useCommitDocumentIngestion, useDocumentIngestion, useLocationsForIngestion, useRejectDocumentIngestion, useReExtractDocumentIngestion } from './queries'
import { CommitBar } from './components/CommitBar'
import { ExtractedFieldsPanel } from './components/ExtractedFieldsPanel'
import { LineMappingTable, type ReviewedLineState } from './components/LineMappingTable'
import { SourceViewer } from './components/SourceViewer'
import { SupplierPicker } from './components/SupplierPicker'
import type { DocumentIngestionDetail, ProductCandidate, ReceiptLineCandidate, ReviewedLinePayload, ReviewedPayload } from './types'

function confidenceSummary(detail: DocumentIngestionDetail) {
  return detail.confidenceSummary ?? detail.confidence_summary ?? null
}

function sourceUrl(detail: DocumentIngestionDetail): string | null {
  return detail.sourceUrl ?? detail.source_url ?? null
}

function committedType(detail: DocumentIngestionDetail): string | null {
  return detail.committedType ?? detail.committed_type ?? null
}

function committedId(detail: DocumentIngestionDetail): string | null {
  return detail.committedId ?? detail.committed_id ?? null
}

function providerModel(detail: DocumentIngestionDetail): string | null {
  return detail.providerModel ?? detail.provider_model ?? null
}

function lineSourceId(candidate: ReceiptLineCandidate | undefined): string {
  return candidate?.poLineId ?? candidate?.po_line_id ?? ''
}

function productTaxRate(candidate: ProductCandidate | undefined): string {
  return candidate?.taxRate ?? candidate?.tax_rate ?? ''
}

function buildFlaggedPaths(detail: DocumentIngestionDetail): Set<string> {
  const summary = confidenceSummary(detail)
  const paths = new Set<string>(summary?.lowConfidenceFields ?? [])
  for (const flag of summary?.reconciliation.flags ?? []) {
    if (flag.startsWith('field_unparseable:')) {
      paths.add(flag.slice('field_unparseable:'.length))
    }
  }
  for (const [key, field] of Object.entries(detail.extraction?.header ?? {})) {
    if (field.confidence < 0.75) paths.add(`header.${key}`)
  }
  for (const [key, field] of Object.entries(detail.extraction?.supplier ?? {})) {
    if (field.confidence < 0.75) paths.add(`supplier.${key}`)
  }
  return paths
}

function commitBlockedReason(detail: DocumentIngestionDetail, t: (key: string) => string): string | null {
  const reconciliation = confidenceSummary(detail)?.reconciliation
  const flags = reconciliation?.flags ?? []
  if (reconciliation?.consistent !== true) {
    return t('review.blockedReconciliation')
  }
  if (flags.some((flag) => flag.startsWith('field_unparseable:'))) {
    return t('review.blockedUnparseable')
  }
  if (detail.status !== 'needs_review') {
    return t('review.blockedStatus')
  }
  return null
}

function initialLines(detail: DocumentIngestionDetail): ReviewedLineState[] {
  const extractionLines = detail.extraction?.lines ?? []
  return extractionLines.map((line, index) => {
    const product = detail.suggestions?.productCandidates[index]?.[0]
    const receipt = detail.suggestions?.receiptLineCandidates[index]
    return {
      productId: product?.id ?? '',
      quantity: line.quantity.value,
      unitPrice: line.unitPrice?.value ?? '',
      vatRate: line.taxRate?.value ?? productTaxRate(product),
      freeQuantity: '0',
      batchNumber: line.batchNumber?.value ?? '',
      expiryDate: line.expiryDate?.value ?? '',
      sourceLineId: lineSourceId(receipt),
    }
  })
}

function linePayload(line: ReviewedLineState, includeVatRate: boolean): ReviewedLinePayload {
  const payload: ReviewedLinePayload = {
    productId: line.productId,
    quantity: line.quantity,
    freeQuantity: line.freeQuantity,
  }

  if (line.variantId) payload.variantId = line.variantId
  if (line.unitPrice) payload.unitPrice = line.unitPrice
  if (includeVatRate && line.vatRate) payload.vatRate = line.vatRate
  if (line.sourceLineId) payload.sourceLineId = line.sourceLineId
  if (line.batchNumber || line.expiryDate) {
    payload.batch = {
      batch_number: line.batchNumber,
      expiry_date: line.expiryDate,
    }
  }

  return payload
}

function redirectTarget(resultType: string, resultId: string): string {
  if (resultType === 'supplier_invoice') {
    return `/purchases/supplier-invoices/${resultId}`
  }
  return '/purchases/receipts'
}

export function ReviewIngestionPage() {
  const { t } = useTranslation(['documentIngestions'])
  const navigate = useNavigate()
  const params = useParams()
  const id = params['id'] ?? ''
  const { data: detail, isLoading, refetch } = useDocumentIngestion(id)
  const isDeliveryNote = detail?.kind === 'supplier_delivery_note'
  const locations = useLocationsForIngestion(isDeliveryNote)
  const commitMutation = useCommitDocumentIngestion(id)
  const rejectMutation = useRejectDocumentIngestion(id)
  const reExtractMutation = useReExtractDocumentIngestion(id)
  const [supplierId, setSupplierId] = useState('')
  const [locationId, setLocationId] = useState('')
  const [pendingReceipt, setPendingReceipt] = useState(false)
  const [lineStates, setLineStates] = useState<ReviewedLineState[]>([])
  const [serverError, setServerError] = useState<string | null>(null)

  useEffect(() => {
    if (!detail) return
    setSupplierId(detail.suggestions?.supplierCandidates[0]?.id ?? '')
    setLineStates(initialLines(detail))
  }, [detail])

  const flaggedPaths = useMemo(() => detail ? buildFlaggedPaths(detail) : new Set<string>(), [detail])
  const flags = detail ? confidenceSummary(detail)?.reconciliation.flags ?? [] : []
  const currency = detail?.extraction?.header['currency']?.value ?? 'TND'
  const blockReason = detail ? commitBlockedReason(detail, t) : null
  const canCommit = blockReason === null && supplierId !== '' && lineStates.every((line) => line.productId !== '')

  if (isLoading || !detail) {
    return <div className={tokens.card.base}>{t('review.loading')}</div>
  }

  if (detail.status === 'committed') {
    const type = committedType(detail)
    const committed = committedId(detail)
    return (
      <div className={cn(tokens.card.base, 'space-y-3')}>
        <h1 className={tokens.heading.section}>{t('review.committed')}</h1>
        <p>{type && committed ? t('review.committedTarget', { type, id: committed }) : t('review.committedNoTarget')}</p>
      </div>
    )
  }

  if (!detail.extraction) {
    return (
      <div className={cn(tokens.card.base, 'space-y-4')}>
        <h1 className={tokens.heading.section}>{t('review.title')}</h1>
        <p>{detail.error?.message ?? t('review.noExtraction')}</p>
        {detail.status === 'failed' && (
          <button
            type="button"
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
            onClick={() => { void reExtractMutation.mutateAsync().then(() => refetch()) }}
          >
            {t('actions.reExtract')}
          </button>
        )}
      </div>
    )
  }

  function updateLine(index: number, value: ReviewedLineState): void {
    setLineStates((current) => current.map((line, lineIndex) => lineIndex === index ? value : line))
  }

  function buildPayload(): ReviewedPayload {
    const reference = detail?.extraction?.header['number']?.value ?? detail?.extraction?.header['bl_number']?.value
    const documentDate = detail?.extraction?.header['delivery_date']?.value ?? detail?.extraction?.header['issue_date']?.value
    const payload: ReviewedPayload = {
      supplierId,
      lines: lineStates.map((line) => linePayload(line, !isDeliveryNote)),
    }

    if (reference) {
      payload.reference = reference
    }

    if (documentDate) {
      payload.documentDate = documentDate
    }

    if (isDeliveryNote) {
      payload.locationId = locationId
    } else {
      payload.currency = currency
      payload.pendingReceipt = pendingReceipt
    }

    return payload
  }

  async function handleCommit(): Promise<void> {
    setServerError(null)
    try {
      const result = await commitMutation.mutateAsync(buildPayload())
      toast.success(t('messages.committed'))
      void navigate(redirectTarget(result.committedType, result.committedId))
    } catch (error) {
      setServerError(getErrorMessage(error))
    }
  }

  async function handleReject(): Promise<void> {
    if (!window.confirm(t('review.rejectConfirm'))) return
    await rejectMutation.mutateAsync()
    toast.success(t('messages.rejected'))
    void navigate('/purchases/scans')
  }

  async function handleReExtract(): Promise<void> {
    await reExtractMutation.mutateAsync()
    await refetch()
  }

  const model = providerModel(detail)
  const locationOptions = Array.isArray(locations.data) ? locations.data : []

  return (
    <div className="space-y-6">
      <div>
        <h1 className={cn(typography.fontSize['2xl'], typography.fontWeight.bold, textColors.primary)}>
          {t('review.title')}
        </h1>
        <p className={cn(typography.fontSize.sm, textColors.tertiary)}>
          {t('review.meta', {
            provider: detail.provider ?? t('review.providerUnknown'),
            model: model ?? t('review.providerUnknown'),
          })}
        </p>
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(420px,0.8fr)]">
        <SourceViewer sourceUrl={sourceUrl(detail)} />

        <div className="space-y-4">
          <ExtractedFieldsPanel extraction={detail.extraction} flaggedPaths={flaggedPaths} flags={flags} />

          <section className={cn(tokens.card.base, 'space-y-4')}>
            <SupplierPicker
              candidates={detail.suggestions?.supplierCandidates ?? []}
              value={supplierId}
              onChange={setSupplierId}
              onCreateSupplier={() => {
                const name = detail.extraction?.supplier['name']?.value ?? ''
                void navigate(`/purchases/suppliers/new?name=${encodeURIComponent(name)}`)
              }}
            />

            {isDeliveryNote ? (
              <div>
                <label htmlFor="ingestion-location" className={tokens.label.base}>{t('review.location')}</label>
                <select
                  id="ingestion-location"
                  aria-label={t('review.location')}
                  className={tokens.select.base}
                  value={locationId}
                  onChange={(event) => { setLocationId(event.target.value) }}
                >
                  <option value="">{t('review.chooseLocation')}</option>
                  {locationOptions.map((location) => (
                    <option key={location.id} value={location.id}>{location.name}</option>
                  ))}
                </select>
              </div>
            ) : (
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  className={tokens.checkbox.base}
                  checked={pendingReceipt}
                  onChange={(event) => { setPendingReceipt(event.target.checked) }}
                />
                <span>{t('review.pendingReceipt')}</span>
              </label>
            )}
          </section>

          <LineMappingTable
            kind={detail.kind}
            currency={currency}
            lines={detail.extraction.lines}
            productCandidates={detail.suggestions?.productCandidates ?? []}
            receiptLineCandidates={detail.suggestions?.receiptLineCandidates ?? []}
            values={lineStates}
            onChange={updateLine}
          />

          {serverError && <div className={tokens.alert.error}>{serverError}</div>}

          <CommitBar
            canCommit={canCommit}
            isCommitting={commitMutation.isPending}
            isRejecting={rejectMutation.isPending}
            isReExtracting={reExtractMutation.isPending}
            refusalReason={blockReason}
            canReExtract={detail.status === 'failed' || detail.status === 'needs_review'}
            onCommit={() => { void handleCommit() }}
            onReject={() => { void handleReject() }}
            onReExtract={() => { void handleReExtract() }}
          />
        </div>
      </div>
    </div>
  )
}
