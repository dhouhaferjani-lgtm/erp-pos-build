import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { expenseCategoryApi } from '../api/expenseApi'
import type { CreateExpenseCategoryDTO, ExpenseCategoryFilters } from '../types'

/**
 * Query keys for expense category queries
 */
export const expenseCategoryKeys = {
  all: ['expense-categories'] as const,
  lists: () => [...expenseCategoryKeys.all, 'list'] as const,
  list: (filters?: ExpenseCategoryFilters) => [...expenseCategoryKeys.lists(), filters] as const,
  details: () => [...expenseCategoryKeys.all, 'detail'] as const,
  detail: (id: string) => [...expenseCategoryKeys.details(), id] as const,
}

/**
 * Hook to fetch expense categories
 */
export function useExpenseCategories(filters?: ExpenseCategoryFilters) {
  return useQuery({
    queryKey: expenseCategoryKeys.list(filters),
    queryFn: () => expenseCategoryApi.list(filters),
  })
}

/**
 * Hook to fetch a single expense category
 */
export function useExpenseCategory(id: string) {
  return useQuery({
    queryKey: expenseCategoryKeys.detail(id),
    queryFn: () => expenseCategoryApi.get(id),
    enabled: !!id,
  })
}

/**
 * Hook to create a new expense category
 */
export function useCreateExpenseCategory() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateExpenseCategoryDTO) => expenseCategoryApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: expenseCategoryKeys.lists() })
      toast.success(t('expenses:categories.messages.created'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}

/**
 * Hook to update an expense category
 */
export function useUpdateExpenseCategory() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateExpenseCategoryDTO> }) =>
      expenseCategoryApi.update(id, data),
    onSuccess: (updatedCategory) => {
      queryClient.invalidateQueries({ queryKey: expenseCategoryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: expenseCategoryKeys.detail(updatedCategory.id) })
      toast.success(t('expenses:categories.messages.updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}

/**
 * Hook to delete an expense category
 */
export function useDeleteExpenseCategory() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => expenseCategoryApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: expenseCategoryKeys.lists() })
      toast.success(t('expenses:categories.messages.deleted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}
