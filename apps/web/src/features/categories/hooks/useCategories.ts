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
 * appended at the useQuery call site via tenantScopedKey([...]). The
 * audit-tanstack-keys gate only approves a queryKey expression that is
 * either an array literal carrying an approved scope identifier OR a
 * bare-Identifier call expression `tenantScopedKey(...)`. A property-
 * access factory call like `categoryKeys.list(...)` is neither, so the
 * wrap MUST happen at the call site.
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
 * Predicate factory for invalidations. tenantScopedKey() puts tenant_id +
 * company_id at the END of the leaf key (e.g., `[cats, list, params, t,
 * c]`), so the natural prefix-match cascade that older code used
 * (`invalidateQueries({ queryKey: categoryKeys.lists() })`) no longer
 * works once the leaves are scoped: a wrapped tag like `[cats, list, t,
 * c]` is NOT a prefix of `[cats, list, params, t, c]` because position 2
 * is `t` vs `params`. Predicate-based invalidation sidesteps the
 * positional mismatch and keeps the tenant scope explicit.
 */
type CategoryInvalidationShape =
  | { readonly kind: 'all' }
  | { readonly kind: 'lists' }
  | { readonly kind: 'trees' }
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
      case 'trees':
        return k[1] === 'tree'
      case 'detail':
        return k[1] === 'detail' && k[2] === shape.id
    }
  }
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createCategory,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: categoriesInvalidationPredicate({ kind: 'all' }, tenantId, companyId),
      })
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }) => updateCategory(id, data),
    onSuccess: async (_, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate(
            { kind: 'detail', id: variables.id },
            tenantId,
            companyId,
          ),
        }),
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate({ kind: 'lists' }, tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate({ kind: 'trees' }, tenantId, companyId),
        }),
      ])
    },
  })
}

/**
 * Hook to delete a category
 */
export function useDeleteCategory(): UseMutationResult<void, Error, number> {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteCategory,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: categoriesInvalidationPredicate({ kind: 'all' }, tenantId, companyId),
      })
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: reorderCategories,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate({ kind: 'lists' }, tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: categoriesInvalidationPredicate({ kind: 'trees' }, tenantId, companyId),
        }),
      ])
    },
  })
}
