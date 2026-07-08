import { useTranslation } from 'react-i18next'
import { MoneyInput, QuantityInput } from '@/components/atoms'
import { cn } from '@/lib/utils'
import { textColors, tokens, typography } from '@/lib/designTokens'
import type { ExtractedLine, ProductCandidate, ReceiptLineCandidate } from '../types'

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
  onChange: (index: number, value: ReviewedLineState) => void
}

function requiresBatch(candidate: ProductCandidate | undefined): boolean {
  return candidate?.requiresBatchTracking === true || candidate?.requires_batch_tracking === true
}

function receiptSourceId(candidate: ReceiptLineCandidate | undefined): string {
  return candidate?.poLineId ?? candidate?.po_line_id ?? ''
}

export function LineMappingTable({
  kind,
  currency,
  lines,
  productCandidates,
  receiptLineCandidates,
  values,
  onChange,
}: LineMappingTableProps) {
  const { t } = useTranslation(['documentIngestions'])
  const isInvoice = kind === 'supplier_invoice'

  return (
    <section className={cn(tokens.card.base, 'space-y-4')} aria-label={t('review.lines')}>
      <h2 className={tokens.heading.section}>{t('review.lines')}</h2>
      <div className="space-y-4">
        {lines.map((line, index) => {
          const value = values[index]
          const candidates = productCandidates[index] ?? []
          const selectedProduct = candidates.find((candidate) => candidate.id === value?.productId)
          const batchRequired = requiresBatch(selectedProduct)
          if (!value) return null

          return (
            <div key={`${line.description.value}-${index}`} data-testid={`review-line-${index}`} className="grid gap-3 rounded-[var(--radius-card)] border p-4 lg:grid-cols-6">
              <div className="lg:col-span-2">
                <p className={typography.fontWeight.medium}>{line.description.value}</p>
                <p className={cn(typography.fontSize.sm, textColors.tertiary)}>{line.supplierRef?.value ?? t('review.noSupplierRef')}</p>
              </div>
              <div>
                <label className={tokens.label.base} htmlFor={`product-${index}`}>{t('review.product')}</label>
                <select
                  id={`product-${index}`}
                  aria-label={t('review.product')}
                  className={tokens.select.base}
                  value={value.productId}
                  onChange={(event) => {
                    const nextProduct = candidates.find((candidate) => candidate.id === event.target.value)
                    onChange(index, {
                      ...value,
                      productId: event.target.value,
                      vatRate: value.vatRate || nextProduct?.taxRate || nextProduct?.tax_rate || '',
                    })
                  }}
                >
                  <option value="">{t('review.chooseProduct')}</option>
                  {candidates.map((candidate) => (
                    <option key={candidate.id} value={candidate.id}>{candidate.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={tokens.label.base} htmlFor={`quantity-${index}`}>{t('review.quantity')}</label>
                <QuantityInput
                  id={`quantity-${index}`}
                  aria-label={t('review.quantity')}
                  value={value.quantity}
                  onChange={(quantity) => { onChange(index, { ...value, quantity }) }}
                  decimalPlaces={4}
                />
              </div>
              <div>
                <label className={tokens.label.base} htmlFor={`unit-price-${index}`}>{t('review.unitPrice')}</label>
                <MoneyInput
                  id={`unit-price-${index}`}
                  aria-label={t('review.unitPrice')}
                  value={value.unitPrice}
                  onChange={(unitPrice) => { onChange(index, { ...value, unitPrice }) }}
                  currency={currency}
                />
              </div>
              {isInvoice && (
                <div>
                  <label className={tokens.label.base} htmlFor={`vat-rate-${index}`}>{t('review.vatRate')}</label>
                  <QuantityInput
                    id={`vat-rate-${index}`}
                    aria-label={t('review.vatRate')}
                    value={value.vatRate}
                    onChange={(vatRate) => { onChange(index, { ...value, vatRate }) }}
                    decimalPlaces={2}
                  />
                </div>
              )}
              {isInvoice && (
                <div>
                  <label className={tokens.label.base} htmlFor={`source-line-${index}`}>{t('review.sourceLine')}</label>
                  <select
                    id={`source-line-${index}`}
                    className={tokens.select.base}
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
                  <div>
                    <label className={tokens.label.base} htmlFor={`batch-${index}`}>{t('review.batchNumber')}</label>
                    <input
                      id={`batch-${index}`}
                      aria-label={t('review.batchNumber')}
                      className={tokens.input.base}
                      value={value.batchNumber}
                      onChange={(event) => { onChange(index, { ...value, batchNumber: event.target.value }) }}
                    />
                  </div>
                  <div>
                    <label className={tokens.label.base} htmlFor={`expiry-${index}`}>{t('review.expiryDate')}</label>
                    <input
                      id={`expiry-${index}`}
                      aria-label={t('review.expiryDate')}
                      type="date"
                      className={tokens.input.base}
                      value={value.expiryDate}
                      onChange={(event) => { onChange(index, { ...value, expiryDate: event.target.value }) }}
                    />
                  </div>
                </>
              )}
            </div>
          )
        })}
      </div>
    </section>
  )
}
