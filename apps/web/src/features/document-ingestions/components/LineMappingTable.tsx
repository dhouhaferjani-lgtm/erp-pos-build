import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, CheckCheck } from 'lucide-react'
import { MoneyInput, QuantityInput } from '@/components/atoms'
import { cn } from '@/lib/utils'
import { textColors, tokens, typography } from '@/lib/designTokens'
import { ProductPicker, type ProductPickerValue } from '@/components/molecules/pickers'
import { AddQuickProductModal } from '@/components/organisms'
import { buildProductPrefill } from '../buildProductPrefill'
import type { ExtractedLine, ProductCandidate, ReceiptLineCandidate } from '../types'

const buttonTokens = tokens.button

const formTokenClasses = {
  input: tokens.input.base,
  select: tokens.select.base,
  textarea: tokens.textarea.base,
  checkbox: tokens.checkbox.base,
  radio: tokens.radio.base,
}


export interface ReviewedLineState {
  productId: string
  variantId?: string
  quantity: string
  unitPrice: string
  vatRate: string
  freeQuantity: string
  batchNumber: string
  expiryDate: string
  sourceLineId: string
}

interface LineMappingTableProps {
  kind: string
  currency: string
  lines: readonly ExtractedLine[]
  productCandidates: readonly ProductCandidate[][]
  receiptLineCandidates: readonly ReceiptLineCandidate[]
  values: readonly ReviewedLineState[]
  initialValues: readonly ReviewedLineState[]
  onChange: (index: number, value: ReviewedLineState) => void
}

/**
 * Per-field validation cue: grey machine tick while the value still matches
 * what extraction produced, green human check once the reviewer has edited
 * it. Pure render-time comparison — the caller owns no extra state for this.
 */
function FieldCue({ edited }: { edited: boolean }) {
  const { t } = useTranslation(['documentIngestions'])
  const Icon = edited ? CheckCheck : Check
  const label = edited ? t('review.editedValue') : t('review.machineValue')
  return (
    <Icon
      role="img"
      aria-label={label}
      className={cn('h-4 w-4 shrink-0', edited ? textColors.success : textColors.tertiary)}
    />
  )
}

function requiresBatch(candidate: ProductCandidate | undefined, known: ProductPickerValue | undefined): boolean {
  if (known?.requires_batch_tracking !== undefined) {
    return known.requires_batch_tracking
  }
  return candidate?.requiresBatchTracking === true || candidate?.requires_batch_tracking === true
}

function receiptSourceId(candidate: ReceiptLineCandidate | undefined): string {
  return candidate?.poLineId ?? candidate?.po_line_id ?? ''
}

function candidateToPickerValue(candidate: ProductCandidate): ProductPickerValue {
  const value: ProductPickerValue = { id: candidate.id, sku: candidate.sku ?? '', name: candidate.name }
  const requiresBatchFlag = candidate.requiresBatchTracking ?? candidate.requires_batch_tracking
  if (requiresBatchFlag !== undefined) { value.requires_batch_tracking = requiresBatchFlag }
  return value
}

