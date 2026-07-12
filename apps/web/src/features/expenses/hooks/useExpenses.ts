import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { expenseApi } from '../api/expenseApi'
import { expensesInvalidationPredicate } from '../_invalidation'
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
  linkableInvoices: () => [...expenseKeys.all, 'linkable-invoices'] as const,
  linkableOperations: (invoiceId: string) =>
    [...expenseKeys.all, 'linkable-operations', invoiceId] as const,
}

/**
 * Hook to fetch a list of expenses
 */
export function useExpenses(filters?: ExpenseFilters) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...expenseKeys.list(filters)]),
    queryFn: () => expenseApi.list(filters),
    enabled: !!tenantId && !!companyId,
  })
}

/**
 * Hook to fetch a single expense
 */
export function useExpense(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...expenseKeys.detail(id)]),
    queryFn: () => expenseApi.get(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useLinkableExpenseInvoices(enabled = true) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...expenseKeys.linkableInvoices()]),
    queryFn: () => expenseApi.listLinkableInvoices(),
    enabled: enabled && !!tenantId && !!companyId,
  })
}

export function useLinkableExpenseOperations(invoiceId: string | undefined) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...expenseKeys.linkableOperations(invoiceId ?? '')]),
    queryFn: () => expenseApi.resolveLinkableOperations(invoiceId ?? ''),
    enabled: !!invoiceId && !!tenantId && !!companyId,
  })
}

/**
 * Hook to create a new expense
 */
export function useCreateExpense() {
  const { t } = useTranslation(['expenses', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (data: CreateExpenseDTO) => expenseApi.create(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: expensesInvalidationPredicate(tenantId, companyId),
      })
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateExpenseDTO> }) =>
      expenseApi.update(id, data),
    onSuccess: async (updatedExpense) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: expensesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: [...expenseKeys.detail(updatedExpense.id)],
        }),
      ])
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => expenseApi.delete(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: expensesInvalidationPredicate(tenantId, companyId),
      })
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
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => expenseApi.post(id),
    onSuccess: async (postedExpense) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: expensesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: [...expenseKeys.detail(postedExpense.id)],
        }),
      ])
      toast.success(t('expenses:messages.posted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}
