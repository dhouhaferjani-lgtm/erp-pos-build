import { useState, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ArrowDownCircle, ArrowUpCircle, RefreshCw, ArrowRightLeft, Package } from 'lucide-react'
import { toast } from 'sonner'
import { api, isApiError } from '../../lib/api'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import { bccomp, formatQuantity } from '../../lib/decimal'
import { getQuantityDecimals } from '../../lib/quantityScale'
import { locationScopedKey, normalizeViewScope } from '../../lib/locationScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { usePermissions } from '../../hooks/usePermissions'
import { usePlaceholderScopeGuard } from '../../hooks/usePlaceholderScopeGuard'
import { SearchInput } from '../../components/molecules/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import { useViewScope } from '../locations/hooks/useViewScope'
import { StatusBadge, type StatusTone } from '../../components/atoms/StatusBadge/StatusBadge'
import { EntityLink } from '../../components/molecules/EntityLink'
import { documentRouteTypeFromSource } from '../../lib/entityRoutes'
import { PageHeader } from '../../components/molecules/PageHeader'
import { DataTable, type DataTableColumn } from '../../components/molecules/DataTable/DataTable'
import { OffsetPagination } from '../../components/ui/OffsetPagination'
import type { OffsetPaginationMeta } from '../../types/pagination'
import { EmptyState } from '../../components/molecules/EmptyState/EmptyState'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { reverseWriteOff } from '../batches/api/batches'
import {
  batchesInvalidationPredicate,
  stockLevelsInvalidationPredicate,
  stockMovementsInvalidationPredicate,
} from './_invalidation'

/**
 * A `GET /api/v1/stock-movements` row, exactly as the controller emits it.
 * EXPORTED so tests bind their fixtures to this shape instead of re-declaring a
 * narrower copy — a fixture missing a field the page reads is how a green suite
 * hides a runtime break (gate r2, N7).
 */
export interface StockMovement {
  id: string
  product_id: string
  product_name: string
  location_id: string
  location_name: string
  movement_type: string
  /** MovementReason value e.g. 'write_off', 'expiry', 'damage', or null */
  reason: string | null
  quantity: string
  quantity_decimals: number
  quantity_before: string
  quantity_after: string
  reference: string
  /** Document-linkage morph type (StockMovementReferenceType value) or null. */
  reference_type: string | null
  source_document_id: string | null
  source_document_type: string | null
  notes: string | null
  user_id: string
  user_name: string | null
  /** UUID of the original movement this row corrects; null if this is not a reversal. */
  reverses_movement_id: string | null
  /** True when another movement has already reversed this row. */
  is_reversed: boolean
  created_at: string
}

/** The endpoint's unconditionally paginated envelope. Exported with {@link StockMovement}. */
export interface StockMovementsResponse {
  data: StockMovement[]
  meta: OffsetPaginationMeta
}

type MovementFilter = 'all' | 'receipt' | 'issue' | 'adjustment' | 'transfer' | 'write_off'

/**
 * Reasons that the backend ReverseWriteOffService considers reversible.
 * Mirrors MovementReason::{Expiry,Damage,WriteOff} enum values exactly.
 */
const REVERSIBLE_WRITE_OFF_REASONS = ['write_off', 'expiry', 'damage'] as const
type ReversibleWriteOffReason = typeof REVERSIBLE_WRITE_OFF_REASONS[number]

function isReversibleWriteOff(reason: string | null): reason is ReversibleWriteOffReason {
  return reason !== null && (REVERSIBLE_WRITE_OFF_REASONS as readonly string[]).includes(reason)
}

/**
 * Document-linkage types whose write-off movements the backend REFUSES to
 * reverse. Mirrors StockMovementReferenceType::PosReceiptReturnScrap exactly.
 *
 * A POS return scrap carries reason='write_off' and movement_type='issue', so it
 * is indistinguishable from a lot write-off by reason alone — but it is undone by
 * CORRECTING THE RETURN, never by ReverseWriteOffService (which would put
 * physically destroyed goods back into sellable stock and inflate the DEFAULT lot
 * on batch-tracked products). Keep this in sync with the backend guard.
 */
const NON_REVERSIBLE_REFERENCE_TYPES = ['pos_receipt_return_scrap'] as const

