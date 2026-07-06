import { useState, useCallback, useEffect, useMemo, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Info, Plus, Trash2 } from 'lucide-react'
import { formatCurrency } from '../../../lib/format'
import { bcadd, bccomp, bcdiv, bcmul, bcsub } from '../../../lib/decimal'
import { apiPost } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { AddQuickProductModal } from '../../../components/organisms/AddQuickProductModal/AddQuickProductModal'
import { TaxConfigurationSelect } from '../../../components/atoms/TaxConfigurationSelect/TaxConfigurationSelect'
import { MoneyInput } from '../../../components/atoms/MoneyInput/MoneyInput'
import { QuantityInput } from '../../../components/atoms/QuantityInput/QuantityInput'
import { LineItemsTable, QuantityCell, type LineItemsTableColumn } from '../../../components/molecules/line-items/LineItemsTable'
import { LineItemEntryBar, ProductCell, type LineItemEntryAddMeta, type ProductLineProduct } from '../../../components/molecules/line-items'
import { useCompanyConfig } from '../../../contexts/CompanyConfigContext'
import { DesignationCell } from './DesignationCell'
import { NotesCell } from './NotesCell'
import { useLineDesignationFeature } from '../hooks/useLineDesignationFeature'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { borderColors, colors, textColors, tokens } from '../../../lib/designTokens'

// Map frontend document type strings to backend applicable_document_types format
const DOCUMENT_TYPE_MAP: Record<string, string> = {
  invoice: 'TAX_INVOICE',
  delivery_note: 'DELIVERY_NOTE',
  credit_note: 'CREDIT_NOTE',
  return_note: 'RETURN_NOTE',
}

function taxSelectorDocumentType(documentType: string | undefined): string | undefined {
  if (!documentType) return undefined
  return DOCUMENT_TYPE_MAP[documentType]
}

function decimalValue(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '0'
  return String(value)
}

function calculateDiscountedSubtotal(
  quantity: string | number,
  unitPrice: string | number,
  discountPercent?: string | null,
  discountAmount?: string | null,
): string {
  const grossSubtotal = bcmul(decimalValue(quantity), decimalValue(unitPrice))
  const discount = discountPercent !== undefined && discountPercent !== null && discountPercent !== ''
    ? bcmul(grossSubtotal, bcdiv(discountPercent, '100', 6))
    : decimalValue(discountAmount)

  if (bccomp(discount, grossSubtotal) >= 0) return '0.000'

  return bcsub(grossSubtotal, discount)
}

function calculateLineTax(discountedSubtotal: string, taxRate: string | number): string {
  return bcmul(discountedSubtotal, bcdiv(decimalValue(taxRate), '100', 6))
}

function calculateLineTotal(
  quantity: string | number,
  unitPrice: string | number,
  taxRate: string | number,
  discountPercent?: string | null,
  discountAmount?: string | null,
): string {
  const discountedSubtotal = calculateDiscountedSubtotal(quantity, unitPrice, discountPercent, discountAmount)
  return bcadd(discountedSubtotal, calculateLineTax(discountedSubtotal, taxRate))
}

function calculateTotalFromNetAmount(netAmount: string | number, taxRate: string | number): string {
  const net = decimalValue(netAmount)
  return bcadd(net, calculateLineTax(net, taxRate))
}

function calculateNetExtendedAmount(line: DocumentLine): string {
  return calculateDiscountedSubtotal(
    line.quantity,
    line.unit_price,
    line.discount_percent,
    line.discount_amount,
  )
}

interface Product {
  id: string
  name: string
  sku?: string | null
  barcode?: string | null
  sale_price?: string | number | null
  tax_rate?: string | number | null
  default_tax_configuration_id?: string | null
  quantity_decimals?: number | null
  primary_image_url?: string | null
  has_variants?: boolean
  requires_batch_tracking?: boolean
}

