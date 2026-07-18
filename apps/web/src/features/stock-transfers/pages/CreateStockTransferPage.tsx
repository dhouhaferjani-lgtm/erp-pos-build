import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate, Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, PackageSearch, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchLocations, type LocationApiResponse } from '@/features/locations/api'
import { Button } from '@/components/atoms/Button/Button'
import { MoneyInput } from '@/components/atoms/MoneyInput/MoneyInput'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { ProductPicker, type ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import {
  LineItemEntryBar,
  LineItemsTable,
  QuantityCell,
  type LineItemEntryAddMeta,
  type LineItemsTableColumn,
  type ProductLineProduct,
} from '@/components/molecules/line-items'
import { textColors, borderColors, tokens, colors } from '@/lib/designTokens'
import { useCurrency } from '@/hooks/useCurrency'
import { bcadd, bccomp, bcsub } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { useProductBatches } from '@/features/batches/hooks/useBatches'
import type { Batch } from '@/features/batches/types'
import { useProductVariants } from '@/features/catalog/hooks/useProductVariants'
import type { ProductVariant } from '@/features/catalog/api/variantApi'
import { useCreateStockTransfer } from '../api/queries'
import type { CreateStockTransferInput, TransferCostDistribution } from '../types'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { Input, Select, Textarea } from '@/components/atoms'
import { TransferSourceSuggestion } from '../components/TransferSourceSuggestion'

interface DraftBatchAllocation {
  batch_id: number
  quantity: string
}

interface DraftLine {
  uid: string
  product: ProductPickerValue | null
  variantId: string | null
  quantity: string
  batchAllocations: DraftBatchAllocation[]
}

/** A parent product scanned by code that still needs a variant chosen. */
interface PendingVariantChoice {
  product: ProductLineProduct
  code: string
}

/** Active variants of the line's product, cached for submit-time validation. */
type VariantsByProduct = Map<string, ProductVariant[]>

function activeVariants(variants: ProductVariant[] | undefined): ProductVariant[] {
  return Array.isArray(variants) ? variants.filter((variant) => variant.is_active) : []
}

const DISTRIBUTION_OPTIONS: readonly TransferCostDistribution[] = [
  'pro_rata_value',
  'pro_rata_quantity',
  'equal_per_line',
]

function isDistribution(value: string): value is TransferCostDistribution {
  return (DISTRIBUTION_OPTIONS as readonly string[]).includes(value)
}

function generateUid(): string {
  return `line-${String(Date.now())}-${Math.random().toString(36).slice(2, 8)}`
}

function quantityAtSource(batch: Batch, sourceLocationId: string): string {
  const stock = batch.batch_stock?.find((entry) => String(entry.location_id) === sourceLocationId)
  return stock?.available_quantity !== undefined ? String(stock.available_quantity) : '0.0000'
}

function isBatchTransferableAtSource(batch: Batch, sourceLocationId: string): boolean {
  return batch.can_be_sold && bccomp(quantityAtSource(batch, sourceLocationId), '0') > 0
}

function buildFefoAllocations(
  batches: Batch[],
  sourceLocationId: string,
  requestedQuantity: string,
): DraftBatchAllocation[] {
  let remaining = requestedQuantity.trim() === '' ? '0.0000' : bcsub(requestedQuantity, '0', 4)
  const allocations: DraftBatchAllocation[] = []

  const eligible = batches
    .filter((batch) => isBatchTransferableAtSource(batch, sourceLocationId))
    .sort((a, b) => a.expiry_date.localeCompare(b.expiry_date))

  for (const batch of eligible) {
    if (bccomp(remaining, '0') <= 0) {
      break
    }

    const available = quantityAtSource(batch, sourceLocationId)
    const quantity = bccomp(available, remaining) < 0 ? bcsub(available, '0', 4) : remaining
    allocations.push({
      batch_id: batch.id,
      quantity,
    })
    remaining = bcsub(remaining, quantity, 4)
  }

  return allocations
}

function sumAllocations(allocations: DraftBatchAllocation[]): string {
  return allocations.reduce((total, allocation) => bcadd(total, allocation.quantity, 4), '0.0000')
}

/**
 * Whether the FEFO/manual allocation for a batch line fully covers the line
 * quantity. A batch line that is not covered is blocked from submit and shows
 * the "needs allocation" affordance.
 */
function isBatchAllocationCovered(allocations: DraftBatchAllocation[], requestedQuantity: string): boolean {
  const requested = requestedQuantity.trim() === '' ? '0' : requestedQuantity
  return bccomp(sumAllocations(allocations), requested) >= 0
}

function sortBatchesByExpiry(batches: Batch[]): Batch[] {
  return Array.from(batches).sort((a, b) => a.expiry_date.localeCompare(b.expiry_date))
}

interface RemoveLineButtonProps {
  disabled: boolean
  label: string
  onRemove: () => void
}

function RemoveLineButton({ disabled, label, onRemove }: RemoveLineButtonProps) {
  return (
    <button
      type="button"
      onClick={onRemove}
      disabled={disabled}
      aria-label={label}
      className={`rounded p-2 ${textColors.error} ${textColors.hoverError} ${colors.hover.red50} disabled:opacity-30`}
    >
      <Trash2 className="h-4 w-4" />
    </button>
  )
}

interface VariantChooserProps {
  product: ProductLineProduct
  onChoose: (variantId: string) => void
}

/**
 * Inline chooser shown when a parent-with-variants product is scanned. The
 * component fetches the product's variants (mirroring the row-level variant
 * select) so the operator can disambiguate the scan into a concrete line.
 */
function VariantChooser({ product, onChoose }: VariantChooserProps) {
  const { t } = useTranslation('stock-transfers')
  const variantsQuery = useProductVariants(product.id)
  const variants = useMemo(() => activeVariants(variantsQuery.data), [variantsQuery.data])

  return (
    <div
      role="dialog"
      aria-label={t('create.entry.chooseVariantFor', { product: product.name })}
      className={`mt-2 rounded-md border ${borderColors.light} bg-white p-3 shadow-sm`}
    >
      <div className={`mb-2 text-sm font-medium ${textColors.primary}`}>
        {t('create.entry.chooseVariantFor', { product: product.name })}
      </div>
      <div className="flex flex-wrap gap-2">
        {variants.map((variant) => (
          <Button
            key={variant.id}
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => {
              onChoose(variant.id)
            }}
          >
            {[variant.name_suffix, variant.sku].filter(Boolean).join(' ')}
          </Button>
        ))}
      </div>
    </div>
  )
}

