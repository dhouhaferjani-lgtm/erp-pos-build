import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { incomeApi } from '../api/incomeApi'
import { incomeInvalidationPredicate } from '../_invalidation'
import type { CreateIncomeDTO, IncomeFilters } from '../types'

/**
 * Query keys for income-related queries.
 */
export const incomeKeys = {
  all: ['income'] as const,
  lists: () => [...incomeKeys.all, 'list'] as const,
  list: (filters?: IncomeFilters) => [...incomeKeys.lists(), filters] as const,
  details: () => [...incomeKeys.all, 'detail'] as const,
  detail: (id: string) => [...incomeKeys.details(), id] as const,
}

export function useIncomeList(filters?: IncomeFilters) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...incomeKeys.list(filters)]),
    queryFn: () => incomeApi.list(filters),
    enabled: !!tenantId && !!companyId,
  })
}

export function useIncome(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...incomeKeys.detail(id)]),
    queryFn: () => incomeApi.get(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useCreateIncome() {
  const { t } = useTranslation(['income', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (data: CreateIncomeDTO) => incomeApi.create(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: incomeInvalidationPredicate(tenantId, companyId),
      })
      toast.success(t('income:messages.created'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}

export function useUpdateIncome() {
  const { t } = useTranslation(['income', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateIncomeDTO> }) =>
      incomeApi.update(id, data),
    onSuccess: async (updated) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: incomeInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: [...incomeKeys.detail(updated.id)],
        }),
      ])
      toast.success(t('income:messages.updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}

export function usePostIncome() {
  const { t } = useTranslation(['income', 'common'])
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)

  return useMutation({
    mutationFn: (id: string) => incomeApi.post(id),
    onSuccess: async (posted) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: incomeInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          queryKey: [...incomeKeys.detail(posted.id)],
        }),
      ])
      toast.success(t('income:messages.posted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('common:errors.unexpected'))
    },
  })
}