function isReversibleMovement(movement: StockMovement): boolean {
  if (!isReversibleWriteOff(movement.reason)) return false

  // A write-off REVERSAL is a receipt that inherits the original's reason, so it
  // is indistinguishable from a genuine write-off by reason alone — and the
  // Write-Offs tab (reason-only server filter) now surfaces it. The backend
  // refuses it unconditionally (ReverseWriteOffService: "is itself a reversal
  // and cannot be reversed"), so never offer the control (gate r1, B2).
  if (movement.reverses_movement_id !== null) return false

  // A stock ADJUSTMENT line can carry reason_code 'damage'/'write_off' on a
  // non-batch-tracked product, so the reason-only Write-Offs tab surfaces it —
  // but ReverseWriteOffService refuses anything that is not an ISSUE ("Only
  // write-off issue movements can be reversed"), so never offer the control
  // (gate r2, F1).
  if (movement.movement_type !== 'issue') return false

  return movement.reference_type === null
    || !(NON_REVERSIBLE_REFERENCE_TYPES as readonly string[]).includes(movement.reference_type)
}

/**
 * Per-movement-type presentation: semantic tone for the {@link StatusBadge} pill
 * and the leading lucide icon. Tones come from the sanctioned {@link StatusTone}
 * set so no off-theme color literals are introduced.
 */
const movementTypeConfig: Record<string, { tone: StatusTone; icon: typeof ArrowDownCircle }> = {
  receipt: { tone: 'success', icon: ArrowDownCircle },
  issue: { tone: 'danger', icon: ArrowUpCircle },
  adjustment: { tone: 'info', icon: RefreshCw },
  transfer_in: { tone: 'success', icon: ArrowRightLeft },
  transfer_out: { tone: 'warning', icon: ArrowRightLeft },
  write_off: { tone: 'danger', icon: ArrowUpCircle },
}

