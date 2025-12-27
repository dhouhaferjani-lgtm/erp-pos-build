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

/**
 * Hook to fetch paginated categories
 */
export function useCategories(
  params?: GetCategoriesParams,
  options?: Omit<UseQueryOptions<Awaited<ReturnType<typeof getCategories>>>, 'queryKey' | 'queryFn'>
) {
  return useQuery({
    queryKey: categoryKeys.list(params),
    queryFn: () => getCategories(params),
    ...options,
  })
}

/**
 * Hook to fetch category tree
 */
export function useCategoryTree(
  options?: Omit<UseQueryOptions<CategoryTreeNode[]>, 'queryKey' | 'queryFn'>
) {
  return useQuery({
    queryKey: categoryKeys.tree(),
    queryFn: getCategoryTree,
    ...options,
  })
}

/**
 * Hook to fetch a single category
 */
export function useCategory(
  id: number,
  options?: Omit<UseQueryOptions<Category>, 'queryKey' | 'queryFn'>
) {
  return useQuery({
    queryKey: categoryKeys.detail(id),
    queryFn: () => getCategory(id),
    enabled: Boolean(id),
    ...options,
  })
}

/**
 * Hook to create a category
 */
export function useCreateCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateCategoryData) => createCategory(data),
    onSuccess: () => {
      // Invalidate all category queries
      queryClient.invalidateQueries({ queryKey: categoryKeys.all })
    },
  })
}

/**
 * Hook to update a category
 */
export function useUpdateCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateCategoryData }) => updateCategory(id, data),
    onSuccess: (_, variables) => {
      // Invalidate specific category and all lists/tree
      queryClient.invalidateQueries({ queryKey: categoryKeys.detail(variables.id) })
      queryClient.invalidateQueries({ queryKey: categoryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: categoryKeys.tree() })
    },
  })
}

/**
 * Hook to delete a category
 */
export function useDeleteCategory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => deleteCategory(id),
    onSuccess: () => {
      // Invalidate all category queries
      queryClient.invalidateQueries({ queryKey: categoryKeys.all })
    },
  })
}
