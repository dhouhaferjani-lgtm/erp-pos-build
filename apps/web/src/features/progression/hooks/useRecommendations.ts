import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import i18next from 'i18next'
import { progressionApi } from '../api/progressionApi'
import { getErrorMessage } from '@/lib/api'
import { progressionKeys } from './useCompanyProgression'
import type { Recommendation } from '../api/types'

/**
 * Fetch all recommendations for the company.
 */
export function useRecommendations() {
  return useQuery<Recommendation[]>({
    queryKey: progressionKeys.recommendations(),
    queryFn: progressionApi.getRecommendations,
    staleTime: 5 * 60 * 1000,
    retry: 1,
  })
}

/**
 * Accept a recommendation. Invalidates recommendations on success.
 */
export function useAcceptRecommendation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (recommendationId: string) => progressionApi.acceptRecommendation(recommendationId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: progressionKeys.recommendations() })
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
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (recommendationId: string) => progressionApi.dismissRecommendation(recommendationId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: progressionKeys.recommendations() })
      toast.success(i18next.t('progression:recommendation.dismissedSuccess'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })
}
