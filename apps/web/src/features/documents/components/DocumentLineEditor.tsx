import { useState, useCallback, useEffect, useMemo, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Info, Plus, Trash2 } from 'lucide-react'
import { formatCurrency, formatPercent } from '../../../lib/format'
import { bcadd, bccomp, bcdiv, bcmul, bcsub } from '../../../lib/decimal'
import { apiPost } from '../../../lib/api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { AddQuickProductModal } from '../../../components/organisms/AddQuickProductModal/AddQuickProductModal'
import { TaxConfigurationSelect } from '../../../components/atoms/TaxConfigurationSelect/TaxConfigurationSelect'
import { Button } from '../../../components/atoms/Button/Button'
import { Input } from '../../../components/atoms/Input/Input'
import { MoneyInput } from '../../../components/atoms/MoneyInput/MoneyInput'
import { DraftMoneyInput } from '../../../components/atoms/DraftMoneyInput'
import { QuantityInput } from '../../../components/atoms/QuantityInput/QuantityInput'
import { LineItemsTable, QuantityCell, type LineItemsTableColumn } from '../../../components/molecules/line-items/LineItemsTable'
import { LineItemEntryBar, type LineItemEntryAddMeta } from '../../../components/molecules/line-items/LineItemEntryBar'
import { ProductCell } from '../../../components/molecules/line-items/ProductCell'
import type { ProductLineProduct } from '../../../components/molecules/line-items/useProductLineLookup'
import { ServicePicker, type ServicePickerValue } from '../../../components/molecules/pickers/ServicePicker'
import { useCompanyConfig } from '../../../contexts/CompanyConfigContext'
import { DesignationCell } from './DesignationCell'
import { NotesCell } from './NotesCell'
import { useLineDesignationFeature } from '../hooks/useLineDesignationFeature'
import { getQuantityDecimals } from '../../../lib/quantityScale'
import { isPurchaseDocumentType } from '../linePayload'
import type { DocumentType } from '../DocumentListPage'
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

/**
 * Display value for a money input. Identical to {@link decimalValue} except it
 * lets a deliberately EMPTY price stay empty instead of showing a fabricated
 * `0` — the operator must see a blank field and type the real price (W2-6).
 */
function moneyInputValue(value: string | number | null | undefined): string {
  return value === '' ? '' : decimalValue(value)
}


/** Where a freshly added line's unit price came from — surfaced to the operator. */
type UnitPriceDefaultSource = 'product_purchase_price' | 'product_sale_price' | 'none'

interface UnitPriceDefault {
  /** Canonical decimal string, or '' meaning "no default — operator must type". */
  price: string
  source: UnitPriceDefaultSource
}

function isBlankMoney(value: string | number | null | undefined): boolean {
  return value === null || value === undefined || String(value).trim() === ''
}

/**
 * Unit-price default for a newly added line — campaign defect W2-6 (P1).
 *
 * A purchase-order line used to inherit `products.sale_price`: an operator who
 * accepted the default booked the goods receipt at RETAIL, inflating stock
 * valuation and WAC by ~64% on the wave-2 tenant (Dr 37 1 852,000 instead of
 * 1 130,000) and matching the supplier invoice against the wrong money.
 *
 * PURCHASE precedence (in order):
 *   1. Supplier-specific purchase price — NOT AVAILABLE. `price_lists` /
 *      `partner_price_lists` carry no purchase/sale discriminator and are
 *      consumed sale-side only (PricingService::getPrice falls back to
 *      `sale_price` as `base_price`), so they cannot be read as supplier
 *      buying prices without a schema change. There is no supplier-product
 *      price table. When one lands, it plugs in HERE, ahead of step 2.
 *   2. `products.purchase_price` — the canonical buying price. Same field the
 *      server-side PO builder already uses
 *      (DraftPurchaseOrderService.php:142 `$product->purchase_price ?? '0'`)
 *      and the supplier-DN committer falls back to
 *      (SupplierDeliveryNoteCommitter.php:137).
 *   3. EMPTY — the operator types the price. Reached when the product carries
 *      no purchase price, and also when the API redacted it for a caller
 *      without `pricing.view_cost_prices` (ProductData::withoutCostFields).
 *      Empty is correct in both cases: a blank field is honest, a retail price
 *      is not.
 *
 * DELIBERATELY EXCLUDED as purchase sources:
 *   - `products.cost_price` — the perpetual weighted-average COST (a
 *     valuation, not a price): WeightedAverageCostService.php:291.
 *   - `products.last_purchase_cost` — the LANDED unit cost, freight/duty
 *     already allocated in (WeightedAverageCostService.php:292-294). Seeding a
 *     PO line with it would bake landed costs into the price the supplier is
 *     asked to invoice and then fail the three-way match against it.
 *
 * Sale documents are untouched: they still default to `sale_price`.
 */
