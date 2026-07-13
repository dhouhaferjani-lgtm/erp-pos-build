import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getErrorMessage } from '@/lib/api'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { expenseApi } from '../api/expenseApi'
import { expensesInvalidationPredicate } from '../_invalidation'
import type {
  CreateExpenseDTO,
  ExpenseAnalyticsFilters,
  ExpenseFilters,
  PayExpenseRequest,
} from '../types'

function flatErrorMessage(error: unknown): string | null {
  if (typeof error !== 'object' || error === null || !('response' in error)) return null
  const response = error.response
  if (typeof response !== 'object' || response === null || !('data' in response)) return null
  const data = response.data
  if (typeof data !== 'object' || data === null || !('error' in data)) return null
  return typeof data.error === 'string' ? data.error : null
}

/**
 * Query keys for expense-related queries
 */
export const expenseKeys = {
  all: ['expenses'] as const,
  lists: () => [...expenseKeys.all, 'list'] as const,
  list: (filters?: ExpenseFilters) => [...expenseKeys.lists(), filters] as const,
  analytics: (filters?: ExpenseAnalyticsFilters) =>
    [...expenseKeys.all, 'analytics', filters] as const,
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

export function useExpenseAnalytics(filters?: ExpenseAnalyticsFilters) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...expenseKeys.analytics(filters)]),
    queryFn: () => expenseApi.getAnalytics(filters),
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

/**
 * Hook to settle a posted expense for its full total.
 */
export function usePayExpense() {
  const { t } = useTranslation(['expenses'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: PayExpenseRequest }) =>
      expenseApi.pay(id, data),
    onSuccess: async (paidExpense, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: expensesInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: [...expenseKeys.detail(paidExpense.id)],
        }),
        queryClient.invalidateQueries({
          queryKey: ['payment-repository', variables.data.payment_repository_id],
        }),
        queryClient.invalidateQueries({
          queryKey: ['treasury-cash-position'],
        }),
      ])
      toast.success(t('expenses:pay.success'))
    },
    onError: (error: unknown) => {
      const message = flatErrorMessage(error) ?? getErrorMessage(error)
      toast.error(message || t('expenses:pay.error'))
    },
  })
}
