import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getRecipes,
  getRecipe,
  createRecipe,
  updateRecipe,
  activateRecipe,
  calculateRecipeCost,
  createRecipeLine,
  updateRecipeLine,
  deleteRecipeLine,
  getVariants,
  createVariant,
  updateVariant,
  deleteVariant,
} from '../api/recipeApi'
import { compositeItemKeys } from './useCompositeItems'
import type { CreateRecipeData, CreateRecipeLineData, CreateVariantData } from '../types/compositeItem'

export const recipeKeys = {
  all: ['recipes'] as const,
  lists: () => [...recipeKeys.all, 'list'] as const,
  listByItem: (compositeItemId: string) => [...recipeKeys.lists(), compositeItemId] as const,
  details: () => [...recipeKeys.all, 'detail'] as const,
  detail: (id: string) => [...recipeKeys.details(), id] as const,
}

export const variantKeys = {
  all: ['variants'] as const,
  listByItem: (compositeItemId: string) => [...variantKeys.all, 'list', compositeItemId] as const,
}

export function useRecipes(compositeItemId: string) {
  return useQuery({
    queryKey: recipeKeys.listByItem(compositeItemId),
    queryFn: () => getRecipes(compositeItemId),
    enabled: !!compositeItemId,
  })
}

export function useRecipe(id: string) {
  return useQuery({
    queryKey: recipeKeys.detail(id),
    queryFn: () => getRecipe(id),
    enabled: !!id,
  })
}

export function useCreateRecipe() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ compositeItemId, data }: { compositeItemId: string; data: CreateRecipeData }) =>
      createRecipe(compositeItemId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipeKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useUpdateRecipe() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateRecipeData> }) => updateRecipe(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipeKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useActivateRecipe() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => activateRecipe(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipeKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useCalculateRecipeCost() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => calculateRecipeCost(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipeKeys.all })
    },
  })
}

export function useCreateRecipeLine() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ recipeId, data }: { recipeId: string; data: CreateRecipeLineData }) =>
      createRecipeLine(recipeId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipeKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useUpdateRecipeLine() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ recipeId, lineId, data }: { recipeId: string; lineId: string; data: Partial<CreateRecipeLineData> }) =>
      updateRecipeLine(recipeId, lineId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipeKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useDeleteRecipeLine() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ recipeId, lineId }: { recipeId: string; lineId: string }) =>
      deleteRecipeLine(recipeId, lineId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: recipeKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

// Variants
export function useVariants(compositeItemId: string) {
  return useQuery({
    queryKey: variantKeys.listByItem(compositeItemId),
    queryFn: () => getVariants(compositeItemId),
    enabled: !!compositeItemId,
  })
}

export function useCreateVariant() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ compositeItemId, data }: { compositeItemId: string; data: CreateVariantData }) =>
      createVariant(compositeItemId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: variantKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useUpdateVariant() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateVariantData> }) => updateVariant(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: variantKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}

export function useDeleteVariant() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteVariant(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: variantKeys.all })
      queryClient.invalidateQueries({ queryKey: compositeItemKeys.all })
    },
  })
}
