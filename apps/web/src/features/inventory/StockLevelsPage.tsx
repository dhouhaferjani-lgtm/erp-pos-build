import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { usePageTitle } from '../../hooks/usePageTitle'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Package, AlertTriangle, MapPin, Plus, Minus, RefreshCw, X, ArrowRightLeft } from 'lucide-react'
import { api, apiPost } from '../../lib/api'
import { cn } from '../../lib/utils'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { bccomp, bcsub } from '../../lib/decimal'
import { formatQuantity } from '../../lib/format'
import { tokens, textColors, borderColors, colors } from '../../lib/designTokens'
import { SearchInput } from '../../components/molecules/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import { LocationSelector } from '../location/LocationSelector'
import { useLocation } from '../../hooks/useLocation'
import { getLocations } from '../locations/api/locations'
import {
  Button,
  StatusBadge,
  statusTone,
  Select,
  Textarea,
  QuantityInput,
} from '../../components/atoms'
import { PageHeader } from '../../components/molecules/PageHeader'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
} from '../../components/molecules'
import type { StockLevel, StockLevelsResponse } from './types'
import { stockLevelsInvalidationPredicate } from './_invalidation'

interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  quantity: string
  quantity_before: string
  quantity_after: string
  reference: string
  notes: string | null
  user_name: string | null
  created_at: string
}

type StockFilter = 'all' | 'low' | 'out'
type AdjustmentType = 'adjust' | 'receive' | 'issue' | 'transfer'
type StockStatusKey = 'out' | 'low' | 'in_stock'