function resolveLineUnitPriceDefault(
  product: Pick<Product, 'sale_price' | 'purchase_price'>,
  isPurchaseDocument: boolean,
): UnitPriceDefault {
  if (!isPurchaseDocument) {
    return { price: decimalValue(product.sale_price), source: 'product_sale_price' }
  }
  if (!isBlankMoney(product.purchase_price)) {
    return { price: String(product.purchase_price), source: 'product_purchase_price' }
  }
  return { price: '', source: 'none' }
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
  purchase_price?: string | number | null
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
  documentType?: DocumentType
  partnerId?: string | null
  /**
   * Lines whose unit price the parent form refused to submit (blank price).
   * Escalates the blank-price hint from advisory to error and marks the input.
   */
  invalidLineIds?: ReadonlySet<string>
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
    // R-2 / LEDGER D-T9-1: the last sale line may sit on a DRAFT, which carries no
    // document number until it is confirmed. The price hint is what this is for.
    document_no: string | null
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

export function DocumentLineEditor({ lines, onChange, readonly = false, documentType, partnerId = null, invalidLineIds }: DocumentLineEditorProps) {
  const { t } = useTranslation(['sales', 'common'])
  const queryClient = useQueryClient()
  const { config: companyConfig, hasModule } = useCompanyConfig()
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const designationFeatureEnabled = useLineDesignationFeature()
  const [showProductModal, setShowProductModal] = useState(false)
  const [showServicePicker, setShowServicePicker] = useState(false)
  const [focusedPriceLineId, setFocusedPriceLineId] = useState<string | null>(null)
  const [openPricingLineId, setOpenPricingLineId] = useState<string | null>(null)
  // Per-line discount UI mode (percent vs. absolute amount). This is a pure
  // UI concern layered on top of the domain model — DocumentLine only ever
  // carries ONE of discount_percent/discount_amount at a time (mirroring the
  // backend's either/or). An explicit toggle wins once set; otherwise the
  // mode is derived from which field is populated, so a line whose amount
  // arrived from the API/an import defaults to amount mode instead of being
  // silently clobbered by touching the percent cell.
  const [discountModeOverrides, setDiscountModeOverrides] = useState<Record<string, 'percent' | 'amount' | undefined>>({})
  // Where each freshly added line's unit price came from, so the operator can
  // see the provenance of a number they did not type (W2-6). UI-only state on
  // purpose: it must never reach the API line payload. Dropped for a line as
  // soon as the operator edits that line's price.
  const [priceSourceByLineId, setPriceSourceByLineId] = useState<Record<string, UnitPriceDefaultSource | undefined>>({})
  const linesRef = useRef(lines)

  useEffect(() => {
    linesRef.current = lines
  }, [lines])

  // Gate r2 finding 6: a refused submit must take the operator TO the problem.
  // On a long document the inline message can be far off-screen, so focus the
  // first refused price cell (which scrolls it into view) when the parent form
  // flags one.
  const firstRefusedLineId = useMemo(() => {
    if (invalidLineIds === undefined || invalidLineIds.size === 0) return null
    return lines.find((line) => invalidLineIds.has(line.id))?.id ?? null
  }, [invalidLineIds, lines])

  useEffect(() => {
    if (firstRefusedLineId === null) return
    const input = document.getElementById(`line-price-input-${firstRefusedLineId}`)
    if (input instanceof HTMLInputElement) {
      input.focus()
    }
  }, [firstRefusedLineId])

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale.replace('_', '-') ?? 'en-US'
  const taxDocumentType = taxSelectorDocumentType(documentType)
  const isPurchaseDocument = isPurchaseDocumentType(documentType)
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

      const priceDefault = resolveLineUnitPriceDefault(product, isPurchaseDocument)
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
        unit_price: priceDefault.price,
        discount_percent: null,
        discount_amount: null,
        tax_rate: taxRate,
        tax_configuration_id: product.default_tax_configuration_id ?? null,
        line_total: calculateLineTotal(incrementBy, priceDefault.price, taxRate, null, null),
        free_quantity: '0',
        price_entry_mode: 'unit',
        quantity_decimals: product.quantity_decimals ?? null,
      }
      setPriceSourceByLineId((current) => ({ ...current, [newLine.id]: priceDefault.source }))
      onChange([...linesRef.current, newLine])
    },
    [isPurchaseDocument, onChange]
  )

  const handleAddService = useCallback(
    (service: ServicePickerValue) => {
      const unitPrice = decimalValue(service.base_price ?? service.hourly_rate)
      const taxRate = decimalValue(service.tax_rate)
      const newLine: DocumentLine = {
        id: generateId(),
        product_id: '',
        service_id: service.id,
        product_code: service.code,
        product_name: service.name,
        description: service.name,
        designation_default_snapshot: service.name,
        notes: null,
        quantity: '1',
        unit_price: unitPrice,
        discount_percent: null,
        discount_amount: null,
        tax_rate: taxRate,
        tax_configuration_id: null,
        line_total: calculateLineTotal('1', unitPrice, taxRate, null, null),
        free_quantity: '0',
        price_entry_mode: 'unit',
        is_service: true,
      }
      onChange([...linesRef.current, newLine])
      setShowServicePicker(false)
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
      // The provenance hint describes a price the operator did NOT type. Once
      // they touch the price cell it stops being true, so it is dropped.
      if ('unit_price' in updates || 'line_total' in updates || 'price_entry_mode' in updates) {
        setPriceSourceByLineId((current) => {
          if (current[lineId] === undefined) return current
          const { [lineId]: _dropped, ...rest } = current
          return rest
        })
      }
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
              // W2-6 gate r2 C1: never SYNTHESISE a price out of nothing.
              // `deriveUnitPrice` cannot return blank — a blank net amount comes
              // back as '0.000' — so running an unpriced line through it turned the
              // protective EMPTY into a priced-at-zero line on the mode toggle
              // alone, before the operator typed anything: the submit guard stopped
              // seeing a blank and the warning disappeared. Two ways in:
              //   • the operator toggled the mode and there is no entered total yet
              //     (no `line_total` in this update, and the price is still blank);
              //   • the operator CLEARED the total cell (`line_total` arrives blank).
              // Both must stay blank until a real amount is entered.
              const totalNotEnteredYet = !('line_total' in updates) && isBlankMoney(line.unit_price)
              if (totalNotEnteredYet || isBlankMoney(netTotal)) {
                updatedLine.unit_price = ''
                updatedLine.line_total = ''
              } else {
                updatedLine.unit_price = deriveUnitPrice(updatedLine, netTotal)
                updatedLine.line_total = calculateTotalFromNetAmount(decimalValue(netTotal), updatedLine.tax_rate)
              }
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
      setPriceSourceByLineId((current) => {
        if (current[lineId] === undefined) return current
        const { [lineId]: _dropped, ...rest } = current
        return rest
      })
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

  // Resolve the discount UI mode for a line: an explicit toggle wins, else
  // derive from which field currently carries a value (amount-only -> amount
  // mode; anything else, including both-null and percent-set, -> percent).
  const getDiscountMode = useCallback((line: DocumentLine): 'percent' | 'amount' => {
    const override = discountModeOverrides[line.id]
    if (override !== undefined) return override

    const hasAmount = line.discount_amount !== null && line.discount_amount !== undefined && line.discount_amount !== ''
    const hasPercent = line.discount_percent !== null && line.discount_percent !== undefined && line.discount_percent !== ''

    return hasAmount && !hasPercent ? 'amount' : 'percent'
  }, [discountModeOverrides])

  const lineColumns = useMemo<LineItemsTableColumn<DocumentLine>[]>(() => [
    {
      id: 'article',
      header: t('sales:lineItems.article'),
      headerClassName: 'min-w-72',
      Cell: ({ line }) => (
        <div className="min-w-72 space-y-1">
          <ProductCell
            size="sm"
            product={{
              name: line.product_name || line.description || '-',
              sku: line.product_code ?? null,
              barcode: line.product_barcode ?? null,
              primary_image_url: line.primary_image_url ?? null,
            }}
          />
          <div className="max-w-xl">
            {designationFeatureEnabled ? (
              <DesignationCell
                value={line.description || line.product_name}
                originalSnapshot={line.designation_default_snapshot ?? null}
                readOnly={readonly}
                className="min-w-0"
                valueClassName={`line-clamp-2 text-xs ${textColors.tertiary}`}
                onCommit={(next) => {
                  handleUpdateLine(line.id, { description: next })
                }}
              />
            ) : (
              <span className={`block line-clamp-2 text-xs ${textColors.tertiary}`}>
                {line.description || line.product_name}
              </span>
            )}
            {designationFeatureEnabled && (
              <NotesCell
                value={line.notes ?? null}
                readOnly={readonly}
                className="mt-0.5"
                valueClassName={`line-clamp-2 text-xs ${textColors.tertiary}`}
                onCommit={(next) => {
                  handleUpdateLine(line.id, { notes: next })
                }}
              />
            )}
          </div>
          {line.is_service && (
            <span className={`mt-1 inline-flex rounded-full ${colors.neutral[100]} px-1.5 py-0.5 text-[10px] font-medium ${textColors.secondary}`}>
              {t('sales:lineItems.serviceBadge')}
            </span>
          )}
        </div>
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
        // W2-6 + gate r1 finding 3. Two distinct hints, both derived from the
        // line's CURRENT state so neither can be stranded by an edit:
        //   • blank price  → always warn, on every document type. This is the
        //     affordance that backs the submit block, so it must survive the
        //     operator typing a digit and deleting it again (the earlier
        //     version read add-time provenance, which `handleUpdateLine` drops
        //     on any price edit — the warning vanished in exactly the state it
        //     exists to flag).
        //   • priced from purchase_price → informational provenance, dropped
        //     as soon as the operator overtypes it (it stops being true).
        const priceIsBlank = isBlankMoney(line.unit_price)
        const priceRefused = invalidLineIds?.has(line.id) === true
        const priceHintId = `line-price-hint-${line.id}`
        const priceInputId = `line-price-input-${line.id}`
        const priceHintMessage = priceIsBlank
          ? (isPurchaseDocument && priceSourceByLineId[line.id] === 'none'
              ? t('sales:lineItems.priceSource.none')
              : t('sales:lineItems.priceSource.required'))
          : (isPurchaseDocument && priceSourceByLineId[line.id] === 'product_purchase_price'
              ? t('sales:lineItems.priceSource.productPurchasePrice')
              : null)

        if (readonly) {
          return <span className={`text-sm ${textColors.primary}`}>{formatAmount(deriveUnitPrice(line))}</span>
        }

        return (
          <div className="relative flex min-w-40 flex-col items-end gap-1">
            <div className="flex items-center justify-end gap-2">
              {(line.price_entry_mode ?? 'unit') === 'total' ? (
                <DraftMoneyInput
                  id={priceInputId}
                  currency={companyCurrency}
                  min="0"
                  error={isBlocked || priceRefused}
                  {...(priceHintMessage !== null ? { 'aria-describedby': priceHintId } : {})}
                  // A blank line has no total to show either — rendering
                  // `calculateNetExtendedAmount` here would print 0.000 for a price
                  // nobody entered (gate r2 C1).
                  initialValue={priceIsBlank ? '' : calculateNetExtendedAmount(line)}
                  onFocus={() => {
                    setFocusedPriceLineId(line.id)
                  }}
                  onCommit={(value) => {
                    handleUpdateLine(line.id, { line_total: value })
                  }}
                  aria-label={t('sales:lineItems.unitPrice')}
                  className={`${tokens.input.base} w-28 text-end text-sm`}
                />
              ) : (
                <MoneyInput
                  id={priceInputId}
                  currency={companyCurrency}
                  min="0"
                  error={isBlocked || priceRefused}
                  {...(priceHintMessage !== null ? { 'aria-describedby': priceHintId } : {})}
                  value={moneyInputValue(line.unit_price)}
                  onFocus={() => {
                    setFocusedPriceLineId(line.id)
                  }}
                  onChange={(value) => {
                    handleUpdateLine(line.id, { unit_price: value })
                  }}
                  aria-label={t('sales:lineItems.unitPrice')}
                  className={`${tokens.input.base} w-28 text-end text-sm`}
                />
              )}
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

            {priceHintMessage !== null && (
              <div
                id={priceHintId}
                // Deliberately NOT role="alert": the form-level refusal message is
                // the single announcement, and this node is already reachable from
                // the input through aria-describedby. N+1 alerts on a long document
                // is noise, not accessibility (gate r2 finding 6).
                className={`max-w-72 text-end text-[11px] leading-4 ${priceRefused ? textColors.error : priceIsBlank ? textColors.warning : textColors.secondary}`}
              >
                {priceHintMessage}
              </div>
            )}

            {pricingItem !== undefined && (
              <div className={`max-w-72 text-end text-[11px] leading-4 ${isBlocked ? textColors.error : isWarning ? textColors.warning : textColors.secondary}`}>
                <div className="flex flex-wrap items-center justify-end gap-x-1">
                  <span>
                    {t('sales:lineItems.pricing.cost', { amount: pricingItem.cost_wac })}
                    {' · '}
                    {t('sales:lineItems.pricing.lastBuy', { amount: pricingItem.last_purchase_cost ?? '-' })}
                    {' · '}
                    {t('sales:lineItems.pricing.margin', { percent: formatPercent(pricingItem.target_margin_pct).replace(/%$/, '') })}
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
                    {t('sales:lineItems.pricing.minimumMargin', { percent: formatPercent(pricingItem.minimum_margin_pct).replace(/%$/, '') })}
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
      headerClassName: readonly ? 'w-28 text-end' : 'w-40 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => {
        const mode = getDiscountMode(line)

        if (readonly) {
          return (
            <span className={`text-sm ${textColors.primary}`}>
              {mode === 'amount' && line.discount_amount !== null && line.discount_amount !== undefined
                ? formatAmount(line.discount_amount)
                : formatPercent(line.discount_percent ?? '0')}
            </span>
          )
        }

        // Line gross (qty x unit_price), string math only — bounds the
        // amount input the same way `max="100"` bounds the percent input, so
        // an over-gross value gets immediate field-level feedback instead of
        // only the generic 422 toast on submit (FE gate IMPORTANT I-3).
        const lineGross = bcmul(decimalValue(line.quantity), decimalValue(line.unit_price))

        return (
          <div className="flex flex-col items-end gap-1">
            <div className="flex items-center justify-end gap-1">
              {mode === 'percent' ? (
                <Input
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
                  className="w-16 text-end text-sm"
                />
              ) : (
                <MoneyInput
                  currency={companyCurrency}
                  min="0"
                  max={lineGross}
                  // I-1: an unset amount renders EMPTY, matching the percent
                  // branch's `?? ''` — decimalValue() would render a literal
                  // "0", which reads as a deliberate zero discount rather
                  // than "nothing entered yet".
                  value={line.discount_amount ?? ''}
                  onChange={(value) => {
                    handleUpdateLine(line.id, {
                      // I-2: clearing the field emits null, symmetric with
                      // the percent branch above — never a bare '', which
                      // would introduce a third value into a `string | null`
                      // field and desync from every downstream null check.
                      discount_amount: value === '' ? null : value,
                      discount_percent: null,
                    })
                  }}
                  aria-label={t('sales:lineItems.discountAmountPerLine')}
                  className={`${tokens.input.base} w-24 text-end text-sm`}
                />
              )}
              <Button
                type="button"
                variant="secondary"
                size="xs"
                onClick={() => {
                  const nextMode = mode === 'percent' ? 'amount' : 'percent'
                  setDiscountModeOverrides((prev) => ({ ...prev, [line.id]: nextMode }))
                  // C-1: the toggle must WRITE, not just switch which input
                  // renders. Null the field being abandoned in the SAME
                  // click, so what the operator sees immediately after
                  // toggling is always what gets saved — a toggle that only
                  // changed the view could leave the OLD field's value
                  // active (percent wins over amount on the backend),
                  // silently applying a discount nobody can see anymore.
                  handleUpdateLine(
                    line.id,
                    nextMode === 'amount' ? { discount_percent: null } : { discount_amount: null },
                  )
                }}
                aria-pressed={mode === 'amount'}
              >
                {mode === 'percent' ? t('sales:lineItems.discountMode.amount') : t('sales:lineItems.discountMode.percent')}
              </Button>
            </div>
            {mode === 'amount' && (
              <span className={`text-[11px] leading-4 ${textColors.tertiary}`}>
                {t('sales:lineItems.discountMaxHint', { amount: formatAmount(lineGross) })}
              </span>
            )}
          </div>
        )
      },
    },
    {
      id: 'tax',
      header: t('sales:lineItems.taxPercent'),
      headerClassName: 'w-20 text-end',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        readonly ? (
          <span className={`text-sm ${textColors.disabled}`}>{formatPercent(line.tax_rate)}</span>
        ) : (
          <TaxConfigurationSelect
            value={line.tax_configuration_id ?? null}
            onChange={(configId, taxRate) => {
              handleUpdateLine(line.id, {
                tax_configuration_id: configId,
                tax_rate: taxRate.trim() === '' ? '0' : taxRate,
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
    invalidLineIds,
    isPurchaseDocument,
    priceSourceByLineId,
    formatAmount,
    getDiscountMode,
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

  const renderLineDetail = useCallback((line: DocumentLine) => {
    const facts = purchaseBonusEnabled ? bonusFacts(line) : null
    const showFullDescription =
      line.description.trim().length > 80 &&
      line.description.trim() !== (line.product_name || '').trim()
    const showFullNotes = (line.notes ?? '').trim().length > 80

    if (facts === null && !showFullDescription && !showFullNotes) return null

    return (
      <div className={`border-t ${borderColors.light} ${colors.neutral[50]} px-6 py-2 text-xs ${textColors.secondary}`}>
        {showFullDescription ? <div>{line.description}</div> : null}
        {showFullNotes ? <div>{line.notes}</div> : null}
        {facts !== null ? (
          <>
            <div>{t('sales:lineItems.effectiveUnitCost', { amount: formatAmount(facts.effectiveUnitCost) })}</div>
            <div>{t('sales:lineItems.bonusSavings', { amount: formatAmount(facts.savings) })}</div>
          </>
        ) : null}
      </div>
    )
  }, [bonusFacts, formatAmount, purchaseBonusEnabled, t])

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
        renderLineDetail={renderLineDetail}
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
            {hasModule('Workshop') ? (
              <div className="w-full md:w-72">
                {showServicePicker ? (
                  <ServicePicker
                    value={null}
                    label=""
                    placeholder={t('sales:lineItems.searchServicesPlaceholder')}
                    onChange={(service) => {
                      if (service !== null) {
                        handleAddService(service)
                      }
                    }}
                  />
                ) : (
                  <button
                    type="button"
                    onClick={() => {
                      setShowServicePicker(true)
                    }}
                    className={`inline-flex w-full items-center justify-center gap-2 rounded-lg border ${borderColors.default} ${colors.white} px-4 py-2 text-sm font-medium ${textColors.secondary} ${colors.hover.gray50} transition-colors`}
                  >
                    <Plus className="h-4 w-4" />
                    {t('sales:lineItems.tabs.service')}
                  </button>
                )}
              </div>
            ) : null}
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