export function LineMappingTable({
  kind,
  currency,
  lines,
  productCandidates,
  receiptLineCandidates,
  values,
  initialValues,
  onChange,
}: LineMappingTableProps) {
  const { t } = useTranslation(['documentIngestions'])
  const isInvoice = kind === 'supplier_invoice'
  const [knownProducts, setKnownProducts] = useState<Record<string, ProductPickerValue>>({})
  const [createForIndex, setCreateForIndex] = useState<number | null>(null)
  // `lines` can shrink while the create-modal is open (e.g. a cross-session
  // re-extract) — resolve defensively so a stale index never crashes the page.
  const createForLine = createForIndex !== null ? lines[createForIndex] : undefined

  function registerProduct(product: ProductPickerValue): void {
    setKnownProducts((prev) => ({ ...prev, [product.id]: product }))
  }

  return (
    <section className={cn(tokens.card.base, 'space-y-4')} aria-label={t('review.lines')}>
      <h2 className={tokens.heading.section}>{t('review.lines')}</h2>
      <div className="space-y-4">
        {lines.map((line, index) => {
          const value = values[index]
          const initial = initialValues[index]
          const candidates = productCandidates[index] ?? []
          const selectedProduct = candidates.find((candidate) => candidate.id === value?.productId)
          const known = value?.productId ? knownProducts[value.productId] : undefined
          const batchRequired = requiresBatch(selectedProduct, known)
          if (!value) return null
          const pickerValue: ProductPickerValue | null = value.productId
            ? known ?? {
                id: value.productId,
                sku: '',
                name: selectedProduct?.name ?? t('review.selectedProduct'),
              }
            : null

          return (
            <div key={`${line.description.value}-${index}`} data-testid={`review-line-${index}`} className="space-y-3 rounded-[var(--radius-card)] border p-4">
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p className={cn(typography.fontWeight.medium, 'break-words')}>{line.description.value}</p>
                <p className={cn(typography.fontSize.sm, textColors.tertiary, 'shrink-0')}>
                  {line.supplierRef?.value ?? t('review.noSupplierRef')}
                </p>
              </div>
              <div className="grid gap-3 lg:grid-cols-12">
                <div className="lg:col-span-4">
                  {candidates.length > 0 && (
                    <div className="mb-2">
                      <p className={tokens.label.base}>{t('review.suggested')}</p>
                      <div className="flex flex-wrap gap-1" role="group" aria-label={t('review.suggested')}>
                        {candidates.map((candidate) => (
                          <button
                            key={candidate.id}
                            type="button"
                            data-testid={`candidate-chip-${index}-${candidate.id}`}
                            aria-pressed={value.productId === candidate.id}
                            className={cn(
                              tokens.badge.base,
                              value.productId === candidate.id ? tokens.badge.blue : tokens.badge.outline,
                            )}
                            onClick={() => {
                              registerProduct(candidateToPickerValue(candidate))
                              onChange(index, {
                                ...value,
                                productId: candidate.id,
                                vatRate: value.vatRate || candidate.taxRate || candidate.tax_rate || '',
                              })
                            }}
                          >
                            {candidate.name}
                          </button>
                        ))}
                      </div>
                    </div>
                  )}
                  <ProductPicker
                    value={pickerValue}
                    onChange={(next) => {
                      if (next !== null) { registerProduct(next) }
                      onChange(index, { ...value, productId: next?.id ?? '', vatRate: value.vatRate })
                    }}
                    productType="all"
                    label={t('review.product')}
                    testId={`line-product-picker-${index}`}
                  />
                  <button
                    type="button"
                    className={cn(buttonTokens.base, buttonTokens.secondary, buttonTokens.sizes.sm, 'mt-2')}
                    onClick={() => { setCreateForIndex(index) }}
                  >
                    {t('review.newProduct')}
                  </button>
                </div>
                <div className="lg:col-span-2">
                  <div className="flex items-center justify-between gap-2">
                    <label className={tokens.label.base} htmlFor={`quantity-${index}`}>{t('review.quantity')}</label>
                    <FieldCue edited={initial !== undefined && value.quantity !== initial.quantity} />
                  </div>
                  <QuantityInput
                    id={`quantity-${index}`}
                    aria-label={t('review.quantity')}
                    value={value.quantity}
                    onChange={(quantity) => { onChange(index, { ...value, quantity }) }}
                    decimalPlaces={4}
                    className="tabular-nums"
                  />
                </div>
                <div className="lg:col-span-2">
                  <div className="flex items-center justify-between gap-2">
                    <label className={tokens.label.base} htmlFor={`unit-price-${index}`}>{t('review.unitPrice')}</label>
                    <FieldCue edited={initial !== undefined && value.unitPrice !== initial.unitPrice} />
                  </div>
                  <MoneyInput
                    id={`unit-price-${index}`}
                    aria-label={t('review.unitPrice')}
                    value={value.unitPrice}
                    onChange={(unitPrice) => { onChange(index, { ...value, unitPrice }) }}
                    currency={currency}
                    className="tabular-nums"
                  />
                </div>
                {isInvoice && (
                  <div className="lg:col-span-2">
                    <div className="flex items-center justify-between gap-2">
                      <label className={tokens.label.base} htmlFor={`vat-rate-${index}`}>{t('review.vatRate')}</label>
                      <FieldCue edited={initial !== undefined && value.vatRate !== initial.vatRate} />
                    </div>
                    <QuantityInput
                      id={`vat-rate-${index}`}
                      aria-label={t('review.vatRate')}
                      value={value.vatRate}
                      onChange={(vatRate) => { onChange(index, { ...value, vatRate }) }}
                      decimalPlaces={2}
                      className="tabular-nums"
                    />
                  </div>
                )}
                {isInvoice && (
                  <div className="lg:col-span-2">
                    <label className={tokens.label.base} htmlFor={`source-line-${index}`}>{t('review.sourceLine')}</label>
                    <select
                      id={`source-line-${index}`}
                      className={formTokenClasses.select}
                      value={value.sourceLineId}
                      onChange={(event) => { onChange(index, { ...value, sourceLineId: event.target.value }) }}
                    >
                      <option value="">{t('review.pendingReceipt')}</option>
                      {receiptLineCandidates.map((candidate) => (
                        <option key={receiptSourceId(candidate)} value={receiptSourceId(candidate)}>
                          {candidate.label ?? receiptSourceId(candidate)}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
                {batchRequired && (
                  <>
                    <div className="lg:col-span-3">
                      <label className={tokens.label.base} htmlFor={`batch-${index}`}>{t('review.batchNumber')}</label>
                      <input
                        id={`batch-${index}`}
                        aria-label={t('review.batchNumber')}
                        className={formTokenClasses.input}
                        value={value.batchNumber}
                        onChange={(event) => { onChange(index, { ...value, batchNumber: event.target.value }) }}
                      />
                    </div>
                    <div className="lg:col-span-3">
                      <label className={tokens.label.base} htmlFor={`expiry-${index}`}>{t('review.expiryDate')}</label>
                      <input
                        id={`expiry-${index}`}
                        aria-label={t('review.expiryDate')}
                        type="date"
                        className={formTokenClasses.input}
                        value={value.expiryDate}
                        onChange={(event) => { onChange(index, { ...value, expiryDate: event.target.value }) }}
                      />
                    </div>
                  </>
                )}
              </div>
            </div>
          )
        })}
      </div>
      <AddQuickProductModal
        isOpen={createForIndex !== null}
        {...(createForIndex !== null && createForLine !== undefined
          && { prefill: buildProductPrefill(createForLine) })}
        onClose={() => { setCreateForIndex(null) }}
        onSuccess={(product) => {
          if (createForIndex === null) { return }
          const created: ProductPickerValue = { id: product.id, sku: product.sku ?? '', name: product.name }
          if (product.quantity_decimals !== undefined && product.quantity_decimals !== null) {
            created.quantity_decimals = product.quantity_decimals
          }
          registerProduct(created)
          const current = values[createForIndex]
          if (current !== undefined) {
            onChange(createForIndex, {
              ...current,
              productId: product.id,
              vatRate: current.vatRate || String(product.tax_rate),
            })
          }
          setCreateForIndex(null)
        }}
      />
    </section>
  )
}
