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

export const compositeItemKeys = {
  all: ['compositeItems'] as const,
  lists: () => [...compositeItemKeys.all, 'list'] as const,
  list: (params?: Record<string, unknown>) => [...compositeItemKeys.lists(), params] as const,
  details: () => [...compositeItemKeys.all, 'detail'] as const,
  detail: (id: string) => [...compositeItemKeys.details(), id] as const,
}

export function useCompositeItems(params?: {
  search?: string | undefined
  vertical_type?: string | undefined
  is_active?: boolean | undefined
  per_page?: number | undefined
  page?: number | undefined
}) {
  return useQuery({
    queryKey: compositeItemKeys.list(params as Record<string, unknown>),
    queryFn: () => getCompositeItems(params),
  })
}

export function useCompositeItem(id: string) {
  return useQuery({
    queryKey: compositeItemKeys.detail(id),
    queryFn: () => getCompositeItem(id),
    enabled: !!id,
  })
}

export function useCreateCompositeItem() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateCompositeItemData) => createCompositeItem(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useUpdateCompositeItem() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCompositeItemData }) => updateCompositeItem(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useDeleteCompositeItem() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteCompositeItem(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useDuplicateCompositeItem() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => duplicateCompositeItem(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useCompositeItemAvailability(id: string, locationId: string) {
  return useQuery({
    queryKey: [...compositeItemKeys.detail(id), 'availability', locationId],
    queryFn: () => checkCompositeItemAvailability(id, locationId),
    enabled: !!id && !!locationId,
  })
}