export interface DocumentLine {
  id: string
  product_id: string
  variant_id?: string | null
  service_id?: string
  product_code?: string
  product_barcode?: string | null
  product_name: string
  primary_image_url?: string | null
  description: string
  designation_default_snapshot?: string | null
  notes?: string | null
  quantity: string | number
  unit_price: string | number
  discount_percent?: string | null
  discount_amount?: string | null
  tax_rate: string | number
  tax_configuration_id?: string | null
  line_total: string | number
  free_quantity?: string | number | null
  free_quantity_received?: string | number | null
  free_quantity_invoiced?: string | number | null
  price_entry_mode?: 'unit' | 'total'
  landed_unit_cost?: string | number | null
  is_bonus_line?: boolean
  is_service?: boolean
  /** Unit precision (unit decimal_places) → drives the qty input step. */
  quantity_decimals?: number | null
}

interface DocumentLineEditorProps {
  lines: DocumentLine[]
  onChange: (lines: DocumentLine[]) => void
  readonly?: boolean
  documentType?: string
  partnerId?: string | null
}

interface PricingContextLineRequest {
  product_id: string
  variant_id: string | null
  unit_price: string
}

interface PricingPolicy {
  level: 'green' | 'yellow' | 'orange' | 'red'
  allowed: boolean
  requires_permission: string | null
}

interface PricingContextItem {
  currency: string
  cost_wac: string
  last_purchase_cost: string | null
  last_purchase_at: string | null
  last_sale_to_partner: {
    unit_price: string
    at: string | null
    document_no: string
  } | null
  suggested_price: string
  target_margin_pct: string
  minimum_margin_pct: string
  policy: PricingPolicy
}

