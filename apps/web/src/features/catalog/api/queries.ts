import { useQuery, useMutation, useQueryClient, type UseQueryOptions } from '@tanstack/react-query'
import type { Category, GetCategoriesParams, CategoryTreeNode, CreateCategoryData, UpdateCategoryData } from '../types'
import {
  getCategories,
  getCategoryTree,
  getCategory,
  createCategory,
  updateCategory,
  deleteCategory,
} from './categories'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

/**
 * Query key factory for categories
 */
export const categoryKeys = {
  all: ['categories'] as const,
  lists: () => [...categoryKeys.all, 'list'] as const,
  list: (params?: GetCategoriesParams) => [...categoryKeys.lists(), params] as const,
  tree: () => [...categoryKeys.all, 'tree'] as const,
  details: () => [...categoryKeys.all, 'detail'] as const,
  detail: (id: number) => [...categoryKeys.details(), id] as const,
}

type CategoryInvalidationShape =
  | { readonly kind: 'all' }
  | { readonly kind: 'lists' }
  | { readonly kind: 'tree' }
  | { readonly kind: 'detail'; readonly id: number }

function categoriesInvalidationPredicate(
  shape: CategoryInvalidationShape,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    if (k.length < 3 || k[0] !== 'categories') return false
    if (k[k.length - 2] !== tenantId || k[k.length - 1] !== companyId) return false

    switch (shape.kind) {
      case 'all':
        return true
      case 'lists':
        return k[1] === 'list'
      case 'tree':
        return k[1] === 'tree'
      case 'detail':
        return k[1] === 'detail' && k[2] === shape.id
    }
  }
}

/**
 * Hook to fetch paginated categories
 */
export function useCategories(
  params?: GetCategoriesParams,
  options?: Omit<UseQueryOptions<Awaited<ReturnType<typeof getCategories>>>, 'queryKey' | 'queryFn'>
) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...categoryKeys.list(params)]),
    queryFn: () => getCategories(params),
    ...options,
    enabled: (options?.enabled ?? true) && tenantId !== null && companyId !== null,
  })
}

/**
 * Hook to fetch category tree
 */
export function useCategoryTree(
  options?: Omit<UseQueryOptions<CategoryTreeNode[]>, 'queryKey' | 'queryFn'>
) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...categoryKeys.tree()]),
    queryFn: getCategoryTree,
    ...options,
    enabled: (options?.enabled ?? true) && tenantId !== null && companyId !== null,
  })
}

/**
 * Hook to fetch a single category
 */
export function useCategory(
  id: number,
  options?: Omit<UseQueryOptions<Category>, 'queryKey' | 'queryFn'>
) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...categoryKeys.detail(id)]),
    queryFn: () => getCategory(id),
    ...options,
    enabled: Boolean(id) && (options?.enabled ?? true) && tenantId !== null && companyId !== null,
  })
}

/**
 * Hook to create a category
 */
export function useCreateCategory() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateCategoryData) => createCategory(data),
    onSuccess: async () => {
      // Invalidate all category queries
      await queryClient.invalidateQueries({
        predicate: categoriesInvalidationPredicate({ kind: 'all' }, tenantId, companyId),
      })
    },
  })
}

/**
 * Hook to update a category
 */
export function useUpdateCategory() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateCategoryData }) => updateCategory(id, data),
    onSuccess: async (_, variables) => {
      // Invalidate specific category and all lists/tree
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate({ kind: 'detail', id: variables.id }, tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate({ kind: 'lists' }, tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate({ kind: 'tree' }, tenantId, companyId),
        }),
      ])
    },
  })
}

/**
 * Hook to delete a category
 */
export function useDeleteCategory() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => deleteCategory(id),
    onSuccess: async () => {
      // Invalidate all category queries
      await queryClient.invalidateQueries({
        predicate: categoriesInvalidationPredicate({ kind: 'all' }, tenantId, companyId),
      })
    },
  })
}
