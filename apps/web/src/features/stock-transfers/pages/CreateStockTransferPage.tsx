import { useMemo, useState, type ReactNode } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, PackageSearch, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { useQuery } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { fetchLocations, type LocationApiResponse } from '@/features/location/api'
import { Button } from '@/components/atoms/Button/Button'
import { MoneyInput } from '@/components/atoms/MoneyInput/MoneyInput'
import { ProductPicker, type ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import { LineItemsTable, QuantityCell, type LineItemsTableColumn } from '@/components/molecules/line-items/LineItemsTable'
import { textColors, borderColors, tokens, colors } from '@/lib/designTokens'
import { useCurrency } from '@/hooks/useCurrency'
import { bccomp, bcsub } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { useProductBatches } from '@/features/batches/hooks/useBatches'
import type { Batch } from '@/features/batches/types'
import { useCreateStockTransfer } from '../api/queries'
import type { CreateStockTransferInput, TransferCostDistribution } from '../types'

interface DraftBatchAllocation {
  batch_id: number
  quantity: string
}

interface DraftLine {
  uid: string
  product: ProductPickerValue | null
  quantity: string
  batchAllocations: DraftBatchAllocation[]
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
                    decimalPlaces={4}
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
  const batchesQuery = useProductBatches(product?.id ?? '')
  const batches = useMemo(() => batchesQuery.data ?? [], [batchesQuery.data])

  if (product === null || !product.requires_batch_tracking) {
    return <span className={`text-sm ${textColors.tertiary}`}>—</span>
  }

  const isLoading = batchesQuery.isLoading === true || batchesQuery.isFetching === true

  const handleToggle = (): void => {
    if (!expanded && line.batchAllocations.length === 0 && sourceLocationId !== '' && batches.length > 0) {
      onAllocationsChange(buildFefoAllocations(batches, sourceLocationId, line.quantity))
    }
    onToggle()
  }

  return (
    <Button
      type="button"
      variant="secondary"
      size="sm"
      onClick={handleToggle}
      disabled={sourceLocationId === '' || isLoading}
      aria-expanded={expanded}
    >
      <PackageSearch className="me-1 h-4 w-4" />
      {line.batchAllocations.length > 0
        ? t('create.batch.allocated', { count: line.batchAllocations.length })
        : t('create.batch.action')}
    </Button>
  )
}

interface BatchDetailRowProps {
  line: DraftLine & { product: ProductPickerValue }
  sourceLocationId: string
  onChange: (allocations: DraftBatchAllocation[]) => void
}

/** Full-width expandable batch panel rendered beneath a batch-tracked line. */
function BatchDetailRow({ line, sourceLocationId, onChange }: BatchDetailRowProps) {
  const batchesQuery = useProductBatches(line.product.id)
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

export function CreateStockTransferPage() {
  const { t } = useTranslation('stock-transfers')
  const navigate = useNavigate()
  const { currency } = useCurrency()

  const locationsQuery = useQuery({
    queryKey: tenantScopedKey(['locations', 'all']),
    queryFn: () => fetchLocations(),
  })
  const locations: LocationApiResponse[] = locationsQuery.data ?? []

  const [sourceLocationId, setSourceLocationId] = useState('')
  const [destinationLocationId, setDestinationLocationId] = useState('')
  const [notes, setNotes] = useState('')
  const [transferCost, setTransferCost] = useState('0')
  const [transferCostLabel, setTransferCostLabel] = useState('')
  const [distribution, setDistribution] = useState<TransferCostDistribution>('pro_rata_value')
  const [lines, setLines] = useState<DraftLine[]>([
    { uid: generateUid(), product: null, quantity: '1', batchAllocations: [] },
  ])
  const [expandedBatchLineUid, setExpandedBatchLineUid] = useState<string | null>(null)

  const createMutation = useCreateStockTransfer()

  const addLine = () => {
    setLines((prev) => [...prev, { uid: generateUid(), product: null, quantity: '1', batchAllocations: [] }])
  }

  const removeLine = (uid: string) => {
    setLines((prev) => (prev.length <= 1 ? prev : prev.filter((l) => l.uid !== uid)))
  }

  const updateLine = (uid: string, patch: Partial<DraftLine>) => {
    setLines((prev) => prev.map((l) => (l.uid === uid ? { ...l, ...patch } : l)))
  }

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
      if (line.product.requires_batch_tracking && line.batchAllocations.length === 0) {
        toast.error(t('create.batch.required'))
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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    void submitTransfer()
  }

  const handleDistributionChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const raw = e.target.value
    if (isDistribution(raw)) {
      setDistribution(raw)
    }
  }

  const toggleBatchLine = (uid: string): void => {
    setExpandedBatchLineUid((current) => (current === uid ? null : uid))
  }

  const lineColumns: LineItemsTableColumn<DraftLine>[] = [
    {
      id: 'product',
      header: t('create.field.product'),
      Cell: ({ line }) => (
        <ProductPicker
          value={line.product}
          onChange={(product) => {
            // Product change invalidates any batch allocation picked for the old product.
            updateLine(line.uid, { product, batchAllocations: [] })
          }}
          label=""
          placeholder={t('create.field.selectProduct')}
          productType="all"
        />
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
            // Quantity change invalidates the FEFO split; user re-opens to re-allocate.
            updateLine(line.uid, { quantity: value, batchAllocations: [] })
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
  ]

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
          <h1 className={`text-2xl font-semibold ${textColors.primary}`}>{t('create.title')}</h1>
          <p className={textColors.tertiary}>{t('create.subtitle')}</p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
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
              <select
                id="source"
                value={sourceLocationId}
                onChange={(e) => {
                  setSourceLocationId(e.target.value)
                  setExpandedBatchLineUid(null)
                  setLines((prev) => prev.map((line) => ({ ...line, batchAllocations: [] })))
                }}
                className={tokens.select.base}
                required
              >
                <option value="">{t('create.field.selectLocation')}</option>
                {locations.map((loc) => (
                  <option key={loc.id} value={loc.id}>
                    {loc.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="destination" className={tokens.label.base}>
                {t('create.field.destinationLocation')}
              </label>
              <select
                id="destination"
                value={destinationLocationId}
                onChange={(e) => {
                  setDestinationLocationId(e.target.value)
                }}
                className={tokens.select.base}
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
              </select>
            </div>
            <div className="md:col-span-2">
              <label htmlFor="notes" className={tokens.label.base}>
                {t('create.field.notes')}
              </label>
              <textarea
                id="notes"
                value={notes}
                onChange={(e) => {
                  setNotes(e.target.value)
                }}
                rows={2}
                className={tokens.textarea.base}
              />
            </div>
          </div>
        </section>

        {/* Lines section */}
        <section className={`rounded-lg border ${borderColors.light} bg-white p-6`}>
          <h2 className={`mb-4 text-lg font-semibold ${textColors.primary}`}>
            {t('create.section.lines')}
          </h2>
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
              <input
                id="cost-label"
                type="text"
                value={transferCostLabel}
                onChange={(e) => {
                  setTransferCostLabel(e.target.value)
                }}
                className={tokens.input.base}
              />
            </div>
            <div>
              <label htmlFor="dist" className={tokens.label.base}>
                {t('create.field.transferCostDistribution')}
              </label>
              <select
                id="dist"
                value={distribution}
                onChange={handleDistributionChange}
                className={tokens.select.base}
              >
                {DISTRIBUTION_OPTIONS.map((d) => (
                  <option key={d} value={d}>
                    {t(`create.distribution.${d}`)}
                  </option>
                ))}
              </select>
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