interface BatchAllocationPanelProps {
  line: DraftLine & { product: ProductPickerValue }
  sourceLocationId: string
  batches: Batch[]
  onChange: (allocations: DraftBatchAllocation[]) => void
}

function BatchAllocationPanel({ line, sourceLocationId, batches, onChange }: BatchAllocationPanelProps) {
  const { t } = useTranslation('stock-transfers')

  const updateAllocation = (batchId: number, quantity: string): void => {
    const next = line.batchAllocations.filter((allocation) => allocation.batch_id !== batchId)
    if (quantity.trim() !== '' && bccomp(quantity, '0') > 0) {
      next.push({ batch_id: batchId, quantity })
    }
    next.sort((a, b) => {
      const batchA = batches.find((batch) => batch.id === a.batch_id)
      const batchB = batches.find((batch) => batch.id === b.batch_id)
      return (batchA?.expiry_date ?? '').localeCompare(batchB?.expiry_date ?? '')
    })
    onChange(next)
  }

  return (
    <div className={`border-t ${borderColors.light} ${colors.neutral[50]} px-3 py-3`}>
      <div className="mb-2 flex items-center justify-between">
        <div className={`text-sm font-medium ${textColors.primary}`}>{t('create.batch.title')}</div>
        <div className={`text-xs ${textColors.tertiary}`}>{t('create.batch.fefoHint')}</div>
      </div>
      <div className="space-y-2">
        {sortBatchesByExpiry(batches)
          .map((batch) => {
            const available = quantityAtSource(batch, sourceLocationId)
            const allocation = line.batchAllocations.find((item) => item.batch_id === batch.id)
            const disabled = !isBatchTransferableAtSource(batch, sourceLocationId)

            return (
              <div key={batch.id} className="grid grid-cols-1 gap-2 md:grid-cols-12 md:items-center md:gap-3">
                <div className="md:col-span-4">
                  <div className={`text-sm font-medium ${textColors.primary}`}>{batch.batch_number}</div>
                  <div className={`text-xs ${textColors.tertiary}`}>{batch.expiry_date}</div>
                </div>
                <div className={`text-xs ${textColors.tertiary} md:col-span-2`}>
                  <span className="md:hidden">{t('create.batch.statusLabel')}: </span>
                  <span>{batch.expiry_status}</span>
                </div>
                <div className={`text-sm ${textColors.secondary} md:col-span-2`}>
                  <span className={`md:hidden ${textColors.tertiary}`}>{t('create.batch.availableLabel')}: </span>
                  <span>{available}</span>
                </div>
                <div className="md:col-span-4">
                  <QuantityInput
                    value={allocation?.quantity ?? ''}
                    onChange={(value) => {
                      updateAllocation(batch.id, value)
                    }}
                    decimalPlaces={getQuantityDecimals(line.product)}
                    min="0"
                    disabled={disabled}
                    aria-label={t('create.batch.quantityFor', { batch: batch.batch_number })}
                    className={tokens.input.base}
                  />
                </div>
              </div>
            )
          })}
      </div>
    </div>
  )
}

