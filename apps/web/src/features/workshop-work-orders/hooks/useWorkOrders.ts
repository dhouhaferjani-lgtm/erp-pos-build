import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { workOrderApi } from '../api/workOrderApi'
import type {
  ApproveInput,
  CreateWorkOrderInput,
  PaginatedWorkOrders,
  TransitionInput,
  UpdateWorkOrderInput,
  WorkOrder,
  WorkOrderListFilters,
} from '../types'

/**
 * React Query key factory for Workshop / WorkOrder data.
 * Centralising key shapes keeps invalidation patterns easy to maintain.
 */
export const workOrderKeys = {
  all: ['workshop-work-orders'] as const,
  lists: () => [...workOrderKeys.all, 'list'] as const,
  list: (filters: WorkOrderListFilters) => [...workOrderKeys.lists(), filters] as const,
  details: () => [...workOrderKeys.all, 'detail'] as const,
  detail: (id: string) => [...workOrderKeys.details(), id] as const,
}

function useWorkOrdersTenantScope(): {
  tenantId: string | null
  companyId: string | null
  hasTenantScope: boolean
} {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return {
    tenantId,
    companyId,
    hasTenantScope: tenantId !== null && companyId !== null,
  }
}

function workOrderListsPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === workOrderKeys.all[0] &&
      k[1] === 'list' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useWorkOrders(filters: WorkOrderListFilters = {}) {
  const { hasTenantScope } = useWorkOrdersTenantScope()

  return useQuery<PaginatedWorkOrders>({
    queryKey: tenantScopedKey([...workOrderKeys.list(filters)]),
    queryFn: () => workOrderApi.list(filters),
    enabled: hasTenantScope,
    staleTime: 30 * 1000,
  })
}

export function useWorkOrder(id: string | undefined) {
  const { hasTenantScope } = useWorkOrdersTenantScope()

  return useQuery<WorkOrder>({
    queryKey: tenantScopedKey([...workOrderKeys.detail(id ?? '')]),
    queryFn: () => {
      if (typeof id !== 'string' || id.length === 0) {
        // Unreachable when `enabled` gates the query; satisfies strict
        // type checking without a type assertion.
        return Promise.reject(new Error('Work order id is required'))
      }
      return workOrderApi.get(id)
    },
    enabled: typeof id === 'string' && id.length > 0 && hasTenantScope,
    staleTime: 30 * 1000,
  })
}

export function useCreateWorkOrder() {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkOrdersTenantScope()

  return useMutation<WorkOrder, Error, CreateWorkOrderInput>({
    mutationFn: (input) => workOrderApi.create(input),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: workOrderListsPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateWorkOrder(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkOrdersTenantScope()

  return useMutation<WorkOrder, Error, UpdateWorkOrderInput>({
    mutationFn: (input) => workOrderApi.update(id, input),
    onSuccess: async () => {
      await Promise.all([
        qc.invalidateQueries({
          queryKey: tenantScopedKey([...workOrderKeys.detail(id)]),
        }),
        qc.invalidateQueries({
          predicate: workOrderListsPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

export function useTransitionWorkOrder(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkOrdersTenantScope()

  return useMutation<WorkOrder, Error, TransitionInput>({
    mutationFn: (input) => workOrderApi.transition(id, input),
    onSuccess: async () => {
      await Promise.all([
        qc.invalidateQueries({
          queryKey: tenantScopedKey([...workOrderKeys.detail(id)]),
        }),
        qc.invalidateQueries({
          predicate: workOrderListsPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

export function useApproveWorkOrder(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkOrdersTenantScope()

  return useMutation<WorkOrder, Error, ApproveInput>({
    mutationFn: (input) => workOrderApi.approve(id, input),
    onSuccess: async () => {
      await Promise.all([
        qc.invalidateQueries({
          queryKey: tenantScopedKey([...workOrderKeys.detail(id)]),
        }),
        qc.invalidateQueries({
          predicate: workOrderListsPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

export function useCompleteWorkOrder(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkOrdersTenantScope()

  return useMutation<
    WorkOrder,
    Error,
    { completion_mileage?: number | null; expected_updated_at?: string | null }
  >({
    mutationFn: (input) => workOrderApi.complete(id, input),
    onSuccess: async () => {
      await Promise.all([
        qc.invalidateQueries({
          queryKey: tenantScopedKey([...workOrderKeys.detail(id)]),
        }),
        qc.invalidateQueries({
          predicate: workOrderListsPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

export function useCancelWorkOrder(id: string) {
  const qc = useQueryClient()
  const { tenantId, companyId } = useWorkOrdersTenantScope()

  return useMutation<
    WorkOrder,
    Error,
    { reason_code: string; note?: string | null; expected_updated_at?: string | null }
  >({
    mutationFn: (input) => workOrderApi.cancel(id, input),
    onSuccess: async () => {
      await Promise.all([
        qc.invalidateQueries({
          queryKey: tenantScopedKey([...workOrderKeys.detail(id)]),
        }),
        qc.invalidateQueries({
          predicate: workOrderListsPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}
