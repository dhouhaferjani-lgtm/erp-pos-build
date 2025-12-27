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

/**
 * Query key factory for categories
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
  return useQuery({
    queryKey: categoryKeys.list(params),
    queryFn: () => fetchCategories(params),
    staleTime: 60000, // Consider data fresh for 1 minute
  })
}

/**
 * Hook to fetch complete category tree
 */
export function useCategoryTree(): UseQueryResult<CategoryApiResponse[]> {
  return useQuery({
    queryKey: categoryKeys.tree(),
    queryFn: fetchCategoryTree,
    staleTime: 300000, // Tree structure changes less frequently - 5 minutes
  })
}

/**
 * Hook to fetch a single category by ID
 */
export function useCategory(id: number | undefined): UseQueryResult<CategoryApiResponse> {
  return useQuery({
    queryKey: categoryKeys.detail(id!),
    queryFn: () => fetchCategory(id!),
    enabled: Boolean(id),
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
      queryClient.invalidateQueries({ queryKey: categoryKeys.all })
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
      queryClient.invalidateQueries({ queryKey: categoryKeys.detail(variables.id) })
      queryClient.invalidateQueries({ queryKey: categoryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: categoryKeys.trees() })
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
      queryClient.invalidateQueries({ queryKey: categoryKeys.all })
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
      queryClient.invalidateQueries({ queryKey: categoryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: categoryKeys.trees() })
    },
  })
}
