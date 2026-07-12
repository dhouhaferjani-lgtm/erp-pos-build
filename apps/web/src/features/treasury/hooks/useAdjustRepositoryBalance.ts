import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api, getErrorMessage } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'

export type RepositoryAdjustmentDirection = 'in' | 'out'
export type RepositoryAdjustmentReasonCode =
  | 'count_variance'
  | 'correction'
  | 'theft_loss'
  | 'other'

export interface AdjustRepositoryBalanceRequest {
  direction: RepositoryAdjustmentDirection
  amount: string
  reason_code: RepositoryAdjustmentReasonCode
  reason_text: string
}

export interface AdjustRepositoryBalanceResult {
  movement_id: string
  balance_after: string
  ordinal: number
  idempotent_replay: boolean
}

interface AdjustmentResponse {
  message: string
  data: AdjustRepositoryBalanceResult
}

function flatErrorMessage(error: unknown): string | null {
  if (typeof error !== 'object' || error === null || !('response' in error)) return null
  const response = error.response
  if (typeof response !== 'object' || response === null || !('data' in response)) return null
  const data = response.data
  if (typeof data !== 'object' || data === null || !('error' in data)) return null
  return typeof data.error === 'string' ? data.error : null
}

export function useAdjustRepositoryBalance(repositoryId: string) {
  const { t } = useTranslation(['treasury'])
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (request: AdjustRepositoryBalanceRequest) => {
      const response = await api.post<AdjustmentResponse>(
        `/payment-repositories/${repositoryId}/adjustments`,
        request,
      )
      return response.data.data
    },
    onSuccess: async () => {
      const movementScope = tenantScopedKey(['repository-movements', repositoryId])
      const tenantId = movementScope[movementScope.length - 2]
      const companyId = movementScope[movementScope.length - 1]

      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['payment-repository', repositoryId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['payment-repository-transactions', repositoryId],
        }),
        queryClient.invalidateQueries({
          predicate: (query) =>
            query.queryKey[0] === 'repository-movements'
            && query.queryKey[1] === repositoryId
            && query.queryKey[query.queryKey.length - 2] === tenantId
            && query.queryKey[query.queryKey.length - 1] === companyId,
        }),
        queryClient.invalidateQueries({
          queryKey: ['treasury-cash-position'],
        }),
      ])
    },
    onError: (error: unknown) => {
      const message = flatErrorMessage(error) ?? getErrorMessage(error)
      toast.error(message || t('treasury:repositories.adjustBalance.error'))
    },
  })
}
