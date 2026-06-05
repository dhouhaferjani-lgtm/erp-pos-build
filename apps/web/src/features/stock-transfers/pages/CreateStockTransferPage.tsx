import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, ArrowLeft } from 'lucide-react'
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
import { bccomp } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { useCreateStockTransfer } from '../api/queries'
import type { CreateStockTransferInput, TransferCostDistribution } from '../types'

interface DraftLine {
  uid: string
  product: ProductPickerValue | null
  quantity: string
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
    { uid: generateUid(), product: null, quantity: '1' },
  ])

  const createMutation = useCreateStockTransfer()

  const addLine = () => {
    setLines((prev) => [...prev, { uid: generateUid(), product: null, quantity: '1' }])
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

  const lineColumns: LineItemsTableColumn<DraftLine>[] = [
    {
      id: 'product',
      header: t('create.field.product'),
      Cell: ({ line }) => (
        <ProductPicker
          value={line.product}
          onChange={(product) => {
            updateLine(line.uid, { product })
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
      id: 'actions',
      header: <span className="sr-only">{t('create.field.removeLine')}</span>,
      headerClassName: 'w-12',
      cellClassName: 'text-end',
      Cell: ({ line }) => (
        <button
          type="button"
          onClick={() => {
            removeLine(line.uid)
          }}
          disabled={lines.length <= 1}
          aria-label={t('create.field.removeLine')}
          className={`rounded p-2 ${textColors.error} ${textColors.hoverError} ${colors.hover.red50} disabled:opacity-30`}
        >
          <Trash2 className="h-4 w-4" />
        </button>
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
