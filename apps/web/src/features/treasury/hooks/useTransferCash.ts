import { useMutation, useQueryClient } from '@tanstack/react-query'

import { api } from '@/lib/api'

export interface TransferPayload {
  from_repository_id: string
  to_repository_id: string
  amount: string
  notes?: string
  transfer_group_id: string
}

export interface TransferResponse {
  transfer_group_id: string
  journal_entry_id: string | null
  idempotent_replay: boolean
  out: { movement_id: string; balance_after: string; repository_id: string }
  in: { movement_id: string; balance_after: string; repository_id: string }
}

export function useTransferCash() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: TransferPayload): Promise<TransferResponse> => {
      const { data: response } = await api.post<{ message: string; data: TransferResponse }>(
        '/payment-repositories/transfers',
        payload,
      )

      return response.data
    },
    onSuccess: (_data, variables) => {
      const { from_repository_id: fromId, to_repository_id: toId } = variables

      // Cache filters use raw leading prefixes because tenantScopedKey appends
      // tenant/company suffixes, while TanStack matches filters positionally.
      void queryClient.invalidateQueries({ queryKey: ['payment-repositories'] })
      void queryClient.invalidateQueries({ queryKey: ['payment-repository', fromId] })
      void queryClient.invalidateQueries({ queryKey: ['payment-repository', toId] })
      void queryClient.invalidateQueries({ queryKey: ['payment-repository-transactions', fromId] })
      void queryClient.invalidateQueries({ queryKey: ['payment-repository-transactions', toId] })
      void queryClient.invalidateQueries({ queryKey: ['treasury-cash-position'] })
      void queryClient.invalidateQueries({ queryKey: ['repository-movements', fromId] })
      void queryClient.invalidateQueries({ queryKey: ['repository-movements', toId] })
    },
  })
}