export function StockMovementsPage() {
  const { t } = useTranslation(['inventory', 'common'])
  const { scope, effectiveLocationIds } = useViewScope()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')
  const [movementFilter, setMovementFilter] = useState<MovementFilter>('all')
  // The id of the write-off movement currently pending reversal confirmation,
  // or null when the dialog is closed.
  const [reverseTargetId, setReverseTargetId] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  // Any change to a server-side filter invalidates the current offset: page 4 of
  // the previous result set is meaningless for the new one. Adjusted DURING
  // render (React's documented derived-state pattern) rather than in an effect,
  // so the reset happens before the query key is read — an effect would let one
  // request for the stale page escape first.
  // `scope` is normalised through the SAME helper the query key uses, so a
  // permuted-but-equal location selection cannot reset the offset without the
  // query key changing (gate r1, N2).
  // Tenant/company are part of the signature too (gate r1, MAJOR-3): page 4 of
  // company one's movements is as meaningless for company two as it is for a new
  // filter, and without them the operator lands on an empty page-4 table.
  const filterSignature = JSON.stringify([
    searchQuery,
    movementFilter,
    normalizeViewScope(scope),
    tenantId,
    companyId,
  ])
  const [appliedFilterSignature, setAppliedFilterSignature] = useState(filterSignature)
  if (appliedFilterSignature !== filterSignature) {
    setAppliedFilterSignature(filterSignature)
    setPage(1)
  }

  const { hasPermission } = usePermissions()
  const canReverseWriteOff = hasPermission('batches.write-off')

  const queryClient = useQueryClient()

  const { data, isLoading, isPlaceholderData, error } = useQuery({
    queryKey: locationScopedKey(['stock-movements', searchQuery, movementFilter, page, perPage], scope),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      effectiveLocationIds.forEach((id) => { params.append('location_ids[]', id) })
      // Every filter is resolved server-side: the browser never narrows a page
      // it has already received, because a page is a slice of the WHOLE result
      // set and client filtering would silently drop matching rows on page 2+.
      if (movementFilter === 'transfer') {
        params.append('movement_type', 'transfer')
      } else if (movementFilter === 'write_off') {
        params.append('reason', 'write_off')
      } else if (movementFilter !== 'all') {
        params.append('movement_type', movementFilter)
      }
      params.append('page', String(page))
      params.append('per_page', String(perPage))
      const response = await api.get<StockMovementsResponse>(`/stock-movements?${params.toString()}`)
      return response.data
    },
    enabled: !!tenantId && !!companyId,
    // Keep the previous page rendered while the next one loads so paging does
    // not flash an empty table. Scoped to ONE tenant/company by
    // `usePlaceholderScopeGuard` below — TanStack picks its placeholder with no
    // key-lineage check, so unguarded it would also survive a company switch.
    placeholderData: keepPreviousData,
  })

  // A placeholder fetched for the PREVIOUS company must never reach the table or
  // the pagination bar: those rows link into products and documents this company
  // cannot see, and their action column would offer a reverse-write-off against
  // them. `locationScopedKey` also carries the location scope, so that dimension
  // is signed too (gate r1, MAJOR-1) — attributing one location's movements to
  // another is a wrong read on a stock-reconciliation screen.
  const isStaleScopeData = usePlaceholderScopeGuard(isPlaceholderData, data !== undefined, [
    normalizeViewScope(scope),
  ])

  const reverseWriteOffMutation = useMutation({
    mutationFn: (movementId: string) => reverseWriteOff(movementId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: stockMovementsInvalidationPredicate(tenantId, companyId),
      })
      await queryClient.invalidateQueries({
        predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
      })
      await queryClient.invalidateQueries({
        predicate: batchesInvalidationPredicate(tenantId, companyId),
      })
      toast.success(t('movements.actions.reverseSuccess'))
      setReverseTargetId(null)
    },
    onError: (err: unknown) => {
      if (isApiError(err) && err.response?.status === 409) {
        toast.error(t('movements.actions.reverseAlreadyReversed'))
      } else {
        toast.error(t('movements.actions.reverseFailed'))
      }
      setReverseTargetId(null)
    },
  })

  // The server already applied every filter; the page is rendered verbatim.
  const movements = isStaleScopeData ? [] : data?.data ?? []
  // Hoisted once: `api.get<StockMovementsResponse>` is an unchecked cast, so a
  // rolling deploy / error envelope can still hand us a meta-less body. Binding
  // it to a variable keeps the runtime guard AND keeps the declared response
  // type strict, without the inline-chain `no-unnecessary-condition` warning.
  const meta = isStaleScopeData ? undefined : data?.meta

  // No counts: a single page cannot supply the GLOBAL total for the other tabs,
  // and a per-page count would understate every filter the user has not selected.
  const filterTabs = useMemo(() => [
    { value: 'all' as MovementFilter, label: t('common:filters.all') },
    { value: 'receipt' as MovementFilter, label: t('movements.filters.receipts') },
    { value: 'issue' as MovementFilter, label: t('movements.filters.issues') },
    { value: 'adjustment' as MovementFilter, label: t('movements.filters.adjustments') },
    { value: 'transfer' as MovementFilter, label: t('movements.filters.transfers') },
    { value: 'write_off' as MovementFilter, label: t('movements.filters.writeOffs') },
  ], [t])

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  const movementTypeLabels: Record<string, string> = {
    receipt: t('movements.typeLabels.receipt'),
    issue: t('movements.typeLabels.issue'),
    adjustment: t('movements.typeLabels.adjustment'),
    transfer_in: t('movements.typeLabels.transfer_in'),
    transfer_out: t('movements.typeLabels.transfer_out'),
    write_off: t('movements.typeLabels.write_off'),
  }

  const getMovementConfig = (movement: StockMovement) => {
    // Write-off movements (reason in {write_off, expiry, damage}) are issues
    // displayed with a dedicated label instead of the generic "Issue" label.
    const configKey = isReversibleWriteOff(movement.reason) ? 'write_off' : movement.movement_type
    const base = movementTypeConfig[configKey] ?? { tone: 'neutral' as StatusTone, icon: Package }
    return { ...base, label: movementTypeLabels[configKey] ?? movement.movement_type }
  }

  const columns: DataTableColumn<StockMovement>[] = [
    {
      key: 'date',
      header: t('products.movementsTab.columns.date'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (movement) => formatDate(movement.created_at),
    },
    {
      key: 'type',
      header: t('products.movementsTab.columns.type'),
      render: (movement) => {
        const config = getMovementConfig(movement)
        const Icon = config.icon
        const isPositive = bccomp(movement.quantity, '0') >= 0
        return (
          <div className="flex items-center gap-2">
            <Icon className={cn('h-4 w-4', isPositive ? textColors.success : textColors.error)} />
            <StatusBadge tone={config.tone}>{config.label}</StatusBadge>
          </div>
        )
      },
    },
    {
      key: 'product',
      header: t('movements.columns.product'),
      render: (movement) => (
        <EntityLink
          type="product"
          id={movement.product_id}
          label={movement.product_name}
          className={cn('font-medium', textColors.hoverPrimary)}
        />
      ),
    },
    {
      key: 'location',
      header: t('products.movementsTab.columns.location'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (movement) => movement.location_name,
    },
    {
      key: 'quantity',
      numeric: true,
      header: t('products.movementsTab.columns.quantity'),
      render: (movement) => {
        const isPositive = bccomp(movement.quantity, '0') >= 0
        return (
          <span className={cn('text-sm font-semibold', isPositive ? textColors.success : textColors.error)}>
            {isPositive ? '+' : ''}{formatQuantity(movement.quantity, getQuantityDecimals(movement))}
          </span>
        )
      },
    },
    {
      key: 'before',
      numeric: true,
      header: t('products.movementsTab.columns.before'),
      cellClassName: cn('text-sm', textColors.tertiary),
      render: (movement) => formatQuantity(movement.quantity_before, getQuantityDecimals(movement)),
    },
    {
      key: 'after',
      numeric: true,
      header: t('products.movementsTab.columns.after'),
      cellClassName: cn('text-sm font-medium', textColors.primary),
      render: (movement) => formatQuantity(movement.quantity_after, getQuantityDecimals(movement)),
    },
    {
      key: 'reference',
      header: t('products.movementsTab.columns.reference'),
      cellClassName: cn('text-sm max-w-xs truncate', textColors.tertiary),
      render: (movement) => {
        const documentType = documentRouteTypeFromSource(movement.source_document_type)
        return (
          <span title={movement.reference}>
            {documentType ? (
              <EntityLink
                type="document"
                id={movement.source_document_id}
                documentType={documentType}
                label={movement.reference}
              />
            ) : (
              movement.reference
            )}
          </span>
        )
      },
    },
    {
      key: 'user',
      header: t('movements.columns.user'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.tertiary),
      render: (movement) => movement.user_name ?? t('movements.system'),
    },
    // ── Reverse action (write-offs only, permission-gated) ───────────────────
    {
      key: 'actions',
      header: '',
      render: (movement) => {
        if (!isReversibleMovement(movement)) return null
        if (!canReverseWriteOff) return null
        if (movement.is_reversed) {
          return (
            <span className={cn('text-xs', textColors.disabled)}>
              {t('movements.actions.reversed')}
            </span>
          )
        }
        return (
          <button
            type="button"
            onClick={() => { setReverseTargetId(movement.id) }}
            aria-label={t('movements.actions.reverse')}
            className={cn(
              'text-sm font-medium transition-opacity hover:opacity-80',
              textColors.error,
            )}
          >
            {t('movements.actions.reverse')}
          </button>
        )
      },
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('movements.title')}
        subtitle={t('movements.subtitle', { count: meta?.total ?? 0 })}
        breadcrumb={
          <Link
            to="/inventory/stock"
            className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:actions.back')}
          </Link>
        }
        className="mb-0"
      />

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={movementFilter} onChange={setMovementFilter} />
        <SearchInput
          value={searchQuery}
          onChange={setSearchQuery}
          placeholder={t('movements.searchPlaceholder')}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('common:errors.operationFailed')}
        </div>
      ) : (
        <div>
          <DataTable
            columns={columns}
            data={movements}
            keyExtractor={(movement) => movement.id}
            isLoading={isLoading || isStaleScopeData}
            className={cn('rounded-lg border bg-white', borderColors.light)}
            emptyState={
              <div className="py-6">
                <EmptyState
                  icon={<RefreshCw className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                  title={
                    searchQuery || movementFilter !== 'all'
                      ? t('common:status.noResults')
                      : t('movements.noMovements')
                  }
                  description={
                    searchQuery
                      ? t('common:status.tryDifferentSearch')
                      : movementFilter !== 'all'
                        ? t('movements.noMatchFilter')
                        : t('movements.emptyDescription')
                  }
                />
              </div>
            }
          />
          {meta ? (
            <OffsetPagination
              currentPage={meta.current_page}
              lastPage={meta.last_page}
              total={meta.total}
              perPage={meta.per_page}
              from={meta.from}
              to={meta.to}
              onPageChange={setPage}
              onPerPageChange={(next) => {
                setPerPage(next)
                setPage(1)
              }}
            />
          ) : null}
        </div>
      )}

      {/* Reverse write-off confirmation dialog */}
      <ConfirmDialog
        isOpen={reverseTargetId !== null}
        onClose={() => { setReverseTargetId(null) }}
        onConfirm={() => {
          if (reverseTargetId !== null) {
            reverseWriteOffMutation.mutate(reverseTargetId)
          }
        }}
        title={t('movements.actions.reverseConfirmTitle')}
        message={t('movements.actions.reverseConfirmMessage')}
        confirmText={t('movements.actions.reverse')}
        variant="danger"
        isLoading={reverseWriteOffMutation.isPending}
      />
    </div>
  )
}
