import { useQuery, useMutation, useQueryClient, type UseQueryResult, type UseMutationResult } from '@tanstack/react-query'
import {
  fetchCategories,
  fetchCategoryTree,
  fetchCategory,
  createCategory,
  updateCategory,
  deleteCategory,
  reorderCategories,
  type CategoryApiResponse,
  type CategoriesListResponse,
  type CreateCategoryInput,
  type UpdateCategoryInput,
  type ReorderCategoryInput,
} from '../api'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

/**
 * Query key factory for categories.
 *
 * Returns un-scoped structural prefixes; the tenant + company scope is
 * appended at the useQuery / invalidateQueries call site via
 * tenantScopedKey([...]). The audit-tanstack-keys gate (see
 * apps/web/tools/audit-tanstack-keys.mjs) only approves a queryKey that
 * is either an array literal carrying an approved scope identifier OR a
 * bare-Identifier call expression `tenantScopedKey(...)`. A property-
 * access factory call like `categoryKeys.list(...)` is neither, so the
 * wrap MUST happen at the call site, not inside this factory.
 */
export const categoryKeys = {
  all: ['categories'] as const,
  lists: () => [...categoryKeys.all, 'list'] as const,
  list: (params?: {
    page?: number
    perPage?: number
    search?: string
    parentId?: number | null
    isActive?: boolean
  }) => [...categoryKeys.lists(), params] as const,
  trees: () => [...categoryKeys.all, 'tree'] as const,
  tree: () => [...categoryKeys.trees()] as const,
  details: () => [...categoryKeys.all, 'detail'] as const,
  detail: (id: number) => [...categoryKeys.details(), id] as const,
}

/**
 * Hook to fetch paginated list of categories
 */
export function useCategories(params?: {
  page?: number
  perPage?: number
  search?: string
  parentId?: number | null
  isActive?: boolean
}): UseQueryResult<CategoriesListResponse> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...categoryKeys.list(params)]),
    queryFn: () => fetchCategories(params),
    enabled: !!tenantId && !!companyId,
    staleTime: 60000, // Consider data fresh for 1 minute
  })
}

/**
 * Hook to fetch complete category tree
 */
export function useCategoryTree(): UseQueryResult<CategoryApiResponse[]> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...categoryKeys.tree()]),
    queryFn: fetchCategoryTree,
    enabled: !!tenantId && !!companyId,
    staleTime: 300000, // Tree structure changes less frequently - 5 minutes
  })
}

/**
 * Hook to fetch a single category by ID
 */
export function useCategory(id: number | undefined): UseQueryResult<CategoryApiResponse> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...categoryKeys.detail(id!)]),
    queryFn: () => fetchCategory(id!),
    enabled: Boolean(id) && !!tenantId && !!companyId,
  })
}

/**
 * Hook to create a new category
 */
export function useCreateCategory(): UseMutationResult<
  CategoryApiResponse,
  Error,
  CreateCategoryInput
> {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createCategory,
    onSuccess: () => {
      // Invalidate all category queries to refetch with new data
      queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.all]) })
    },
  })
}

/**
 * Hook to update an existing category
 */
export function useUpdateCategory(): UseMutationResult<
  CategoryApiResponse,
  Error,
  { id: number; data: UpdateCategoryInput }
> {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }) => updateCategory(id, data),
    onSuccess: (_, variables) => {
      // Invalidate the specific category and all lists/trees
      queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.detail(variables.id)]) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.lists()]) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.trees()]) })
    },
  })
}

/**
 * Hook to delete a category
 */
export function useDeleteCategory(): UseMutationResult<void, Error, number> {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteCategory,
    onSuccess: () => {
      // Invalidate all category queries
      queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.all]) })
    },
  })
}

/**
 * Hook to reorder categories
 */
export function useReorderCategories(): UseMutationResult<
  void,
  Error,
  ReorderCategoryInput[]
> {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: reorderCategories,
    onSuccess: () => {
      // Invalidate lists and trees to refetch with new order
      queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.lists()]) })
      queryClient.invalidateQueries({ queryKey: tenantScopedKey([...categoryKeys.trees()]) })
    },
  })
}
