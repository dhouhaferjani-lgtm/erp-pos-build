import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import {
  stockLevelsInvalidationPredicate,
  stockMovementsInvalidationPredicate,
} from '@/features/inventory/_invalidation'
import { stockAdjustmentsInvalidationPredicate } from '../_invalidation'
import { stockAdjustmentApi, type PostStockAdjustmentOptions } from './stockAdjustmentApi'
import type {
  CreateStockAdjustmentInput,
  StockAdjustmentListFilters,
  UpdateStockAdjustmentInput,
} from '../types'

const namespace = 'stock-adjustments'

/**
 * The cross-feature import of `@/features/inventory/_invalidation` above is
 * DELIBERATE. It would be the first in the repo — all existing `_invalidation`
 * import sites are same-feature — and it is lint-legal (no boundary rule exists;
 * the only `no-restricted-imports` entry is the colorClasses quarantine).
 * Duplicating the stock-levels and stock-movements predicates here would be
 * worse: posting an adjustment invalidates exactly the caches inventory owns,
 * and two copies of a suffix-matching predicate is how they drift. Stated here
 * so a later sweep does not "fix" it.
 */
function useInvalidateAdjustmentCaches(): () => void {
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  return () => {
    void queryClient.invalidateQueries({
      predicate: stockAdjustmentsInvalidationPredicate(tenantId, companyId),
    })
    void queryClient.invalidateQueries({
      predicate: stockLevelsInvalidationPredicate(tenantId, companyId),
    })
    void queryClient.invalidateQueries({
      predicate: stockMovementsInvalidationPredicate(tenantId, companyId),
    })
  }
}

function useScope(): { tenantId: string | null; companyId: string | null } {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId)
  return { tenantId, companyId }
}

export function useStockAdjustmentList(filters: StockAdjustmentListFilters = {}) {
  const { tenantId, companyId } = useScope()

  return useQuery({
    queryKey: tenantScopedKey([namespace, 'list', filters]),
    queryFn: () => stockAdjustmentApi.list(filters),
    // tenantScopedKey's own instruction: pair it with an enabled guard so cache
    // invalidation is causal, not incidental. The transfers hooks omit this.
    enabled: !!tenantId && !!companyId,
  })
}

export function useStockAdjustment(id: string | undefined) {
  const { tenantId, companyId } = useScope()

  return useQuery({
    queryKey: tenantScopedKey([namespace, 'detail', id]),
    queryFn: () => {
      if (id === undefined) {
        throw new Error('Stock adjustment id is required')
      }
      return stockAdjustmentApi.show(id)
    },
    enabled: !!tenantId && !!companyId && typeof id === 'string' && id.length > 0,
  })
}

/**
 * The FRESH stock-level read that authors `observed_before`.
 *
 * `staleTime: 0` is the whole point: a cached anchor would make the staleness
 * guard fire on cache age rather than on real concurrent movement.
 */
export function useFreshStockLevel(productId: string | undefined, locationId: string | undefined) {
  const { tenantId, companyId } = useScope()

  return useQuery({
    queryKey: tenantScopedKey(['stock-levels', 'detail', productId, locationId]),
    queryFn: () => {
      if (productId === undefined || locationId === undefined) {
        throw new Error('Product and location are required')
      }
      return stockAdjustmentApi.stockLevel(productId, locationId)
    },
    staleTime: 0,
    enabled:
      !!tenantId &&
      !!companyId &&
      typeof productId === 'string' &&
      productId.length > 0 &&
      typeof locationId === 'string' &&
      locationId.length > 0,
  })
}

export function useCreateStockAdjustment() {
  const invalidate = useInvalidateAdjustmentCaches()

  return useMutation({
    mutationFn: (input: CreateStockAdjustmentInput) => stockAdjustmentApi.create(input),
    onSuccess: invalidate,
  })
}

export function useUpdateStockAdjustment() {
  const invalidate = useInvalidateAdjustmentCaches()

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: UpdateStockAdjustmentInput }) =>
      stockAdjustmentApi.update(id, input),
    onSuccess: invalidate,
  })
}

export function usePostStockAdjustment() {
  const invalidate = useInvalidateAdjustmentCaches()

  return useMutation({
    mutationFn: ({ id, options }: { id: string; options?: PostStockAdjustmentOptions }) =>
      stockAdjustmentApi.post(id, options ?? {}),
    onSuccess: invalidate,
  })
}

export function useCancelStockAdjustment() {
  const invalidate = useInvalidateAdjustmentCaches()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      stockAdjustmentApi.cancel(id, reason),
    onSuccess: invalidate,
  })
}

export function useCorrectStockAdjustment() {
  const invalidate = useInvalidateAdjustmentCaches()

  return useMutation({
    mutationFn: (id: string) => stockAdjustmentApi.correct(id),
    onSuccess: invalidate,
  })
}
