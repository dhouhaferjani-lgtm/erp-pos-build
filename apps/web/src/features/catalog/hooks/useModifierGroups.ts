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
import { compositeItemKeys } from './useCompositeItems'
import type { CreateModifierGroupData, CreateModifierData } from '../types/compositeItem'

export const modifierGroupKeys = {
  all: ['modifierGroups'] as const,
  lists: () => [...modifierGroupKeys.all, 'list'] as const,
  list: (params?: Record<string, unknown>) => [...modifierGroupKeys.lists(), params] as const,
  details: () => [...modifierGroupKeys.all, 'detail'] as const,
  detail: (id: string) => [...modifierGroupKeys.details(), id] as const,
}

export function useModifierGroups(params?: {
  search?: string
  is_active?: boolean
  per_page?: number
  page?: number
}) {
  return useQuery({
    queryKey: modifierGroupKeys.list(params as Record<string, unknown>),
    queryFn: () => getModifierGroups(params),
  })
}

export function useModifierGroup(id: string) {
  return useQuery({
    queryKey: modifierGroupKeys.detail(id),
    queryFn: () => getModifierGroup(id),
    enabled: !!id,
  })
}

export function useCreateModifierGroup() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateModifierGroupData) => createModifierGroup(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: modifierGroupKeys.all })
    },
  })
}

export function useUpdateModifierGroup() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateModifierGroupData> }) =>
      updateModifierGroup(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: modifierGroupKeys.all })
    },
  })
}

export function useDeleteModifierGroup() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteModifierGroup(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: modifierGroupKeys.all })
    },
  })
}

export function useCreateModifier() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ groupId, data }: { groupId: string; data: CreateModifierData }) =>
      createModifier(groupId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: modifierGroupKeys.all })
    },
  })
}

export function useUpdateModifier() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateModifierData> }) =>
      updateModifier(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: modifierGroupKeys.all })
    },
  })
}

export function useDeleteModifier() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteModifier(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: modifierGroupKeys.all })
    },
  })
}

export function useAssignModifierGroup() {
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
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useRemoveModifierGroup() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      compositeItemId,
      modifierGroupId,
    }: {
      compositeItemId: string
      modifierGroupId: string
    }) => removeModifierGroup(compositeItemId, modifierGroupId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}
