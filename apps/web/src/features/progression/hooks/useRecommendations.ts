import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18next from 'i18next'
import { progressionApi } from '../api/progressionApi'
import { getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  progressionKeys,
  progressionRecommendationsInvalidationPredicate,
} from './useCompanyProgression'
import type { Recommendation } from '../api/types'

/**
 * Fetch all recommendations for the company.
 */
export function useRecommendations() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery<Recommendation[]>({
    queryKey: tenantScopedKey([...progressionKeys.recommendations()]),
    queryFn: progressionApi.getRecommendations,
    enabled: !!tenantId && !!companyId,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}

/**
 * Accept a recommendation. Invalidates recommendations on success.
 */
export function useAcceptRecommendation() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (recommendationId: string) => progressionApi.acceptRecommendation(recommendationId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: progressionRecommendationsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18next.t('progression:recommendation.accepted'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}

/**
 * Dismiss a recommendation. Invalidates recommendations on success.
 */
export function useDismissRecommendation() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (recommendationId: string) => progressionApi.dismissRecommendation(recommendationId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: progressionRecommendationsInvalidationPredicate(tenantId, companyId),
      })
      toast.success(i18next.t('progression:recommendation.dismissedSuccess'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
