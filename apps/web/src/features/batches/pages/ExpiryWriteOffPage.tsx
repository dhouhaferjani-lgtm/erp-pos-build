import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { isApiError } from '../../../lib/api'
import { cn } from '../../../lib/utils'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { formatQuantity } from '../../../lib/format'
import { bccomp, bcadd } from '../../../lib/decimal'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { usePermissions } from '../../../hooks/usePermissions'
import { useLocation } from '../../../hooks/useLocation'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { DataTable, type DataTableColumn } from '../../../components/molecules'
import { QuantityInput } from '../../../components/atoms'
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog'
import { LocationSelector } from '../../location/LocationSelector'
import {
  stockLevelsInvalidationPredicate,
  stockMovementsInvalidationPredicate,
} from '../../inventory/_invalidation'
import { getExpiredBatches, groupedWriteOff } from '../api/batches'
import type { ExpiredBatch, GroupedWriteOffPayload } from '../types'

/** Quantity precision for write-off lines (precision contract: scale 4). */
const QUANTITY_SCALE = 4

/**
 * Tenant-scoped invalidation predicate for the `batches` namespace (covers the
 * expired-lots list and any batch collections/details).
 */
function batchesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'batches' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/** Sum of per-location reserved quantities for a lot (string, scale 4). */
function reservedQuantity(batch: ExpiredBatch): string {
  return batch.batch_stock.reduce(
    (acc, stock) => bcadd(acc, stock.reserved_quantity, QUANTITY_SCALE),
    '0',
  )
}

/**
 * Dedicated pharmacy screen to write off expired/expiring lots.
 *
 * Flow: pick a location → review expired lots → select multiple lots with a
 * per-lot quantity → one confirm → ONE grouped (idempotent) write-off call.
 *
 * Quantities are carried as canonical decimal STRINGS end-to-end (never coerced
 * via parseFloat/Number) so precision is preserved into the payload. The
 * batch-level `available_quantity`/`total_quantity` numbers are display-only.
 */