interface BatchToggleCellProps {
  line: DraftLine
  sourceLocationId: string
  expanded: boolean
  onToggle: () => void
  onAllocationsChange: (allocations: DraftBatchAllocation[]) => void
}

/** Gated batch column cell: only batch-tracked products show the detail action. */
function BatchToggleCell({ line, sourceLocationId, expanded, onToggle, onAllocationsChange }: BatchToggleCellProps) {
  const { t } = useTranslation('stock-transfers')
  const product = line.product
  const batchesQuery = useProductBatches(product?.id ?? '', line.variantId)
  const batches = useMemo(() => batchesQuery.data ?? [], [batchesQuery.data])

  // Single source of truth for batch allocations. Whenever a driver of the
  // FEFO split changes — product, variant, source location, or line quantity —
  // re-derive the allocation so the panel always DISPLAYS exactly what will be
  // SUBMITTED. This runs even while the panel is collapsed, so a batch line is
  // never silently unallocated and the submit guard only trips when FEFO
  // genuinely has nothing to offer. Manual edits inside the panel keep the same
  // signature and are therefore preserved until one of the drivers changes.
  const productId = product?.id ?? ''
  const requiresBatch = product?.requires_batch_tracking ?? false
  const fefoSignatureRef = useRef<string | null>(null)
  useEffect(() => {
    if (!requiresBatch || sourceLocationId === '' || batches.length === 0) {
      return
    }
    const signature = `${productId}|${line.variantId ?? ''}|${sourceLocationId}|${line.quantity}`
    if (fefoSignatureRef.current === signature) {
      return
    }
    fefoSignatureRef.current = signature
    onAllocationsChange(buildFefoAllocations(batches, sourceLocationId, line.quantity))
  }, [requiresBatch, productId, line.variantId, line.quantity, sourceLocationId, batches, onAllocationsChange])

  if (!product?.requires_batch_tracking) {
    return <span className={`text-sm ${textColors.tertiary}`}>—</span>
  }

  const isLoading = batchesQuery.isLoading || batchesQuery.isFetching

  // Derived UI affordance on top of the single reconciliation path above: once
  // batches are loaded and FEFO still cannot cover the requested quantity, the
  // line is blocked and the operator is prompted to allocate lots manually.
  const covered = isBatchAllocationCovered(line.batchAllocations, line.quantity)
  const needsAllocation = requiresBatch && sourceLocationId !== '' && !isLoading && !covered

  // FEFO re-allocation is owned by the reconciliation effect above; the toggle
  // only opens/closes the panel.
  const handleToggle = (): void => {
    onToggle()
  }

  return (
    <div className="space-y-1">
      <Button
        type="button"
        variant="secondary"
        size="sm"
        onClick={handleToggle}
        disabled={sourceLocationId === '' || isLoading}
        aria-expanded={expanded}
      >
        <PackageSearch className="me-1 h-4 w-4" />
        {needsAllocation
          ? t('create.batch.needsAllocation')
          : line.batchAllocations.length > 0
            ? t('create.batch.allocated', { count: line.batchAllocations.length })
            : t('create.batch.action')}
      </Button>
      {needsAllocation ? (
        <div className={`text-xs ${textColors.error}`}>{t('create.batch.cannotAllocate')}</div>
      ) : null}
    </div>
  )
}

