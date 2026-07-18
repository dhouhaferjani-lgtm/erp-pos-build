import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { expenseCategoryApi } from '../api/expenseApi'
import {
  expenseAnalyticsInvalidationPredicate,
  expenseCategoriesInvalidationPredicate,
} from '../_invalidation'
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...expenseCategoryKeys.list(filters)]),
    queryFn: () => expenseCategoryApi.list(filters),
    enabled: !!tenantId && !!companyId,
  })
}

/**
 * Hook to fetch a single expense category
 */
export function useExpenseCategory(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...expenseCategoryKeys.detail(id)]),
    queryFn: () => expenseCategoryApi.get(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

/**
 * Hook to create a new expense category
 */
export function useCreateExpenseCategory() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (data: CreateExpenseCategoryDTO) => expenseCategoryApi.create(data),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: expenseCategoriesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: expenseAnalyticsInvalidationPredicate(tenantId, companyId),
        }),
      ])
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateExpenseCategoryDTO> }) =>
      expenseCategoryApi.update(id, data),
    onSuccess: async (updatedCategory) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: expenseCategoriesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: expenseAnalyticsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: [...expenseCategoryKeys.detail(updatedCategory.id)],
        }),
      ])
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => expenseCategoryApi.delete(id),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: expenseCategoriesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: expenseAnalyticsInvalidationPredicate(tenantId, companyId),
        }),
      ])
      toast.success(t('expenses:categories.messages.deleted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}