export function ExpiryWriteOffPage() {
  const { t } = useTranslation(['batches', 'common'])
  const { currentLocationId } = useLocation()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  const { hasPermission } = usePermissions()
  const canWriteOff = hasPermission('batches.write-off')

  // uuid → entered quantity string (presence ⇒ selected)
  const [quantities, setQuantities] = useState<Record<string, string>>({})
  const [confirmOpen, setConfirmOpen] = useState(false)
  // Generated once per confirmed submission; reused across retries of the SAME
  // submission so an idempotent replay hits the same server-side record.
  const [idempotencyKey, setIdempotencyKey] = useState<string | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['batches', 'expired', currentLocationId]),
    queryFn: () => getExpiredBatches(currentLocationId ?? undefined),
    enabled: !!tenantId && !!companyId,
  })

  const batches = useMemo(() => data ?? [], [data])

  const selectedIds = useMemo(
    () => new Set(Object.keys(quantities)),
    [quantities],
  )

  const writeOffMutation = useMutation({
    mutationFn: (payload: GroupedWriteOffPayload) => groupedWriteOff(payload),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: batchesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: stockMovementsInvalidationPredicate(tenantId, companyId),
        }),
      ])
      toast.success(t('expiryWriteOff.toast.success'))
      setConfirmOpen(false)
      setQuantities({})
      setIdempotencyKey(null)
    },
    onError: (err: unknown) => {
      if (isApiError(err) && err.response?.status === 422) {
        toast.error(t('expiryWriteOff.toast.shortStock'))
      } else {
        toast.error(t('expiryWriteOff.toast.error'))
      }
      // Keep the dialog open so a retry reuses the same idempotency key.
    },
  })

  const toggleLot = (uuid: string): void => {
    setQuantities((prev) => {
      if (uuid in prev) {
        const next = { ...prev }
        delete next[uuid]
        return next
      }
      const batch = batches.find((b) => b.uuid === uuid)
      return { ...prev, [uuid]: batch ? String(batch.available_quantity) : '0' }
    })
  }

  const toggleAll = (): void => {
    setQuantities((prev) => {
      // If every lot is already selected, clear; otherwise select all.
      const allSelected = batches.length > 0 && batches.every((b) => b.uuid in prev)
      if (allSelected) return {}
      const next: Record<string, string> = {}
      for (const b of batches) {
        next[b.uuid] = b.uuid in prev ? prev[b.uuid] : String(b.available_quantity)
      }
      return next
    })
  }

  const setQuantity = (uuid: string, value: string): void => {
    setQuantities((prev) => ({ ...prev, [uuid]: value }))
  }

  /** A selected line is invalid when empty, ≤ 0, or above the lot's available. */
  const lineHasError = (batch: ExpiredBatch): boolean => {
    const qty = quantities[batch.uuid]
    if (qty === undefined) return false
    if (qty.trim() === '' || bccomp(qty, '0') <= 0) return true
    return bccomp(qty, String(batch.available_quantity)) > 0
  }

  const selectedBatches = batches.filter((b) => b.uuid in quantities)
  const hasInvalidLine = selectedBatches.some(lineHasError)
  const canSubmit =
    canWriteOff &&
    !!currentLocationId &&
    selectedBatches.length > 0 &&
    !hasInvalidLine

  const handleOpenConfirm = (): void => {
    if (!canSubmit) return
    setIdempotencyKey(crypto.randomUUID())
    setConfirmOpen(true)
  }

  const handleConfirm = (): void => {
    if (!currentLocationId || idempotencyKey === null) return
    const payload: GroupedWriteOffPayload = {
      location_id: currentLocationId,
      lines: selectedBatches.map((b) => ({
        batch_id: b.uuid,
        quantity: quantities[b.uuid],
      })),
      reason: 'expiry',
      idempotency_key: idempotencyKey,
    }
    writeOffMutation.mutate(payload)
  }

  const columns: DataTableColumn<ExpiredBatch>[] = [
    {
      key: 'product',
      header: t('fields.product'),
      render: (batch) => (
        <div className="flex flex-col">
          <span className={cn('font-medium', textColors.primary)}>{batch.product.name}</span>
          <span className={cn('text-xs', textColors.tertiary)}>{batch.product.sku}</span>
        </div>
      ),
    },
    {
      key: 'lot',
      header: t('fields.batchNumber'),
      cellClassName: cn('text-sm', textColors.secondary),
      render: (batch) => batch.batch_number,
    },
    {
      key: 'expiry',
      header: t('fields.expiryDate'),
      cellClassName: cn('whitespace-nowrap text-sm', textColors.error),
      render: (batch) => batch.expiry_date,
    },
    {
      key: 'onHand',
      numeric: true,
      header: t('expiryWriteOff.columns.onHand'),
      cellClassName: cn('text-sm', textColors.secondary),
      render: (batch) => formatQuantity(batch.total_quantity),
    },
    {
      key: 'reserved',
      numeric: true,
      header: t('fields.reservedQuantity'),
      cellClassName: cn('text-sm', textColors.tertiary),
      render: (batch) => formatQuantity(reservedQuantity(batch)),
    },
    {
      key: 'available',
      numeric: true,
      header: t('fields.availableQuantity'),
      cellClassName: cn('text-sm font-medium', textColors.primary),
      render: (batch) => formatQuantity(batch.available_quantity),
    },
    {
      key: 'writeOffQty',
      header: t('expiryWriteOff.columns.writeOffQuantity'),
      width: '11rem',
      render: (batch) => {
        if (!(batch.uuid in quantities)) {
          return <span className={cn('text-sm', textColors.disabled)}>—</span>
        }
        return (
          <QuantityInput
            value={quantities[batch.uuid]}
            onChange={(value) => { setQuantity(batch.uuid, value) }}
            decimalPlaces={QUANTITY_SCALE}
            min="0"
            max={String(batch.available_quantity)}
            error={lineHasError(batch)}
            aria-label={t('expiryWriteOff.columns.writeOffQuantity')}
          />
        )
      },
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('expiryWriteOff.title')}
        subtitle={t('expiryWriteOff.subtitle')}
        actions={<LocationSelector />}
        className="mb-0"
      />

      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('expiryWriteOff.loadError')}
        </div>
      ) : (
        <>
          <DataTable
            columns={columns}
            data={batches}
            keyExtractor={(batch) => batch.uuid}
            isLoading={isLoading}
            className={cn('rounded-lg border bg-white', borderColors.light)}
            emptyTitle={t('expiryWriteOff.empty.title')}
            emptyDescription={t('expiryWriteOff.empty.description')}
            {...(canWriteOff
              ? {
                  selection: {
                    selectedIds,
                    onToggle: toggleLot,
                    onToggleAll: toggleAll,
                    getRowLabel: (batch: ExpiredBatch) => batch.product.name,
                    selectAllLabel: t('expiryWriteOff.actions.selectAll'),
                  },
                }
              : {})}
          />

          {canWriteOff && (
            <div className="flex items-center justify-end gap-3">
              <span className={cn('text-sm', textColors.tertiary)}>
                {t('expiryWriteOff.selectedCount', { count: selectedBatches.length })}
              </span>
              <button
                type="button"
                onClick={handleOpenConfirm}
                disabled={!canSubmit}
                className={cn(
                  tokens.button.base,
                  tokens.button.danger,
                  tokens.button.sizes.md,
                )}
              >
                {t('expiryWriteOff.actions.writeOffSelected')}
              </button>
            </div>
          )}
        </>
      )}

      <ConfirmDialog
        isOpen={confirmOpen}
        onClose={() => { setConfirmOpen(false) }}
        onConfirm={handleConfirm}
        title={t('expiryWriteOff.confirm.title')}
        message={t('expiryWriteOff.confirm.message', { count: selectedBatches.length })}
        confirmText={t('expiryWriteOff.actions.writeOffSelected')}
        variant="danger"
        isLoading={writeOffMutation.isPending}
      />
    </div>
  )
}
