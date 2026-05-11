import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getModifierGroups,
  getModifierGroup,
  createModifierGroup,
  updateModifierGroup,
  deleteModifierGroup,
  createModifier,
  updateModifier,
  deleteModifier,
  assignModifierGroup,
  removeModifierGroup,
} from '../api/modifierGroupApi'
import { compositeItemsInvalidationPredicate } from './useCompositeItems'
import type { CreateModifierGroupData, CreateModifierData } from '../types/compositeItem'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

export const modifierGroupKeys = {
  all: ['modifierGroups'] as const,
  lists: () => [...modifierGroupKeys.all, 'list'] as const,
  list: (params?: Record<string, unknown>) => [...modifierGroupKeys.lists(), params] as const,
  details: () => [...modifierGroupKeys.all, 'detail'] as const,
  detail: (id: string) => [...modifierGroupKeys.details(), id] as const,
}

export function modifierGroupsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === 'modifierGroups' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useModifierGroups(params?: {
  search?: string | undefined
  is_active?: boolean | undefined
  per_page?: number | undefined
  page?: number | undefined
}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...modifierGroupKeys.list(params as Record<string, unknown>)]),
    queryFn: () => getModifierGroups(params),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function useModifierGroup(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...modifierGroupKeys.detail(id)]),
    queryFn: () => getModifierGroup(id),
    enabled: !!id && tenantId !== null && companyId !== null,
  })
}

export function useCreateModifierGroup() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateModifierGroupData) => createModifierGroup(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: modifierGroupsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateModifierGroup() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateModifierGroupData> }) =>
      updateModifierGroup(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: modifierGroupsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useDeleteModifierGroup() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteModifierGroup(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: modifierGroupsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useCreateModifier() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ groupId, data }: { groupId: string; data: CreateModifierData }) =>
      createModifier(groupId, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: modifierGroupsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateModifier() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateModifierData> }) =>
      updateModifier(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: modifierGroupsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useDeleteModifier() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteModifier(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: modifierGroupsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useAssignModifierGroup() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      compositeItemId,
      modifierGroupId,
      displayOrder,
    }: {
      compositeItemId: string
      modifierGroupId: string
      displayOrder?: number
    }) => assignModifierGroup(compositeItemId, modifierGroupId, displayOrder),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: compositeItemsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useRemoveModifierGroup() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      compositeItemId,
      modifierGroupId,
    }: {
      compositeItemId: string
      modifierGroupId: string
    }) => removeModifierGroup(compositeItemId, modifierGroupId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: compositeItemsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}