interface PricingContextResponse {
  items: Record<string, PricingContextItem>
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

function pricingContextKey(line: Pick<DocumentLine, 'product_id' | 'variant_id'>): string {
  if ((line.variant_id ?? null) === null || line.variant_id === '') {
    return line.product_id
  }
  return `${line.product_id}:${line.variant_id}`
}

export function DocumentLineEditor({ lines, onChange, readonly = false, documentType, partnerId = null }: DocumentLineEditorProps) {
  const { t } = useTranslation(['sales', 'common'])
  const queryClient = useQueryClient()
  const { config: companyConfig, hasModule } = useCompanyConfig()
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const designationFeatureEnabled = useLineDesignationFeature()
  const [showProductModal, setShowProductModal] = useState(false)
  const [focusedPriceLineId, setFocusedPriceLineId] = useState<string | null>(null)
  const [openPricingLineId, setOpenPricingLineId] = useState<string | null>(null)
  const linesRef = useRef(lines)

  useEffect(() => {
    linesRef.current = lines
  }, [lines])

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'
  const taxDocumentType = taxSelectorDocumentType(documentType)
  const purchaseBonusEnabled =
    documentType === 'purchase_order' &&
    hasModule('PurchaseBonus') &&
    companyConfig?.purchase_bonus_enabled === true

  const deriveUnitPrice = useCallback((line: DocumentLine, netTotal?: string | number): string => {
    const paidQuantity = decimalValue(line.quantity)
    if ((line.price_entry_mode ?? 'unit') !== 'total') {
      return decimalValue(line.unit_price)
    }
    if (bccomp(paidQuantity, '0') <= 0) {
      return '0.000'
    }
    const netAmount = decimalValue(netTotal ?? calculateNetExtendedAmount(line))
    const workingScale = 4

    if (line.discount_percent !== undefined && line.discount_percent !== null && line.discount_percent !== '') {
      const discountRate = bcdiv(line.discount_percent, '100', workingScale)
      const payableRate = bcsub('1', discountRate, workingScale)
      const discountedQuantity = bcmul(paidQuantity, payableRate, workingScale)

      if (bccomp(discountedQuantity, '0') <= 0) {
        return '0.000'
      }

      return bcdiv(netAmount, discountedQuantity, 3)
    }

    if (line.discount_amount !== undefined && line.discount_amount !== null && line.discount_amount !== '') {
      return bcdiv(bcadd(netAmount, decimalValue(line.discount_amount), workingScale), paidQuantity, 3)
    }

    return bcdiv(netAmount, paidQuantity, 3)
  }, [])

  const pricingContextLines = useMemo<PricingContextLineRequest[]>(() => (
    lines
      .filter((line) => !line.is_service && line.product_id !== '')
      .map((line) => ({
        product_id: line.product_id,
        variant_id: line.variant_id ?? null,
        unit_price: decimalValue((line.price_entry_mode ?? 'unit') === 'total' ? deriveUnitPrice(line) : line.unit_price),
      }))
  ), [deriveUnitPrice, lines])
  const pricingContextSignature = useMemo(
    () => pricingContextLines
      .map((line) => `${line.product_id}:${line.variant_id ?? ''}:${line.unit_price}`)
      .join('|'),
    [pricingContextLines],
  )
  const pricingContextEnabled =
    !readonly &&
    focusedPriceLineId !== null &&
    pricingContextLines.length > 0 &&
    tenantId !== null &&
    companyId !== null

  const { data: pricingContext } = useQuery({
    queryKey: tenantScopedKey(['line-entry-pricing-context', partnerId ?? null, pricingContextSignature]),
    queryFn: () => apiPost<PricingContextResponse>('/line-entry/pricing-context/bulk', {
      partner_id: partnerId ?? null,
      lines: pricingContextLines,
    }),
    enabled: pricingContextEnabled,
    staleTime: 30000,
  })

  // Calculate totals
  const totals = useMemo(() => {
    const subtotal = lines.reduce(
      (sum, line) => bcadd(sum, calculateDiscountedSubtotal(
        line.quantity,
        line.unit_price,
        line.discount_percent,
        line.discount_amount,
      )),
      '0.000',
    )
    const tax = lines.reduce(
      (sum, line) => {
        const lineSubtotal = calculateDiscountedSubtotal(
          line.quantity,
          line.unit_price,
          line.discount_percent,
          line.discount_amount,
        )
        return bcadd(sum, calculateLineTax(lineSubtotal, line.tax_rate))
      },
      '0.000',
    )
    return {
      subtotal,
      tax,
      total: bcadd(subtotal, tax),
    }
  }, [lines])

  // Generate unique ID for new lines
  const generateId = () => `line-${String(Date.now())}-${Math.random().toString(36).substring(2, 11)}`

  // Add product to lines
  const handleAddProduct = useCallback(
    (product: Product | ProductLineProduct, meta?: Partial<LineItemEntryAddMeta>) => {
      const variantId = meta?.variantId ?? null
      const incrementBy = meta?.incrementBy ?? 1
      const existingLine = linesRef.current.find((line) =>
        !line.is_service &&
        line.product_id === product.id &&
        (line.variant_id ?? null) === variantId
      )

      if (existingLine !== undefined) {
        onChange(
          linesRef.current.map((line) => {
            if (line.id !== existingLine.id) return line
            const quantity = bcadd(decimalValue(line.quantity), String(incrementBy), getQuantityDecimals(line))
            return {
              ...line,
              quantity,
              line_total: calculateLineTotal(
                quantity,
                line.unit_price,
                line.tax_rate,
                line.discount_percent,
                line.discount_amount,
              ),
            }
          })
        )
        return
      }

      const salePrice = decimalValue(product.sale_price)
      const taxRate = decimalValue(product.tax_rate)
      const newLine: DocumentLine = {
        id: generateId(),
        product_id: product.id,
        variant_id: variantId,
        product_code: product.sku ?? '',
        product_barcode: product.barcode ?? null,
        product_name: product.name,
        primary_image_url: product.primary_image_url ?? null,
        description: product.name,
        designation_default_snapshot: product.name,
        notes: null,
        quantity: String(incrementBy),
        unit_price: salePrice,
        discount_percent: null,
        discount_amount: null,
        tax_rate: taxRate,
        tax_configuration_id: product.default_tax_configuration_id ?? null,
        line_total: calculateLineTotal(incrementBy, salePrice, taxRate, null, null),
        free_quantity: '0',
        price_entry_mode: 'unit',
        quantity_decimals: product.quantity_decimals ?? null,
      }
      onChange([...linesRef.current, newLine])
    },
    [onChange]
  )

  // Add blank line
  const handleAddBlankLine = useCallback(() => {
    const newLine: DocumentLine = {
      id: generateId(),
      product_id: '',
      product_name: '',
      description: '',
      quantity: 1,
      unit_price: 0,
      discount_percent: null,
      discount_amount: null,
      tax_rate: 0,
      line_total: '0.000',
      free_quantity: '0',
      price_entry_mode: 'unit',
    }
    onChange([...lines, newLine])
  }, [lines, onChange])

  // Update line
  const handleUpdateLine = useCallback(
    (lineId: string, updates: Partial<DocumentLine>) => {
      onChange(
        linesRef.current.map((line) => {
          if (line.id !== lineId) return line
          const updatedLine = { ...line, ...updates }
          // Recalculate line total if quantity, price, discount, or tax changed
          if (
            'quantity' in updates ||
            'unit_price' in updates ||
            'line_total' in updates ||
            'price_entry_mode' in updates ||
            'discount_percent' in updates ||
            'discount_amount' in updates ||
            'tax_rate' in updates
          ) {
            if ((updatedLine.price_entry_mode ?? 'unit') === 'total') {
              const netTotal = 'line_total' in updates ? updates.line_total : calculateNetExtendedAmount(updatedLine)
              updatedLine.unit_price = deriveUnitPrice(updatedLine, netTotal)
              updatedLine.line_total = calculateTotalFromNetAmount(decimalValue(netTotal), updatedLine.tax_rate)
            } else {
              updatedLine.line_total = calculateLineTotal(
                updatedLine.quantity,
                updatedLine.unit_price,
                updatedLine.tax_rate,
                updatedLine.discount_percent,
                updatedLine.discount_amount,
              )
            }
          }
          return updatedLine
        })
      )
    },
    [deriveUnitPrice, onChange]
  )

  const bonusFacts = useCallback((line: DocumentLine) => {
    const freeQuantity = decimalValue(line.free_quantity)
    if (bccomp(freeQuantity, '0') <= 0) return null

    const paidQuantity = decimalValue(line.quantity)
    const physicalQuantity = bcadd(paidQuantity, freeQuantity, getQuantityDecimals(line))
    if (bccomp(physicalQuantity, '0') <= 0) return null

    const unitPrice = deriveUnitPrice(line)
    const netTotal = calculateNetExtendedAmount({ ...line, unit_price: unitPrice })
    return {
      effectiveUnitCost: bcdiv(netTotal, physicalQuantity, 6),
      savings: bcmul(freeQuantity, unitPrice),
    }
  }, [deriveUnitPrice])

  // Remove line
  const handleRemoveLine = useCallback(
    (lineId: string) => {
      onChange(linesRef.current.filter((line) => line.id !== lineId))
    },
    [onChange]
  )

  const handleReorderLines = useCallback(
    (fromIndex: number, toIndex: number) => {
      if (fromIndex === toIndex) return
      const newLines = [...linesRef.current]
      const [draggedLine] = newLines.splice(fromIndex, 1)
      newLines.splice(toIndex, 0, draggedLine)
      onChange(newLines)
    },
    [onChange]
  )

  // Format currency using company settings
  const formatAmount = useCallback((amount: string | number) => {
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }, [companyCurrency, companyLocale])

  const lineColumns = useMemo<LineItemsTableColumn<DocumentLine>[]>(() => [
    {
      id: 'article',
      header: t('sales:lineItems.article'),
      headerClassName: 'min-w-56',
      Cell: ({ line }) => (
        <div className="min-w-56">
          <ProductCell
            size="sm"
            product={{
              name: line.product_name || line.description || '-',
              sku: line.product_code ?? null,
              barcode: line.product_barcode ?? null,
              primary_image_url: line.primary_image_url ?? null,
            }}
          />
          {line.is_service && (
            <span className={`mt-1 inline-flex rounded-full ${colors.neutral[100]} px-1.5 py-0.5 text-[10px] font-medium ${textColors.secondary}`}>
              {t('sales:lineItems.serviceBadge')}
            </span>
          )}
        </div>
      ),
    },
    {
      id: 'description',
      header: t('sales:lineItems.description'),
      Cell: ({ line }) => (
        <>
          {designationFeatureEnabled ? (
            <DesignationCell
              value={line.description || line.product_name}
              originalSnapshot={line.designation_default_snapshot ?? null}
              readOnly={readonly}
              onCommit={(next) => {
                handleUpdateLine(line.id, { description: next })
              }}
            />
          ) : (
            <span className={`text-sm ${textColors.primary}`}>{line.description || line.product_name}</span>
          )}
          {designationFeatureEnabled && (
            <NotesCell
              value={line.notes ?? null}
              readOnly={readonly}
              onCommit={(next) => {
                handleUpdateLine(line.id, { notes: next })
              }}
            />
          )}
        </>
      ),
    },
    {
      id: 'quantity',
      header: t('sales:lineItems.quantity'),
      headerClassName: 'w-24 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        <QuantityCell
          decimalPlaces={getQuantityDecimals(line)}
          min="0"
          readonly={readonly}
          value={line.quantity}
          onChange={(value) => {
            handleUpdateLine(line.id, { quantity: value })
          }}
          ariaLabel={t('sales:lineItems.quantity')}
        />
      ),
    },
    ...(purchaseBonusEnabled
      ? [
          {
            id: 'free-quantity',
            header: t('sales:lineItems.freeQuantity'),
            headerClassName: 'w-28 text-end',
            cellClassName: 'text-end',
            Cell: ({ line }) => (
              readonly ? (
                <span className={`text-sm ${textColors.primary}`}>{line.free_quantity ?? '0'}</span>
              ) : (
                <QuantityInput
                  decimalPlaces={getQuantityDecimals(line)}
                  min="0"
                  value={decimalValue(line.free_quantity)}
                  onChange={(value) => {
                    handleUpdateLine(line.id, { free_quantity: value })
                  }}
                  aria-label={t('sales:lineItems.freeQuantity')}
                  className={`${tokens.input.base} w-24 text-end text-sm`}
                />
              )
            ),
          } satisfies LineItemsTableColumn<DocumentLine>,
        ]
      : []),
    {
      id: 'unit-price',
      header: t('sales:lineItems.unitPrice'),
      headerClassName: purchaseBonusEnabled ? 'w-40 text-end' : 'w-32 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => {
        const pricingItem = line.product_id !== '' ? pricingContext?.items[pricingContextKey(line)] : undefined
        const policy = pricingItem?.policy
        const isBlocked = policy?.allowed === false
        const isWarning = policy !== undefined && policy.allowed && policy.level !== 'green'
        const policyMessage = isBlocked
          ? t('sales:lineItems.pricing.policyBlocked', { permission: policy.requires_permission ?? '' })
          : isWarning
            ? t('sales:lineItems.pricing.policyWarning')
            : null
        const detailsOpen = openPricingLineId === line.id

        if (readonly) {
          return <span className={`text-sm ${textColors.primary}`}>{formatAmount(deriveUnitPrice(line))}</span>
        }

        return (
          <div className="relative flex min-w-40 flex-col items-end gap-1">
            <div className="flex items-center justify-end gap-2">
              <MoneyInput
                currency={companyCurrency}
                min="0"
                error={isBlocked}
                value={(line.price_entry_mode ?? 'unit') === 'total' ? calculateNetExtendedAmount(line) : decimalValue(line.unit_price)}
                onFocus={() => {
                  setFocusedPriceLineId(line.id)
                }}
                onChange={(value) => {
                  if ((line.price_entry_mode ?? 'unit') === 'total') {
                    handleUpdateLine(line.id, { line_total: value })
                    return
                  }
                  handleUpdateLine(line.id, { unit_price: value })
                }}
                aria-label={t('sales:lineItems.unitPrice')}
                className={`${tokens.input.base} w-28 text-end text-sm`}
              />
              {purchaseBonusEnabled && (
                <button
                  type="button"
                  onClick={() => {
                    handleUpdateLine(line.id, {
                      price_entry_mode: (line.price_entry_mode ?? 'unit') === 'total' ? 'unit' : 'total',
                    })
                  }}
                  className={`rounded ${colors.neutral[100]} px-2 py-1 text-xs font-medium ${textColors.secondary} ${colors.hover.gray50}`}
                  aria-pressed={(line.price_entry_mode ?? 'unit') === 'total'}
                >
                  {(line.price_entry_mode ?? 'unit') === 'total'
                    ? t('sales:lineItems.priceEntryMode.unit')
                    : t('sales:lineItems.priceEntryMode.total')}
                </button>
              )}
            </div>

            {pricingItem !== undefined && (
              <div className={`max-w-72 text-end text-[11px] leading-4 ${isBlocked ? textColors.error : isWarning ? textColors.warning : textColors.secondary}`}>
                <div className="flex flex-wrap items-center justify-end gap-x-1">
                  <span>
                    {t('sales:lineItems.pricing.cost', { amount: pricingItem.cost_wac })}
                    {' · '}
                    {t('sales:lineItems.pricing.lastBuy', { amount: pricingItem.last_purchase_cost ?? '-' })}
                    {' · '}
                    {t('sales:lineItems.pricing.margin', { percent: pricingItem.target_margin_pct })}
                  </span>
                  <button
                    type="button"
                    onClick={() => {
                      setOpenPricingLineId(detailsOpen ? null : line.id)
                    }}
                    className={`inline-flex items-center ${textColors.hoverPrimary}`}
                    aria-label={t('sales:lineItems.pricing.details')}
                  >
                    <Info className="h-3.5 w-3.5" />
                  </button>
                </div>
                {policyMessage !== null && (
                  <div>{policyMessage}</div>
                )}
              </div>
            )}

            {detailsOpen && pricingItem !== undefined && (
              <div className={`absolute end-0 top-full z-10 mt-1 w-72 rounded-md border ${borderColors.light} ${colors.white} p-3 text-start text-xs shadow-lg`}>
                <div className="space-y-1">
                  <div className={textColors.secondary}>{t('sales:lineItems.pricing.suggested', { amount: pricingItem.suggested_price })}</div>
                  <div className={textColors.secondary}>
                    {t('sales:lineItems.pricing.lastSale', { amount: pricingItem.last_sale_to_partner?.unit_price ?? '-' })}
                  </div>
                  <div className={textColors.secondary}>
                    {t('sales:lineItems.pricing.minimumMargin', { percent: pricingItem.minimum_margin_pct })}
                  </div>
                </div>
                <button
                  type="button"
                  className={`mt-3 rounded ${colors.neutral[100]} px-2 py-1 text-xs font-medium ${textColors.secondary} ${colors.hover.gray50}`}
                  onClick={() => {
                    setOpenPricingLineId(null)
                    handleUpdateLine(line.id, {
                      price_entry_mode: 'unit',
                      unit_price: pricingItem.suggested_price,
                    })
                  }}
                >
                  {t('sales:lineItems.pricing.useSuggested')}
                </button>
              </div>
            )}
          </div>
        )
      },
    },
    {
      id: 'discount',
      header: t('sales:lineItems.discount'),
      headerClassName: 'w-28 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        readonly ? (
          <span className={`text-sm ${textColors.primary}`}>{line.discount_percent ?? '0'}%</span>
        ) : (
          <input
            type="number"
            inputMode="decimal"
            min="0"
            max="100"
            value={line.discount_percent ?? ''}
            onChange={(event) => {
              handleUpdateLine(line.id, {
                discount_percent: event.target.value === '' ? null : event.target.value,
                discount_amount: null,
              })
            }}
            aria-label={t('sales:lineItems.discount')}
            className={`${tokens.input.base} w-20 text-end text-sm`}
          />
        )
      ),
    },
    {
      id: 'tax',
      header: t('sales:lineItems.taxPercent'),
      headerClassName: 'w-20 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        readonly ? (
          <span className={`text-sm ${textColors.disabled}`}>{line.tax_rate}%</span>
        ) : (
          <TaxConfigurationSelect
            value={line.tax_configuration_id ?? null}
            onChange={(configId, taxRate) => {
              handleUpdateLine(line.id, {
                tax_configuration_id: configId,
                tax_rate: Number(taxRate) || 0,
              })
            }}
            {...(taxDocumentType !== undefined ? { documentType: taxDocumentType } : {})}
            size="sm"
          />
        )
      ),
    },
    {
      id: 'total',
      header: t('sales:lineItems.total'),
      headerClassName: 'w-32 text-end',
      cellClassName: `whitespace-nowrap text-end text-sm font-medium ${textColors.primary}`,
      Cell: ({ line }) => <>{formatAmount(line.line_total)}</>,
    },
    ...(!readonly
      ? [
          {
            id: 'actions',
            header: <span className="sr-only">{t('common:table.actionsColumn')}</span>,
            headerClassName: 'w-12',
            cellClassName: 'text-center',
            Cell: ({ line }) => (
              <button
                type="button"
                onClick={() => {
                  handleRemoveLine(line.id)
                }}
                className={`${textColors.disabled} ${textColors.hoverError}`}
                aria-label={t('sales:lineItems.actions.removeLine')}
              >
                <Trash2 className="h-4 w-4" />
              </button>
            ),
          } satisfies LineItemsTableColumn<DocumentLine>,
        ]
      : []),
  ], [
    companyCurrency,
    designationFeatureEnabled,
    deriveUnitPrice,
    formatAmount,
    handleRemoveLine,
    handleUpdateLine,
    purchaseBonusEnabled,
    readonly,
    t,
    taxDocumentType,
  ])

  const totalsFooter = (
    <div className="flex justify-end">
      <dl className="w-64 space-y-2">
        <div className="flex justify-between text-sm">
          <dt className={textColors.disabled}>{t('sales:lineItems.subtotal')}</dt>
          <dd className={`font-medium ${textColors.primary}`}>{formatAmount(totals.subtotal)}</dd>
        </div>
        <div className="flex justify-between text-sm">
          <dt className={textColors.disabled}>{t('sales:lineItems.tax')}</dt>
          <dd className={`font-medium ${textColors.primary}`}>{formatAmount(totals.tax)}</dd>
        </div>
        <div className={`flex justify-between border-t ${borderColors.light} pt-2 text-base`}>
          <dt className={`font-semibold ${textColors.primary}`}>{t('sales:lineItems.total')}</dt>
          <dd className={`font-semibold ${textColors.primary}`}>{formatAmount(totals.total)}</dd>
        </div>
      </dl>
    </div>
  )

  return (
    <div className="space-y-4">
      <LineItemsTable
        title={t('sales:lineItems.title')}
        lines={lines}
        columns={lineColumns}
        getLineKey={(line) => line.id}
        emptyTitle={t('sales:lineItems.empty.title')}
        emptyDescription={!readonly ? t('sales:lineItems.empty.description') : undefined}
        readonly={readonly}
        footer={totalsFooter}
        dragAndDrop={{
          dragAriaLabel: t('sales:lineItems.actions.dragToReorder'),
          onReorder: handleReorderLines,
        }}
        renderLineDetail={(line) => {
              if (!purchaseBonusEnabled) return null
              const facts = bonusFacts(line)
              if (facts === null) return null

              return (
                <div className={`border-t ${borderColors.light} ${colors.neutral[50]} px-6 py-2 text-xs ${textColors.secondary}`}>
                  <div>{t('sales:lineItems.effectiveUnitCost', { amount: formatAmount(facts.effectiveUnitCost) })}</div>
                  <div>{t('sales:lineItems.bonusSavings', { amount: formatAmount(facts.savings) })}</div>
                </div>
              )
            }}
        addControls={(
          <div className="flex flex-col gap-2 md:flex-row md:items-start">
            <div className="min-w-0 flex-1">
              <LineItemEntryBar
                onAddProduct={(product, meta) => {
                  handleAddProduct(product, meta)
                }}
                onCreateFromCode={() => {
                  setShowProductModal(true)
                }}
                onRequiresVariant={() => {
                  // Phase 1 blocks parent-with-variants scans rather than adding
                  // an ambiguous document line. Variant chooser lands in Phase 1B.
                }}
              />
            </div>
            <button
              type="button"
              onClick={handleAddBlankLine}
              className={`inline-flex items-center justify-center gap-2 rounded-lg border ${borderColors.default} ${colors.white} px-4 py-2 text-sm font-medium ${textColors.secondary} ${colors.hover.gray50} transition-colors`}
            >
              <Plus className="h-4 w-4" />
              {t('sales:lineItems.actions.addBlankLine')}
            </button>
          </div>
        )}
      />

      {/* Add Product Modal */}
      <AddQuickProductModal
        isOpen={showProductModal}
        onClose={() => {
          setShowProductModal(false)
        }}
        onSuccess={(product) => {
          // Transform product to match DocumentLineEditor's Product type
          const lineProduct: Product = {
            id: product.id,
            name: product.name,
            sku: product.sku ?? '',
            sale_price: product.sale_price,
            tax_rate: product.tax_rate,
            quantity_decimals: product.quantity_decimals ?? null,
          }
          // Add the new product to the lines
          handleAddProduct(lineProduct)
          void queryClient.invalidateQueries({
            predicate: scopedNamespacePredicate('products', tenantId, companyId),
          })
        }}
      />
    </div>
  )
}