interface BatchDetailRowProps {
  line: DraftLine & { product: ProductPickerValue }
  sourceLocationId: string
  onChange: (allocations: DraftBatchAllocation[]) => void
}

/** Full-width expandable batch panel rendered beneath a batch-tracked line. */
function BatchDetailRow({ line, sourceLocationId, onChange }: BatchDetailRowProps) {
  const batchesQuery = useProductBatches(line.product.id, line.variantId)
  const batches = useMemo(() => batchesQuery.data ?? [], [batchesQuery.data])
  return (
    <BatchAllocationPanel
      line={line}
      sourceLocationId={sourceLocationId}
      batches={batches}
      onChange={onChange}
    />
  )
}

interface ProductStockLevelLocation {
  location_id: string
  available: string
}

interface ProductStockLevelsResponse {
  locations: ProductStockLevelLocation[]
}

interface AvailabilityCellProps {
  line: DraftLine
  sourceLocationId: string
}

/** Shows on-hand availability of the line's product at the chosen source. */
function AvailabilityCell({ line, sourceLocationId }: AvailabilityCellProps) {
  const { t } = useTranslation('stock-transfers')
  const product = line.product
  const productId = product?.id ?? ''
  const variantId = line.variantId
  const stockQuery = useQuery({
    queryKey: tenantScopedKey(['stock-levels', productId, variantId]),
    queryFn: () => apiGet<ProductStockLevelsResponse>(
      `/products/${productId}/stock-levels`,
      variantId !== null ? { variant_id: variantId } : undefined,
    ),
    enabled: productId !== '' && sourceLocationId !== '',
  })

  if (product === null) {
    return <span className={`text-sm ${textColors.disabled}`}>—</span>
  }
  if (sourceLocationId === '') {
    return <span className={`text-xs ${textColors.tertiary}`}>{t('create.availability.selectSource')}</span>
  }
  if (stockQuery.isLoading) {
    return <span className={`text-xs ${textColors.tertiary}`}>{t('create.availability.loading')}</span>
  }
  if (stockQuery.isError) {
    return <span className={`text-xs ${textColors.error}`}>{t('create.availability.error')}</span>
  }

  const stockLocations = Array.isArray(stockQuery.data?.locations) ? stockQuery.data.locations : []
  const available = stockLocations.find((loc) => loc.location_id === sourceLocationId)?.available ?? '0'
  const exceeds = line.quantity.trim() !== '' && bccomp(line.quantity, available) > 0

  return (
    <div className="text-sm">
      <span className={exceeds ? textColors.error : textColors.secondary}>{available}</span>
      {exceeds ? (
        <span className={`block text-xs ${textColors.error}`}>{t('create.availability.exceeds')}</span>
      ) : null}
    </div>
  )
}

interface VariantSelectCellProps {
  line: DraftLine
  onSelect: (variantId: string | null) => void
  onVariantsLoaded: (productId: string, variants: ProductVariant[]) => void
}

/**
 * Renders a variant <Select> when the line's product has active variants.
 * Reports the loaded variants up to the page so submit-time validation can
 * require a choice. Products without variants render a muted dash.
 */
function VariantSelectCell({ line, onSelect, onVariantsLoaded }: VariantSelectCellProps) {
  const { t } = useTranslation('stock-transfers')
  const product = line.product
  const productId = product?.id ?? ''
  const variantsQuery = useProductVariants(productId)
  const variants = useMemo(() => activeVariants(variantsQuery.data), [variantsQuery.data])
  const isLoading = variantsQuery.isLoading

  // Surface the loaded set to the page (keyed by product) for the required-check.
  useEffect(() => {
    if (productId !== '' && !isLoading) {
      onVariantsLoaded(productId, variants)
    }
  }, [productId, isLoading, variants, onVariantsLoaded])

  if (product === null) {
    return <span className={`text-sm ${textColors.disabled}`}>—</span>
  }
  if (variantsQuery.isLoading) {
    return <span className={`text-xs ${textColors.tertiary}`}>{t('create.availability.loading')}</span>
  }
  if (variants.length === 0) {
    return <span className={`text-sm ${textColors.tertiary}`}>—</span>
  }

  return (
    <Select
      value={line.variantId ?? ''}
      onChange={(e) => {
        onSelect(e.target.value === '' ? null : e.target.value)
      }}
      aria-label={t('create.field.variant')}
    >
      <option value="">{t('create.field.selectVariant')}</option>
      {variants.map((variant) => (
        <option key={variant.id} value={variant.id}>
          {variant.name_suffix} ({variant.sku})
        </option>
      ))}
    </Select>
  )
}

