import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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

export function useWorkOrders(filters: WorkOrderListFilters = {}) {
  return useQuery<PaginatedWorkOrders>({
    queryKey: workOrderKeys.list(filters),
    queryFn: () => workOrderApi.list(filters),
    staleTime: 30 * 1000,
  })
}

export function useWorkOrder(id: string | undefined) {
  return useQuery<WorkOrder>({
    queryKey: workOrderKeys.detail(id ?? ''),
    queryFn: () => workOrderApi.get(id as string),
    enabled: typeof id === 'string' && id.length > 0,
    staleTime: 30 * 1000,
  })
}

export function useCreateWorkOrder() {
  const qc = useQueryClient()
  return useMutation<WorkOrder, Error, CreateWorkOrderInput>({
    mutationFn: (input) => workOrderApi.create(input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workOrderKeys.lists() })
    },
  })
}

export function useUpdateWorkOrder(id: string) {
  const qc = useQueryClient()
  return useMutation<WorkOrder, Error, UpdateWorkOrderInput>({
    mutationFn: (input) => workOrderApi.update(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workOrderKeys.detail(id) })
      void qc.invalidateQueries({ queryKey: workOrderKeys.lists() })
    },
  })
}

export function useTransitionWorkOrder(id: string) {
  const qc = useQueryClient()
  return useMutation<WorkOrder, Error, TransitionInput>({
    mutationFn: (input) => workOrderApi.transition(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workOrderKeys.detail(id) })
      void qc.invalidateQueries({ queryKey: workOrderKeys.lists() })
    },
  })
}

export function useApproveWorkOrder(id: string) {
  const qc = useQueryClient()
  return useMutation<WorkOrder, Error, ApproveInput>({
    mutationFn: (input) => workOrderApi.approve(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workOrderKeys.detail(id) })
      void qc.invalidateQueries({ queryKey: workOrderKeys.lists() })
    },
  })
}

export function useCompleteWorkOrder(id: string) {
  const qc = useQueryClient()
  return useMutation<
    WorkOrder,
    Error,
    { completion_mileage?: number | null; expected_updated_at?: string | null }
  >({
    mutationFn: (input) => workOrderApi.complete(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workOrderKeys.detail(id) })
      void qc.invalidateQueries({ queryKey: workOrderKeys.lists() })
    },
  })
}

export function useCancelWorkOrder(id: string) {
  const qc = useQueryClient()
  return useMutation<
    WorkOrder,
    Error,
    { reason_code: string; note?: string | null; expected_updated_at?: string | null }
  >({
    mutationFn: (input) => workOrderApi.cancel(id, input),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: workOrderKeys.detail(id) })
      void qc.invalidateQueries({ queryKey: workOrderKeys.lists() })
    },
  })
}
