import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getCompositeItems,
  getCompositeItem,
  createCompositeItem,
  updateCompositeItem,
  deleteCompositeItem,
  duplicateCompositeItem,
  checkCompositeItemAvailability,
} from '../api/compositeItemApi'
import type { CreateCompositeItemData, UpdateCompositeItemData } from '../types/compositeItem'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

export const compositeItemKeys = {
  all: ['compositeItems'] as const,
  lists: () => [...compositeItemKeys.all, 'list'] as const,
  list: (params?: Record<string, unknown>) => [...compositeItemKeys.lists(), params] as const,
  details: () => [...compositeItemKeys.all, 'detail'] as const,
  detail: (id: string) => [...compositeItemKeys.details(), id] as const,
}

export function compositeItemsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === 'compositeItems' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useCompositeItems(params?: {
  search?: string | undefined
  vertical_type?: string | undefined
  is_active?: boolean | undefined
  per_page?: number | undefined
  page?: number | undefined
}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...compositeItemKeys.list(params as Record<string, unknown>)]),
    queryFn: () => getCompositeItems(params),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function useCompositeItem(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...compositeItemKeys.detail(id)]),
    queryFn: () => getCompositeItem(id),
    enabled: !!id && tenantId !== null && companyId !== null,
  })
}

export function useCreateCompositeItem() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateCompositeItemData) => createCompositeItem(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: compositeItemsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateCompositeItem() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCompositeItemData }) => updateCompositeItem(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: compositeItemsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useDeleteCompositeItem() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteCompositeItem(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: compositeItemsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useDuplicateCompositeItem() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => duplicateCompositeItem(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: compositeItemsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useCompositeItemAvailability(id: string, locationId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...compositeItemKeys.detail(id), 'availability', locationId]),
    queryFn: () => checkCompositeItemAvailability(id, locationId),
    enabled: !!id && !!locationId && tenantId !== null && companyId !== null,
  })
}