export function CreateStockTransferPage() {
  const [searchParams] = useSearchParams()
  const { t } = useTranslation('stock-transfers')
  const navigate = useNavigate()
  const { handleSubmit: handleFormSubmit } = useForm()
  const { currency } = useCurrency()

  const locationsQuery = useQuery({
    queryKey: tenantScopedKey(['locations', 'all']),
    queryFn: () => fetchLocations(),
  })
  const locations: LocationApiResponse[] = locationsQuery.data ?? []

  const [sourceLocationId, setSourceLocationId] = useState(() => searchParams.get('source_location_id') ?? '')
  const [destinationLocationId, setDestinationLocationId] = useState('')
  const [notes, setNotes] = useState('')
  const [transferCost, setTransferCost] = useState('0')
  const [transferCostLabel, setTransferCostLabel] = useState('')
  const [distribution, setDistribution] = useState<TransferCostDistribution>('pro_rata_value')
  const [lines, setLines] = useState<DraftLine[]>([
    { uid: generateUid(), product: null, variantId: null, quantity: '1', batchAllocations: [] },
  ])
  const [expandedBatchLineUid, setExpandedBatchLineUid] = useState<string | null>(null)
  const [pendingVariantChoice, setPendingVariantChoice] = useState<PendingVariantChoice | null>(null)
  // Active variants per product, populated by VariantSelectCell as products
  // are picked. Read at submit to require a variant on variant-bearing products
  // (the backend enforces the same rule and returns 422 if bypassed).
  const variantsByProduct = useRef<VariantsByProduct>(new Map())

  const createMutation = useCreateStockTransfer()

  // Stable handler identities so the memoized line columns below don't change
  // every render — otherwise each cell remounts and the inputs lose focus.
  const addLine = useCallback(() => {
    setLines((prev) => [...prev, { uid: generateUid(), product: null, variantId: null, quantity: '1', batchAllocations: [] }])
  }, [])

  const removeLine = useCallback((uid: string) => {
    setLines((prev) => (prev.length <= 1 ? prev : prev.filter((l) => l.uid !== uid)))
  }, [])

  const updateLine = useCallback((uid: string, patch: Partial<DraftLine>) => {
    setLines((prev) => prev.map((l) => (l.uid === uid ? { ...l, ...patch } : l)))
  }, [])

  const toggleBatchLine = useCallback((uid: string) => {
    setExpandedBatchLineUid((current) => (current === uid ? null : uid))
  }, [])

  const handleVariantsLoaded = useCallback((productId: string, variants: ProductVariant[]) => {
    variantsByProduct.current.set(productId, variants)
  }, [])

  // Source-location-first guard for the entry bar: nothing can be added until a
  // source is chosen (the availability/FEFO reads are all source-scoped).
  const ensureSourceForEntry = useCallback((): boolean => {
    if (sourceLocationId === '') {
      toast.error(t('create.validation.sourceRequiredForEntry'))
      return false
    }
    return true
  }, [sourceLocationId, t])

  // Entry-bar add: increment an existing product+variant line or replace the
  // first blank line / append a new one. Batch allocation is intentionally NOT
  // touched here — the reconciliation effect in BatchToggleCell owns the FEFO
  // split and re-derives it whenever the quantity changes.
  const upsertEntryLine = useCallback((product: ProductLineProduct, meta: LineItemEntryAddMeta): void => {
    const pickerProduct: ProductPickerValue = {
      id: product.id,
      sku: product.sku ?? '',
      name: product.name,
      quantity_decimals: product.quantity_decimals ?? null,
      requires_batch_tracking: product.requires_batch_tracking ?? false,
    }
    const variantId = meta.variantId ?? null
    const increment = String(meta.incrementBy > 0 ? meta.incrementBy : 1)

    setLines((prev) => {
      const existing = prev.find(
        (line) => line.product?.id === pickerProduct.id && line.variantId === variantId,
      )

      if (existing !== undefined) {
        if (pickerProduct.requires_batch_tracking) {
          setExpandedBatchLineUid(existing.uid)
        }
        const nextQuantity = bcadd(existing.quantity, increment, getQuantityDecimals(existing.product))
        return prev.map((line) =>
          line.uid === existing.uid ? { ...line, quantity: nextQuantity } : line,
        )
      }

      const nextLine: DraftLine = {
        uid: generateUid(),
        product: pickerProduct,
        variantId,
        quantity: increment,
        batchAllocations: [],
      }
      if (pickerProduct.requires_batch_tracking) {
        setExpandedBatchLineUid(nextLine.uid)
      }

      const blankIndex = prev.findIndex((line) => line.product === null)
      if (blankIndex === -1) {
        return [...prev, nextLine]
      }
      return prev.map((line, index) => (index === blankIndex ? nextLine : line))
    })
  }, [])

  const handleRequiresVariant = useCallback((product: ProductLineProduct, code: string): void => {
    setPendingVariantChoice({ product, code })
  }, [])

  const choosePendingVariant = useCallback((variantId: string): void => {
    if (pendingVariantChoice === null) {
      return
    }
    upsertEntryLine(pendingVariantChoice.product, {
      source: 'scan',
      incrementBy: 1,
      variantId,
      code: pendingVariantChoice.code,
    })
    setPendingVariantChoice(null)
  }, [pendingVariantChoice, upsertEntryLine])

  const handleEntryNotFound = useCallback((code: string): void => {
    toast.error(t('create.entry.notFound', { code }))
  }, [t])

  const submitTransfer = async (): Promise<void> => {
    if (!sourceLocationId || !destinationLocationId) {
      toast.error(t('create.field.selectLocation'))
      return
    }
    if (sourceLocationId === destinationLocationId) {
      toast.error(t('create.validation.differentLocations'))
      return
    }

    const cleanLines = lines.filter(
      (l): l is DraftLine & { product: ProductPickerValue } =>
        l.product !== null && l.quantity.trim() !== '',
    )
    if (cleanLines.length === 0) {
      toast.error(t('create.validation.linesRequired'))
      return
    }
    for (const line of cleanLines) {
      const qty = line.quantity.trim()
      const isValidDecimal = /^\d*\.?\d+$/.test(qty)
      if (!isValidDecimal || bccomp(qty, '0') <= 0) {
        toast.error(t('create.validation.quantityPositive'))
        return
      }
      // A batch-tracked line must be FULLY covered by its allocation. The
      // reconciliation effect keeps this in sync with FEFO; when FEFO cannot
      // cover the quantity the line is blocked and the operator must allocate.
      if (line.product.requires_batch_tracking && !isBatchAllocationCovered(line.batchAllocations, line.quantity)) {
        toast.error(t('create.batch.cannotAllocateSubmit'))
        return
      }
      // A product with active variants must have a variant chosen. The backend
      // enforces the same rule (422 INVALID_TRANSFER) if this is bypassed.
      const productVariants = variantsByProduct.current.get(line.product.id) ?? []
      if (productVariants.length > 0 && line.variantId === null) {
        toast.error(t('create.validation.variantRequired'))
        return
      }
    }

    const payload: CreateStockTransferInput = {
      source_location_id: sourceLocationId,
      destination_location_id: destinationLocationId,
      notes: notes.trim() === '' ? null : notes.trim(),
      transfer_cost: transferCost.trim() === '' ? '0' : transferCost.trim(),
      transfer_cost_label: transferCostLabel.trim() === '' ? null : transferCostLabel.trim(),
      transfer_cost_distribution: distribution,
      lines: cleanLines.map((l) => ({
        product_id: l.product.id,
        ...(l.variantId !== null ? { variant_id: l.variantId } : {}),
        quantity: l.quantity,
        ...(l.batchAllocations.length > 0
          ? {
              batch_allocations: l.batchAllocations.map((allocation) => ({
                batch_id: allocation.batch_id,
                quantity: allocation.quantity,
              })),
            }
          : {}),
      })),
    }

    try {
      const result: { id: string } = await createMutation.mutateAsync(payload)
      toast.success(t('create.success'))
      void navigate(`/inventory/stock-transfers/${result.id}`)
    } catch {
      toast.error(t('create.error'))
    }
  }

  const submitStockTransfer = () => {
    void submitTransfer()
  }

  const handleDistributionChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const raw = e.target.value
    if (isDistribution(raw)) {
      setDistribution(raw)
    }
  }

  const lineColumns = useMemo<LineItemsTableColumn<DraftLine>[]>(() => [
    {
      id: 'product',
      header: t('create.field.product'),
      Cell: ({ line }) => (
        <ProductPicker
          value={line.product}
          onChange={(product) => {
            // Product change invalidates the batch allocation AND the variant
            // picked for the old product.
            updateLine(line.uid, { product, variantId: null, batchAllocations: [] })
          }}
          label=""
          placeholder={t('create.field.selectProduct')}
          productType="all"
        />
      ),
    },
    {
      id: 'variant',
      header: t('create.field.variant'),
      headerClassName: 'w-48',
      Cell: ({ line }) => (
        <VariantSelectCell
          line={line}
          onSelect={(variantId) => {
            // Variant change invalidates batch allocations (different variant =
            // different lots).
            updateLine(line.uid, { variantId, batchAllocations: [] })
          }}
          onVariantsLoaded={handleVariantsLoaded}
        />
      ),
    },
    {
      id: 'available',
      header: t('create.field.availableAtSource'),
      headerClassName: 'w-36 text-start md:text-end',
      cellClassName: 'md:text-end',
      Cell: ({ line }) => (
        <div>
          <AvailabilityCell line={line} sourceLocationId={sourceLocationId} />
          {line.product ? <TransferSourceSuggestion
            productId={line.product.id}
            variantId={line.variantId}
            destinationLocationId={destinationLocationId}
            requestedQuantity={line.quantity}
            lineCount={lines.length}
            onUseSource={setSourceLocationId}
          /> : null}
        </div>
      ),
    },
    {
      id: 'quantity',
      header: t('create.field.quantity'),
      headerClassName: 'w-40 text-start md:text-end',
      cellClassName: 'md:text-end',
      Cell: ({ line }) => (
        <QuantityCell
          value={line.quantity}
          onChange={(value) => {
            // Quantity change re-derives the FEFO split via the reconciliation
            // effect in BatchToggleCell — no manual wipe, so the panel and the
            // submit payload stay in sync with the new quantity.
            updateLine(line.uid, { quantity: value })
          }}
          decimalPlaces={getQuantityDecimals(line.product)}
          min="0"
          ariaLabel={t('create.field.quantity')}
          className="w-full md:w-28"
        />
      ),
    },
    {
      id: 'batch',
      header: t('create.batch.column'),
      headerClassName: 'w-44',
      Cell: ({ line }) => (
        <BatchToggleCell
          line={line}
          sourceLocationId={sourceLocationId}
          expanded={expandedBatchLineUid === line.uid}
          onToggle={() => {
            toggleBatchLine(line.uid)
          }}
          onAllocationsChange={(batchAllocations) => {
            updateLine(line.uid, { batchAllocations })
          }}
        />
      ),
    },
    {
      id: 'actions',
      header: <span className="sr-only">{t('create.field.removeLine')}</span>,
      headerClassName: 'w-12',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        <RemoveLineButton
          disabled={lines.length <= 1}
          label={t('create.field.removeLine')}
          onRemove={() => {
            removeLine(line.uid)
          }}
        />
      ),
    },
  ], [t, sourceLocationId, destinationLocationId, expandedBatchLineUid, lines.length, updateLine, removeLine, toggleBatchLine, handleVariantsLoaded])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <Link
            to="/inventory/stock-transfers"
            className={`mb-2 inline-flex items-center text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
          >
            <ArrowLeft className="me-1 h-4 w-4" />
            {t('detail.back')}
          </Link>
          <PageHeaderTitle className={`text-2xl font-semibold ${textColors.primary}`}>{t('create.title')}</PageHeaderTitle>
          <p className={textColors.tertiary}>{t('create.subtitle')}</p>
        </div>
      </div>

      <form onSubmit={(event) => { void handleFormSubmit(submitStockTransfer)(event) }} className="space-y-6">
        {/* Header section */}
        <section className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`mb-4 text-lg font-semibold ${textColors.primary}`}>
            {t('create.section.header')}
          </h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label htmlFor="source" className={tokens.label.base}>
                {t('create.field.sourceLocation')}
              </label>
              <Select
                id="source"
                value={sourceLocationId}
                onChange={(e) => {
                  setSourceLocationId(e.target.value)
                  setExpandedBatchLineUid(null)
                  setLines((prev) => prev.map((line) => ({ ...line, batchAllocations: [] })))
                }}
                required
              >
                <option value="">{t('create.field.selectLocation')}</option>
                {locations.map((loc) => (
                  <option key={loc.id} value={loc.id}>
                    {loc.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <label htmlFor="destination" className={tokens.label.base}>
                {t('create.field.destinationLocation')}
              </label>
              <Select
                id="destination"
                value={destinationLocationId}
                onChange={(e) => {
                  setDestinationLocationId(e.target.value)
                }}
                required
              >
                <option value="">{t('create.field.selectLocation')}</option>
                {locations
                  .filter((loc) => loc.id !== sourceLocationId)
                  .map((loc) => (
                    <option key={loc.id} value={loc.id}>
                      {loc.name}
                    </option>
                  ))}
              </Select>
            </div>
            <div className="md:col-span-2">
              <label htmlFor="notes" className={tokens.label.base}>
                {t('create.field.notes')}
              </label>
              <Textarea
                id="notes"
                value={notes}
                onChange={(e) => {
                  setNotes(e.target.value)
                }}
                rows={2}
              />
            </div>
          </div>
        </section>

        {/* Lines section */}
        <section className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`mb-4 text-lg font-semibold ${textColors.primary}`}>
            {t('create.section.lines')}
          </h2>
          {/* Search/scan entry bar: the fast path for adding transfer lines. */}
          <div className="mb-4">
            <LineItemEntryBar
              onBeforeAdd={ensureSourceForEntry}
              onAddProduct={upsertEntryLine}
              onRequiresVariant={handleRequiresVariant}
              onNotFound={handleEntryNotFound}
            />
            {pendingVariantChoice !== null ? (
              <VariantChooser product={pendingVariantChoice.product} onChoose={choosePendingVariant} />
            ) : null}
          </div>
          <LineItemsTable
            lines={lines}
            columns={lineColumns}
            getLineKey={(line) => line.uid}
            emptyTitle={t('create.validation.linesRequired')}
            renderLineDetail={(line) =>
              line.product !== null &&
              line.product.requires_batch_tracking &&
              expandedBatchLineUid === line.uid ? (
                <BatchDetailRow
                  line={line as DraftLine & { product: ProductPickerValue }}
                  sourceLocationId={sourceLocationId}
                  onChange={(batchAllocations) => {
                    updateLine(line.uid, { batchAllocations })
                  }}
                />
              ) : null
            }
            addControls={(
              <Button type="button" variant="secondary" size="sm" onClick={addLine}>
                <Plus className="me-1 h-4 w-4" />
                {t('create.field.addLine')}
              </Button>
            )}
          />
        </section>

        {/* Costs section */}
        <section className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`mb-4 text-lg font-semibold ${textColors.primary}`}>
            {t('create.section.costs')}
          </h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label htmlFor="cost" className={tokens.label.base}>
                {t('create.field.transferCost')}
              </label>
              <MoneyInput
                id="cost"
                value={transferCost}
                onChange={(value) => {
                  setTransferCost(value)
                }}
                currency={currency}
                min="0"
                className={tokens.input.base}
              />
            </div>
            <div>
              <label htmlFor="cost-label" className={tokens.label.base}>
                {t('create.field.transferCostLabel')}
              </label>
              <Input
                id="cost-label"
                type="text"
                value={transferCostLabel}
                onChange={(e) => {
                  setTransferCostLabel(e.target.value)
                }}
              />
            </div>
            <div>
              <label htmlFor="dist" className={tokens.label.base}>
                {t('create.field.transferCostDistribution')}
              </label>
              <Select
                id="dist"
                value={distribution}
                onChange={handleDistributionChange}
              >
                {DISTRIBUTION_OPTIONS.map((d) => (
                  <option key={d} value={d}>
                    {t(`create.distribution.${d}`)}
                  </option>
                ))}
              </Select>
            </div>
          </div>
        </section>

        <div className="flex justify-end gap-3">
          <Link to="/inventory/stock-transfers">
            <Button type="button" variant="secondary">
              {t('create.cancel')}
            </Button>
          </Link>
          <Button type="submit" variant="primary" disabled={createMutation.isPending}>
            {t('create.submit')}
          </Button>
        </div>
      </form>
    </div>
  )
}