export function StockLevelsPage() {
  const { t } = useTranslation(['common', 'inventory'])
  usePageTitle('stockLevels.title', 'inventory')
  const queryClient = useQueryClient()
  const { currentLocationId } = useLocation()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')
  const [stockFilter, setStockFilter] = useState<StockFilter>('all')
  const [selectedStock, setSelectedStock] = useState<StockLevel | null>(null)
  const [adjustmentType, setAdjustmentType] = useState<AdjustmentType>('adjust')
  const [adjustmentQuantity, setAdjustmentQuantity] = useState('')
  const [adjustmentReason, setAdjustmentReason] = useState('inventory_count')
  const [adjustmentNotes, setAdjustmentNotes] = useState('')
  const [transferLocationId, setTransferLocationId] = useState('')

  // Fetch all locations for transfer
  const { data: locationsData } = useQuery({
    queryKey: tenantScopedKey(['locations']),
    queryFn: getLocations,
    enabled: !!tenantId && !!companyId,
  })

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['stock-levels', searchQuery, currentLocationId]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (currentLocationId) params.append('location_id', currentLocationId)
      const queryString = params.toString()
      const response = await api.get<StockLevelsResponse>(`/stock-levels${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: !!tenantId && !!companyId,
  })

  const adjustMutation = useMutation({
    mutationFn: async (data: { product_id: string; location_id: string; new_quantity: string; reason: string }) => {
      return apiPost<StockMovement>('/stock-movements/adjust', data)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
      })
      closeModal()
    },
  })

  const receiveMutation = useMutation({
    mutationFn: async (data: { product_id: string; location_id: string; quantity: string; reference: string; notes?: string }) => {
      return apiPost<StockMovement>('/stock-movements/receive', data)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
      })
      closeModal()
    },
  })

  const issueMutation = useMutation({
    mutationFn: async (data: { product_id: string; location_id: string; quantity: string; reference: string; notes?: string }) => {
      return apiPost<StockMovement>('/stock-movements/issue', data)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
      })
      closeModal()
    },
  })

  const transferMutation = useMutation({
    mutationFn: async (data: { product_id: string; from_location_id: string; to_location_id: string; quantity: string; reference: string }) => {
      return apiPost<{ message: string }>('/stock-movements/transfer', data)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
      })
      closeModal()
    },
  })

  const stockLevels = useMemo(() => data?.data ?? [], [data?.data])
  const total = data?.meta?.total ?? stockLevels.length

  const filteredStockLevels = useMemo(() => {
    return stockLevels.filter((stock) => {
      if (stockFilter === 'low') {
        return (
          stock.min_quantity != null &&
          bccomp(stock.available, stock.min_quantity) <= 0 &&
          bccomp(stock.available, '0') > 0
        )
      }
      if (stockFilter === 'out') {
        return bccomp(stock.available, '0') <= 0
      }
      return true
    })
  }, [stockLevels, stockFilter])

  const filterTabs = useMemo(() => {
    const lowStockCount = stockLevels.filter(
      (s) =>
        s.min_quantity != null &&
        bccomp(s.available, s.min_quantity) <= 0 &&
        bccomp(s.available, '0') > 0
    ).length
    const outOfStockCount = stockLevels.filter((s) => bccomp(s.available, '0') <= 0).length

    return [
      { value: 'all' as StockFilter, label: t('common:filters.all'), count: total },
      { value: 'low' as StockFilter, label: t('inventory:stock.filters.lowStock'), count: lowStockCount },
      { value: 'out' as StockFilter, label: t('inventory:stock.filters.outOfStock'), count: outOfStockCount },
    ]
  }, [t, total, stockLevels])

  const getStockStatus = (stock: StockLevel): { label: string; key: StockStatusKey } => {
    if (bccomp(stock.available, '0') <= 0) {
      return { label: t('inventory:stock.status.outOfStock'), key: 'out' }
    }
    if (stock.min_quantity != null && bccomp(stock.available, stock.min_quantity) <= 0) {
      return { label: t('inventory:stock.status.lowStock'), key: 'low' }
    }
    return { label: t('inventory:stock.status.inStock'), key: 'in_stock' }
  }

  const openAdjustModal = (stock: StockLevel, type: AdjustmentType) => {
    setSelectedStock(stock)
    setAdjustmentType(type)
    setAdjustmentQuantity(type === 'adjust' ? stock.quantity : '')
    setAdjustmentReason('inventory_count')
    setAdjustmentNotes('')
  }

  const closeModal = () => {
    setSelectedStock(null)
    setAdjustmentQuantity('')
    setAdjustmentReason('inventory_count')
    setAdjustmentNotes('')
    setTransferLocationId('')
  }

  const handleSubmit = () => {
    if (!selectedStock) return

    const reasonLabels: Record<string, string> = {
      inventory_count: t('inventory:stock.reasons.inventoryCount'),
      damage: t('inventory:stock.reasons.damage'),
      correction: t('inventory:stock.reasons.correction'),
      other: t('inventory:stock.reasons.other'),
    }
    const reasonLabel = reasonLabels[adjustmentReason] ?? adjustmentReason
    const reference = adjustmentNotes ? `${reasonLabel}: ${adjustmentNotes}` : reasonLabel

    if (adjustmentType === 'adjust') {
      adjustMutation.mutate({
        product_id: selectedStock.product_id,
        location_id: selectedStock.location_id,
        new_quantity: adjustmentQuantity,
        reason: reference,
      })
    } else if (adjustmentType === 'receive') {
      const receiveData: { product_id: string; location_id: string; quantity: string; reference: string; notes?: string } = {
        product_id: selectedStock.product_id,
        location_id: selectedStock.location_id,
        quantity: adjustmentQuantity,
        reference: reference,
      }
      if (adjustmentNotes) {
        receiveData.notes = adjustmentNotes
      }
      receiveMutation.mutate(receiveData)
    } else if (adjustmentType === 'transfer') {
      if (!transferLocationId) return
      transferMutation.mutate({
        product_id: selectedStock.product_id,
        from_location_id: selectedStock.location_id,
        to_location_id: transferLocationId,
        quantity: adjustmentQuantity,
        reference: adjustmentNotes || t('inventory:stock.reasons.stockTransfer'),
      })
    } else {
      const issueData: { product_id: string; location_id: string; quantity: string; reference: string; notes?: string } = {
        product_id: selectedStock.product_id,
        location_id: selectedStock.location_id,
        quantity: adjustmentQuantity,
        reference: reference,
      }
      if (adjustmentNotes) {
        issueData.notes = adjustmentNotes
      }
      issueMutation.mutate(issueData)
    }
  }

  const isSubmitting = adjustMutation.isPending || receiveMutation.isPending || issueMutation.isPending || transferMutation.isPending
  const mutationError = adjustMutation.error ?? receiveMutation.error ?? issueMutation.error ?? transferMutation.error

  // Filter out the current location from transfer destinations
  const transferLocations = useMemo(() => {
    if (!selectedStock || !locationsData) return []
    return locationsData.filter(loc => loc.id !== selectedStock.location_id)
  }, [selectedStock, locationsData])

  const columns: DataTableColumn<StockLevel>[] = [
    {
      key: 'product',
      header: t('inventory:stock.product'),
      render: (stock) => (
        <Link
          to={`/inventory/products/${stock.product_id}`}
          className={cn('font-medium', textColors.primary, textColors.hoverPrimary)}
        >
          {stock.product_name ?? t('inventory:stock.unknownProduct')}
        </Link>
      ),
    },
    {
      key: 'location',
      header: t('inventory:stock.location'),
      cellClassName: cn('text-sm', textColors.tertiary),
      render: (stock) => (
        <div className="flex items-center gap-1">
          <MapPin className={cn('h-3.5 w-3.5', textColors.disabled)} />
          {stock.location_name ?? t('inventory:stock.defaultLocation')}
        </div>
      ),
    },
    {
      key: 'quantity',
      numeric: true,
      header: t('inventory:stock.quantity'),
      cellClassName: textColors.primary,
      render: (stock) => formatQuantity(stock.quantity),
    },
    {
      key: 'reserved',
      numeric: true,
      header: t('inventory:stock.reserved'),
      cellClassName: textColors.tertiary,
      render: (stock) => formatQuantity(stock.reserved),
    },
    {
      key: 'available',
      numeric: true,
      header: t('inventory:stock.available'),
      render: (stock) => {
        const isLowOrOut =
          bccomp(stock.available, '0') <= 0 ||
          (stock.min_quantity != null && bccomp(stock.available, stock.min_quantity) <= 0)
        return (
          <>
            <span className={cn('text-sm font-semibold', isLowOrOut ? textColors.error : textColors.primary)}>
              {formatQuantity(stock.available)}
            </span>
            {stock.min_quantity != null && (
              <span className={cn('ml-1 text-xs', textColors.disabled)}>
                {t('inventory:stock.minQuantityLabel', { quantity: stock.min_quantity })}
              </span>
            )}
          </>
        )
      },
    },
    {
      key: 'status',
      header: t('common:fields.status'),
      render: (stock) => {
        const status = getStockStatus(stock)
        const isLowOrOut = status.key === 'out' || status.key === 'low'
        return (
          <div className="flex items-center gap-2">
            {isLowOrOut && <AlertTriangle className={cn('h-4 w-4', textColors.warningDark)} />}
            <StatusBadge tone={statusTone(status.key, { out: 'danger', low: 'warning', in_stock: 'success' })}>
              {status.label}
            </StatusBadge>
          </div>
        )
      },
    },
    {
      key: 'actions',
      align: 'right',
      header: <span className="sr-only">{t('common:actions.actions')}</span>,
      render: (stock) => (
        <div className="flex items-center justify-end gap-1">
          <Button
            variant="ghost"
            size="sm"
            className="gap-1"
            onClick={() => { openAdjustModal(stock, 'receive') }}
            title={t('inventory:stock.receive')}
          >
            <Plus className="h-3.5 w-3.5" />
            {t('inventory:stock.buttons.in')}
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="gap-1"
            onClick={() => { openAdjustModal(stock, 'issue') }}
            title={t('inventory:stock.issue')}
          >
            <Minus className="h-3.5 w-3.5" />
            {t('inventory:stock.buttons.out')}
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="gap-1"
            onClick={() => { openAdjustModal(stock, 'adjust') }}
            title={t('inventory:stock.adjust')}
          >
            <RefreshCw className="h-3.5 w-3.5" />
            {t('inventory:stock.adjust')}
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="gap-1"
            onClick={() => { openAdjustModal(stock, 'transfer') }}
            title={t('inventory:stock.transfer')}
          >
            <ArrowRightLeft className="h-3.5 w-3.5" />
            {t('inventory:stock.transfer')}
          </Button>
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('inventory:stock.title')}
        subtitle={t('inventory:stock.subtitle', { count: total })}
        actions={
          <>
            <LocationSelector />
            <Link
              to="/inventory/movements"
              className={cn(
                'inline-flex items-center gap-2 rounded-lg border bg-white px-4 py-2 text-sm font-medium transition-colors',
                borderColors.default,
                textColors.secondary,
                colors.hover.gray50,
              )}
            >
              <RefreshCw className="h-4 w-4" />
              {t('inventory:stock.viewMovements')}
            </Link>
          </>
        }
        className="mb-0"
      />

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={stockFilter} onChange={setStockFilter} />
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder={t('inventory:products.searchPlaceholder')}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errors.loadingFailed', 'Error loading data. Please try again.')}
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={filteredStockLevels}
          keyExtractor={(stock) => stock.id}
          isLoading={isLoading}
          className={cn('rounded-lg border bg-white', borderColors.light)}
          emptyState={
            <div className="py-6">
              <EmptyState
                icon={<Package className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={
                  searchQuery || stockFilter !== 'all'
                    ? t('common:status.noResults')
                    : t('inventory:stock.empty.title')
                }
                description={
                  searchQuery
                    ? t('common:status.tryDifferentSearch')
                    : stockFilter !== 'all'
                      ? t('inventory:stock.empty.noMatchFilter')
                      : t('inventory:stock.empty.description')
                }
              />
            </div>
          }
        />
      )}

      {/* Summary Cards */}
      {stockLevels.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-3">
          <div className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
            <div className={cn('text-sm font-medium', textColors.tertiary)}>{t('inventory:stock.summary.totalProducts')}</div>
            <div className={cn('mt-1 text-2xl font-bold', textColors.primary)}>{total}</div>
          </div>
          <div className={cn('rounded-lg border p-4', tokens.alert.warning, borderColors.warning)}>
            <div className={cn('text-sm font-medium', textColors.warning)}>{t('inventory:stock.summary.lowStock')}</div>
            <div className={cn('mt-1 text-2xl font-bold', textColors.warning)}>
              {
                stockLevels.filter(
                  (s) =>
                    s.min_quantity != null &&
                    bccomp(s.available, s.min_quantity) <= 0 &&
                    bccomp(s.available, '0') > 0
                ).length
              }
            </div>
          </div>
          <div className={cn('rounded-lg border p-4', tokens.alert.error, borderColors.error)}>
            <div className={cn('text-sm font-medium', textColors.error)}>{t('inventory:stock.summary.outOfStock')}</div>
            <div className={cn('mt-1 text-2xl font-bold', textColors.error)}>
              {stockLevels.filter((s) => bccomp(s.available, '0') <= 0).length}
            </div>
          </div>
        </div>
      )}

      {/* Adjustment Modal */}
      {selectedStock && (
        <div className={tokens.modal.backdrop}>
          <div className={tokens.modal.container}>
            <div className={tokens.modal.header}>
              <h2 className={tokens.modal.title}>
                {adjustmentType === 'adjust' && t('inventory:stock.modal.adjustTitle')}
                {adjustmentType === 'receive' && t('inventory:stock.modal.receiveTitle')}
                {adjustmentType === 'issue' && t('inventory:stock.modal.issueTitle')}
                {adjustmentType === 'transfer' && t('inventory:stock.modal.transferTitle')}
              </h2>
              <button
                type="button"
                onClick={closeModal}
                className={tokens.modal.closeButton}
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-4">
              <div>
                <span className={tokens.label.base}>{t('inventory:stock.modal.product')}</span>
                <p className={cn('mt-1 text-sm', textColors.primary)}>{selectedStock.product_name}</p>
              </div>

              <div>
                <span className={tokens.label.base}>
                  {adjustmentType === 'transfer' ? t('inventory:stock.modal.fromLocation') : t('inventory:stock.modal.location')}
                </span>
                <p className={cn('mt-1 text-sm', textColors.primary)}>{selectedStock.location_name}</p>
              </div>

              {adjustmentType === 'transfer' && (
                <div>
                  <label htmlFor="transfer-location" className={tokens.label.base}>
                    {t('inventory:stock.modal.toLocation')}
                  </label>
                  <Select
                    id="transfer-location"
                    value={transferLocationId}
                    onChange={(e) => { setTransferLocationId(e.target.value) }}
                  >
                    <option value="">{t('inventory:stock.modal.selectDestination')}</option>
                    {transferLocations.map((loc) => (
                      <option key={loc.id} value={loc.id}>
                        {loc.name} ({loc.code})
                      </option>
                    ))}
                  </Select>
                </div>
              )}

              <div>
                <span className={tokens.label.base}>{t('inventory:stock.modal.currentQuantity')}</span>
                <p className={cn('mt-1 text-sm', textColors.primary)}>{formatQuantity(selectedStock.quantity)}</p>
              </div>

              <div>
                <label htmlFor="quantity" className={tokens.label.base}>
                  {adjustmentType === 'adjust' ? t('inventory:stock.modal.newQuantity') : t('inventory:stock.quantity')}
                </label>
                <QuantityInput
                  id="quantity"
                  value={adjustmentQuantity}
                  onChange={setAdjustmentQuantity}
                  decimalPlaces={4}
                  min="0"
                  placeholder={adjustmentType === 'adjust' ? t('inventory:stock.modal.enterNewQuantity') : t('inventory:stock.modal.enterQuantity')}
                />
                {adjustmentType === 'adjust' && adjustmentQuantity && (() => {
                  // safeBig in lib/decimal.ts treats unparseable input as 0,
                  // so this runs safely even on programmatic paste of garbage.
                  // Quantities are 4-decimal (decimal(15,4)); the delta must use
                  // the quantity scale, not the currency scale, or it truncates.
                  const delta = bcsub(adjustmentQuantity, selectedStock.quantity, 4)
                  const sign = bccomp(delta, '0') >= 0 ? '+' : ''
                  return (
                    <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                      {t('inventory:stock.modal.change')}: {sign}{delta}
                    </p>
                  )
                })()}
              </div>

              {adjustmentType !== 'transfer' && (
                <div>
                  <label htmlFor="reason" className={tokens.label.base}>
                    {t('inventory:stock.modal.reason')}
                  </label>
                  <Select
                    id="reason"
                    value={adjustmentReason}
                    onChange={(e) => { setAdjustmentReason(e.target.value) }}
                  >
                    <option value="inventory_count">{t('inventory:stock.reasons.inventoryCount')}</option>
                    <option value="damage">{t('inventory:stock.reasons.damage')}</option>
                    <option value="correction">{t('inventory:stock.reasons.correction')}</option>
                    <option value="other">{t('inventory:stock.reasons.other')}</option>
                  </Select>
                </div>
              )}

              <div>
                <label htmlFor="notes" className={tokens.label.base}>
                  {adjustmentType === 'transfer' ? t('inventory:stock.modal.reference') : t('inventory:stock.modal.notes')}
                </label>
                <Textarea
                  id="notes"
                  value={adjustmentNotes}
                  onChange={(e) => { setAdjustmentNotes(e.target.value) }}
                  rows={2}
                  placeholder={adjustmentType === 'transfer' ? t('inventory:stock.modal.enterReference') : t('inventory:stock.modal.addNotes')}
                />
              </div>

              {mutationError != null && (
                <div className={cn(tokens.alert.base, tokens.alert.error)}>
                  {mutationError instanceof Error ? mutationError.message : t('common:errors.generic')}
                </div>
              )}

              <div className={tokens.modal.footer}>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={closeModal}
                >
                  {t('common:actions.cancel')}
                </Button>
                <Button
                  type="button"
                  onClick={handleSubmit}
                  disabled={isSubmitting || !adjustmentQuantity || (adjustmentType === 'transfer' && !transferLocationId)}
                >
                  {isSubmitting ? t('inventory:stock.modal.saving') : adjustmentType === 'transfer' ? t('inventory:stock.transfer') : t('inventory:stock.modal.save')}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
