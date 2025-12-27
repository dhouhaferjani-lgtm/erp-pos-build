import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { expenseApi } from '../api/expenseApi'
import type { CreateExpenseDTO, ExpenseFilters } from '../types'

/**
 * Query keys for expense-related queries
 */
export const expenseKeys = {
  all: ['expenses'] as const,
  lists: () => [...expenseKeys.all, 'list'] as const,
  list: (filters?: ExpenseFilters) => [...expenseKeys.lists(), filters] as const,
  details: () => [...expenseKeys.all, 'detail'] as const,
  detail: (id: string) => [...expenseKeys.details(), id] as const,
}

/**
 * Hook to fetch a list of expenses
 */
export function useExpenses(filters?: ExpenseFilters) {
  return useQuery({
    queryKey: expenseKeys.list(filters),
    queryFn: () => expenseApi.list(filters),
  })
}

/**
 * Hook to fetch a single expense
 */
export function useExpense(id: string) {
  return useQuery({
    queryKey: expenseKeys.detail(id),
    queryFn: () => expenseApi.get(id),
    enabled: !!id,
  })
}

/**
 * Hook to create a new expense
 */
export function useCreateExpense() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateExpenseDTO) => expenseApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: expenseKeys.lists() })
      toast.success(t('expenses:messages.created'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}

/**
 * Hook to update an existing expense
 */
export function useUpdateExpense() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateExpenseDTO> }) =>
      expenseApi.update(id, data),
    onSuccess: (updatedExpense) => {
      queryClient.invalidateQueries({ queryKey: expenseKeys.lists() })
      queryClient.invalidateQueries({ queryKey: expenseKeys.detail(updatedExpense.id) })
      toast.success(t('expenses:messages.updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}

/**
 * Hook to delete an expense
 */
export function useDeleteExpense() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => expenseApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: expenseKeys.lists() })
      toast.success(t('expenses:messages.deleted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}

/**
 * Hook to post an expense (finalize)
 */
export function usePostExpense() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => expenseApi.post(id),
    onSuccess: (postedExpense) => {
      queryClient.invalidateQueries({ queryKey: expenseKeys.lists() })
      queryClient.invalidateQueries({ queryKey: expenseKeys.detail(postedExpense.id) })
      toast.success(t('expenses:messages.posted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}
