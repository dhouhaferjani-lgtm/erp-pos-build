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
import { compositeItemsInvalidationPredicate } from './useCompositeItems'
import type { CreateRecipeData, CreateRecipeLineData, CreateVariantData } from '../types/compositeItem'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

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

export function recipesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === 'recipes' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function variantsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === 'variants' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useRecipes(compositeItemId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...recipeKeys.listByItem(compositeItemId)]),
    queryFn: () => getRecipes(compositeItemId),
    enabled: !!compositeItemId && tenantId !== null && companyId !== null,
  })
}

export function useRecipe(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...recipeKeys.detail(id)]),
    queryFn: () => getRecipe(id),
    enabled: !!id && tenantId !== null && companyId !== null,
  })
}

export function useCreateRecipe() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ compositeItemId, data }: { compositeItemId: string; data: CreateRecipeData }) =>
      createRecipe(compositeItemId, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: recipesInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

export function useUpdateRecipe() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateRecipeData> }) => updateRecipe(id, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: recipesInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

export function useActivateRecipe() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => activateRecipe(id),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: recipesInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

export function useCalculateRecipeCost() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => calculateRecipeCost(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ predicate: recipesInvalidationPredicate(tenantId, companyId) })
    },
  })
}

export function useCreateRecipeLine() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ recipeId, data }: { recipeId: string; data: CreateRecipeLineData }) =>
      createRecipeLine(recipeId, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: recipesInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

export function useUpdateRecipeLine() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ recipeId, lineId, data }: { recipeId: string; lineId: string; data: Partial<CreateRecipeLineData> }) =>
      updateRecipeLine(recipeId, lineId, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: recipesInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

export function useDeleteRecipeLine() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ recipeId, lineId }: { recipeId: string; lineId: string }) =>
      deleteRecipeLine(recipeId, lineId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: recipesInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

// Variants
export function useVariants(compositeItemId: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...variantKeys.listByItem(compositeItemId)]),
    queryFn: () => getVariants(compositeItemId),
    enabled: !!compositeItemId && tenantId !== null && companyId !== null,
  })
}

export function useCreateVariant() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ compositeItemId, data }: { compositeItemId: string; data: CreateVariantData }) =>
      createVariant(compositeItemId, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: variantsInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

export function useUpdateVariant() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateVariantData> }) => updateVariant(id, data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: variantsInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}

export function useDeleteVariant() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteVariant(id),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: variantsInvalidationPredicate(tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: compositeItemsInvalidationPredicate(tenantId, companyId) }),
      ])
    },
  })
}
